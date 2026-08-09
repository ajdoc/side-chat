import { describe, expect, it } from 'vitest'

import { decryptFile, encryptFile, generateFileKey } from './attachment'
import {
  contextBytes,
  fileContextBytes,
  packEnvelope,
  sealedFilesFrom,
  sealedFrom,
  unpackEnvelope,
  type SealedFile,
} from './envelope'
import { fromBase64, fromUtf8, open, seal, toBase64, utf8 } from './primitives'
import { createChain, decryptWithKey, messageKeyAt, type SenderChain } from './senderKey'

/*
 * An attachment's whole journey, mirroring the composables line for line.
 *
 * The existing attachment spec proves the pieces work. This one exists because the pieces
 * working is not the same as the two *call sites* agreeing — `encryptOutgoing` seals the file
 * block and `decryptFileMeta` opens it, and every argument they each pass has to line up:
 * the ratchet index, the authenticated context, and which chain the key comes from.
 *
 * The earlier version of this file's helper quietly modelled a send that never happened —
 * it advanced the chain the way the app *used* to, so it agreed with itself and proved
 * nothing about the app. These helpers are copied from the composables deliberately, and a
 * change to either must be made here too.
 */

/** Exactly what useMessageCrypto.encryptOutgoing does. */
async function encryptOutgoing(chain: SenderChain, epoch: number, body: string, files: SealedFile[]) {
  const nextIndex = chain.index + 1
  const header = { v: 1, e: epoch, d: chain.deviceId, i: nextIndex }

  const step = await messageKeyAt({ ...chain, index: 0 }, nextIndex)

  const sealedBody = await seal(step.messageKey, utf8(body), contextBytes(header))
  const sealedFiles = files.length
    ? await seal(step.messageKey, utf8(JSON.stringify(files)), fileContextBytes(header))
    : undefined

  return {
    body: packEnvelope({ e: epoch, d: chain.deviceId, i: nextIndex }, sealedBody, sealedFiles),
    chain: { ...chain, index: nextIndex },
  }
}

/**
 * A message as the timeline holds it: `body` decrypted in place, envelope kept alongside.
 *
 * The shape matters as much as the crypto here — see the "reads its files after the body has
 * been decrypted" test for the bug that lived in exactly this gap.
 */
interface StoredMessage {
  body: string
  cipher_body?: string
}

/** Exactly what useMessageCrypto.decryptIncoming does to a message. */
async function decryptIncoming(storedChain: SenderChain, wire: string): Promise<StoredMessage> {
  const envelope = unpackEnvelope(wire)!
  const step = await messageKeyAt({ ...storedChain, index: 0 }, envelope.i)
  const plaintext = await decryptWithKey(step.messageKey, sealedFrom(envelope), contextBytes(envelope))

  return { body: fromUtf8(plaintext), cipher_body: wire }
}

/** Exactly what useMessageCrypto.decryptFileMeta does. */
async function decryptFileMeta(storedChain: SenderChain, message: StoredMessage): Promise<SealedFile[]> {
  const envelope = unpackEnvelope(message.cipher_body ?? message.body)
  if (!envelope?.f) return []

  try {
    const step = await messageKeyAt({ ...storedChain, index: 0 }, envelope.i)
    const plaintext = await open(step.messageKey, sealedFilesFrom(envelope)!, fileContextBytes(envelope))
    const parsed = JSON.parse(fromUtf8(plaintext))

    return Array.isArray(parsed) ? parsed : []
  } catch {
    return []
  }
}

/** Seal a batch of files, as useMessages.sealFiles does. */
async function sealFiles(files: File[]) {
  const sealed: File[] = []
  const meta: SealedFile[] = []

  for (const file of files) {
    const { key, raw } = await generateFileKey()
    sealed.push(await encryptFile(file, key))
    meta.push({ n: file.name, m: file.type || 'application/octet-stream', k: toBase64(raw) })
  }

  return { files: sealed, meta }
}

function fileOf(name: string, type: string, contents: string): File {
  return new File([utf8(contents) as BlobPart], name, { type })
}

describe('attachment journey', () => {
  it('recovers the real name, type and bytes of a file the sender uploaded', async () => {
    const chain = createChain(1, 'ana-laptop')
    const outgoing = await sealFiles([fileOf('budget.xlsx', 'application/vnd.ms-excel', 'the numbers')])

    const sent = await encryptOutgoing(chain, 1, 'have a look', outgoing.meta)

    // The reader holds the chain as the store has it — root key, advanced counter.
    const meta = await decryptFileMeta(sent.chain, { body: sent.body })

    expect(meta).toHaveLength(1)
    expect(meta[0]!.n).toBe('budget.xlsx')
    expect(meta[0]!.m).toBe('application/vnd.ms-excel')

    const opened = await decryptFile(
      await outgoing.files[0]!.arrayBuffer(),
      fromBase64(meta[0]!.k),
      meta[0]!.m,
    )
    expect(await opened.text()).toBe('the numbers')
  })

  it('still recovers them on the sender’s fourth message, not just the first', async () => {
    /*
     * The gap the old helper hid. A first message works under almost any indexing scheme,
     * because index 1 is where every off-by-one agrees. Everything after it is where a
     * mismatch between what the sender sealed and what the reader winds to shows up — and the
     * symptom is an attachment stuck on its placeholder name, which reads as a display bug.
     */
    let chain = createChain(1, 'ana-laptop')

    for (const body of ['one', 'two', 'three']) {
      chain = (await encryptOutgoing(chain, 1, body, [])).chain
    }

    const outgoing = await sealFiles([fileOf('late.pdf', 'application/pdf', 'arrived fourth')])
    const sent = await encryptOutgoing(chain, 1, 'and the file', outgoing.meta)

    const meta = await decryptFileMeta(sent.chain, { body: sent.body })

    expect(meta[0]?.n).toBe('late.pdf')
    expect(
      await (await decryptFile(await outgoing.files[0]!.arrayBuffer(), fromBase64(meta[0]!.k), meta[0]!.m)).text(),
    ).toBe('arrived fourth')
  })

  it('recovers a file sent with no message text at all', async () => {
    // Dragging a file in and pressing send without typing. The body is empty, so an envelope
    // is built purely to carry the file keys — an early version returned the body untouched
    // when it was blank, which dropped the keys on the floor.
    const chain = createChain(1, 'ana-laptop')
    const outgoing = await sealFiles([fileOf('photo.png', 'image/png', 'pixels')])

    const sent = await encryptOutgoing(chain, 1, '', outgoing.meta)

    expect((await decryptFileMeta(sent.chain, { body: sent.body }))[0]?.n).toBe('photo.png')
  })

  it('keeps several files on one message straight', async () => {
    const chain = createChain(1, 'ana-laptop')
    const originals = [
      fileOf('a.txt', 'text/plain', 'first file'),
      fileOf('b.txt', 'text/plain', 'second file'),
      fileOf('c.txt', 'text/plain', 'third file'),
    ]

    const outgoing = await sealFiles(originals)
    const sent = await encryptOutgoing(chain, 1, 'three of them', outgoing.meta)

    const meta = await decryptFileMeta(sent.chain, { body: sent.body })

    for (const [index, original] of originals.entries()) {
      const opened = await decryptFile(
        await outgoing.files[index]!.arrayBuffer(),
        fromBase64(meta[index]!.k),
        meta[index]!.m,
      )

      expect(meta[index]!.n).toBe(original.name)
      expect(await opened.text()).toBe(await original.text())
    }
  })

  it('reads its files after the body has been decrypted, which is the only order that happens', async () => {
    /*
     * The bug that actually shipped, and the reason every test above passed while nobody
     * could open a single attachment.
     *
     * `decryptIncoming` swaps `body` for the readable text — and the envelope it overwrites is
     * where the file keys live. Every test here handed `decryptFileMeta` the raw wire string,
     * which is a state the timeline never contains: by the time anything asks about
     * attachments, the message has already been through decryption. Parsing plaintext as an
     * envelope yields null, which the code reported as "this message has no attachments".
     *
     * Deterministic, total, and identical for every message with a file on it — which is
     * exactly why it looked like a key-distribution or storage problem instead of one line
     * of overwriting.
     */
    const chain = createChain(1, 'ana-laptop')
    const outgoing = await sealFiles([fileOf('report.pdf', 'application/pdf', 'the contents')])
    const sent = await encryptOutgoing(chain, 1, 'the message text', outgoing.meta)

    // Exactly the sequence the timeline runs: decrypt the message, *then* look at its files.
    const message = await decryptIncoming(sent.chain, sent.body)
    expect(message.body).toBe('the message text')

    const meta = await decryptFileMeta(sent.chain, message)

    expect(meta).toHaveLength(1)
    expect(meta[0]!.n).toBe('report.pdf')

    // And the bytes really open with the key that survived.
    const opened = await decryptFile(
      await outgoing.files[0]!.arrayBuffer(),
      fromBase64(meta[0]!.k),
      meta[0]!.m,
    )
    expect(await opened.text()).toBe('the contents')
  })

  it('finds nothing if the envelope was thrown away with the plaintext', async () => {
    // The negative half, pinned so the fix cannot be quietly undone: a decrypted message that
    // did *not* keep its envelope has no recoverable attachments at all.
    const chain = createChain(1, 'ana-laptop')
    const outgoing = await sealFiles([fileOf('report.pdf', 'application/pdf', 'the contents')])
    const sent = await encryptOutgoing(chain, 1, 'the message text', outgoing.meta)

    const withoutEnvelope = { body: (await decryptIncoming(sent.chain, sent.body)).body }

    expect(await decryptFileMeta(sent.chain, withoutEnvelope)).toEqual([])
  })

  it('returns nothing when the reader is on a chain that is not the root', async () => {
    /*
     * The failure the user actually saw, reproduced deliberately.
     *
     * A chain written by the build that persisted the ratcheted key is a descendant of the
     * root, so winding it from zero produces the wrong message key. The file block fails to
     * open, the metadata comes back empty, and the attachment keeps the server's placeholder
     * name — "Encrypted file" — with no key to fetch its bytes. Nothing throws.
     */
    const root = createChain(1, 'ana-laptop')
    const outgoing = await sealFiles([fileOf('doc.pdf', 'application/pdf', 'contents')])
    const sent = await encryptOutgoing(root, 1, 'here', outgoing.meta)

    const notTheRoot = { ...sent.chain, chainKey: (await messageKeyAt({ ...root, index: 0 }, 1)).chain.chainKey }

    expect(await decryptFileMeta(notTheRoot, { body: sent.body })).toEqual([])
    expect(await decryptFileMeta(sent.chain, { body: sent.body })).toHaveLength(1)
  })
})
