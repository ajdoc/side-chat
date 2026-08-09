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
import { advance, createChain, messageKeyAt } from './senderKey'

/*
 * Encrypted attachments: the bytes, and the keys that travel with them.
 *
 * Two halves that fail in different ways. The bytes are one AES-GCM blob, so the tests there
 * are about integrity — a file that has been altered must not open at all, rather than opening
 * as something slightly wrong. The keys ride inside the message envelope, so the tests there
 * are about *binding*: a file block must belong to exactly one message and must not be
 * transplantable onto another.
 */

/** A file, the way the browser hands one to the composer. */
function fileOf(name: string, type: string, contents: string): File {
  return new File([utf8(contents) as BlobPart], name, { type })
}

describe('attachment bytes', () => {
  it('round-trips a file through encryption', async () => {
    const { key, raw } = await generateFileKey()
    const original = fileOf('notes.txt', 'text/plain', 'the actual contents')

    const sealed = await encryptFile(original, key)
    const opened = await decryptFile(await sealed.arrayBuffer(), raw, 'text/plain')

    expect(await opened.text()).toBe('the actual contents')
    expect(opened.type).toBe('text/plain')
  })

  it('tells the server nothing about the file it is storing', async () => {
    // The name is frequently the most revealing part of a document. What goes up is a
    // neutrally-named blob of an opaque type; the real values travel sealed.
    const { key } = await generateFileKey()

    const sealed = await encryptFile(fileOf('Q3 redundancies.xlsx', 'application/vnd.ms-excel', 'x'), key)

    expect(sealed.name).toBe('encrypted')
    expect(sealed.type).toBe('application/octet-stream')
  })

  it('refuses a file whose bytes were altered in storage', async () => {
    // AES-GCM authenticates the whole file, so a server that tampered with an attachment
    // cannot have it render as anything at all.
    const { key, raw } = await generateFileKey()
    const sealed = await encryptFile(fileOf('photo.png', 'image/png', 'pretend this is a png'), key)

    const bytes = new Uint8Array(await sealed.arrayBuffer())
    bytes[bytes.length - 5] = bytes[bytes.length - 5]! ^ 0xff

    await expect(decryptFile(bytes.buffer as ArrayBuffer, raw, 'image/png')).rejects.toThrow()
  })

  it('refuses a truncated file rather than returning the part that decoded', async () => {
    // Half a decrypted file is not a file. This is the case a chunked design would have to
    // defend against explicitly; sealing in one blob gets it for free.
    const { key, raw } = await generateFileKey()
    const sealed = await encryptFile(fileOf('clip.mp4', 'video/mp4', 'a'.repeat(500)), key)

    const bytes = new Uint8Array(await sealed.arrayBuffer()).slice(0, 200)

    await expect(decryptFile(bytes.buffer as ArrayBuffer, raw, 'video/mp4')).rejects.toThrow()
  })

  it('refuses bytes too short to contain a nonce', async () => {
    const { raw } = await generateFileKey()

    await expect(decryptFile(new Uint8Array(4).buffer, raw, 'text/plain')).rejects.toThrow(/too short/)
  })

  it('will not open one file with another file’s key', async () => {
    const mine = await generateFileKey()
    const theirs = await generateFileKey()

    const sealed = await encryptFile(fileOf('a.txt', 'text/plain', 'mine'), mine.key)

    await expect(decryptFile(await sealed.arrayBuffer(), theirs.raw, 'text/plain')).rejects.toThrow()
  })

  it('gives every file its own key', async () => {
    const first = await generateFileKey()
    const second = await generateFileKey()

    expect(toBase64(first.raw)).not.toBe(toBase64(second.raw))
  })
})

describe('file keys in the envelope', () => {
  /** Seal a body and a file block under one ratchet step, the way useMessageCrypto does. */
  async function sendWithFiles(epoch: number, deviceId: string, body: string, files: SealedFile[]) {
    const chain = createChain(epoch, deviceId)
    const header = { v: 1, e: epoch, d: deviceId, i: chain.index + 1 }
    const step = await advance(chain)

    const sealedBody = await seal(step.messageKey, utf8(body), contextBytes(header))
    const sealedFiles = await seal(step.messageKey, utf8(JSON.stringify(files)), fileContextBytes(header))

    return {
      chain,
      wire: packEnvelope({ e: epoch, d: deviceId, i: step.chain.index }, sealedBody, sealedFiles),
    }
  }

  it('carries the names, types and keys to a reader of the message', async () => {
    const meta: SealedFile[] = [{ n: 'holiday.png', m: 'image/png', k: toBase64(new Uint8Array(32)) }]
    const sent = await sendWithFiles(1, 'ana-laptop', 'here it is', meta)

    const envelope = unpackEnvelope(sent.wire)!
    const step = await messageKeyAt({ ...sent.chain, index: 0 }, envelope.i)

    const files = JSON.parse(
      fromUtf8(await open(step.messageKey, sealedFilesFrom(envelope)!, fileContextBytes(envelope))),
    )

    expect(files).toEqual(meta)
  })

  it('cannot have its file block read as the message body', async () => {
    // Both blobs are sealed under the same key. Without distinct authenticated contexts, an
    // attacker could swap them and have the file metadata render as somebody's words.
    const sent = await sendWithFiles(1, 'ana-laptop', 'the real body', [
      { n: 'x.txt', m: 'text/plain', k: toBase64(new Uint8Array(32)) },
    ])

    const envelope = unpackEnvelope(sent.wire)!
    const step = await messageKeyAt({ ...sent.chain, index: 0 }, envelope.i)

    // The file block, opened with the body's context — must fail.
    await expect(
      open(step.messageKey, sealedFilesFrom(envelope)!, contextBytes(envelope)),
    ).rejects.toThrow()

    // ...and the body opened with the file context, likewise.
    await expect(
      open(step.messageKey, sealedFrom(envelope), fileContextBytes(envelope)),
    ).rejects.toThrow()
  })

  it('pairs each key with its own file by position, and nothing else', async () => {
    /*
     * The ordering rule, and why it earns a test of its own.
     *
     * There is no id linking a key to a file — the keys are sealed before any attachment row
     * exists, and the server must never learn which is which. Position is the entire binding.
     * The server attaches direct uploads first and chunk-staged ones after, so the client has
     * to build its metadata in that same order.
     *
     * Getting it wrong does not throw. Every file simply decrypts with its neighbour's key,
     * fails authentication, and the whole message's attachments turn into locked cards — with
     * plausible names attached, which makes it look like a display bug rather than a key one.
     */
    const files = [
      { name: 'inline.txt', mime: 'text/plain', contents: 'the small one, sent inline' },
      { name: 'huge.mp4', mime: 'video/mp4', contents: 'the big one, staged in chunks' },
    ]

    const sealedFiles: File[] = []
    const meta: SealedFile[] = []

    for (const spec of files) {
      const { key, raw } = await generateFileKey()
      sealedFiles.push(await encryptFile(fileOf(spec.name, spec.mime, spec.contents), key))
      meta.push({ n: spec.name, m: spec.mime, k: toBase64(raw) })
    }

    // Each position opens its own file…
    for (const [index, spec] of files.entries()) {
      const opened = await decryptFile(
        await sealedFiles[index]!.arrayBuffer(),
        fromBase64(meta[index]!.k),
        meta[index]!.m,
      )

      expect(await opened.text()).toBe(spec.contents)
      expect(meta[index]!.n).toBe(spec.name)
    }

    // …and its neighbour's key opens nothing, which is what a misordered list would produce.
    await expect(
      decryptFile(await sealedFiles[0]!.arrayBuffer(), fromBase64(meta[1]!.k), meta[1]!.m),
    ).rejects.toThrow()
  })

  it('omits the block entirely when a message has no attachments', async () => {
    // Nearly every message. It should cost nothing.
    const chain = createChain(1, 'ana-laptop')
    const header = { v: 1, e: 1, d: 'ana-laptop', i: 1 }
    const step = await advance(chain)
    const sealedBody = await seal(step.messageKey, utf8('just words'), contextBytes(header))

    const envelope = unpackEnvelope(packEnvelope({ e: 1, d: 'ana-laptop', i: 1 }, sealedBody))!

    expect(envelope.f).toBeUndefined()
    expect(sealedFilesFrom(envelope)).toBeNull()
  })

  it('rejects an envelope whose file block has been mangled', async () => {
    // A broken `f` is not "no attachments". Treating it as such would render the message with
    // its files silently missing, which is worse than saying the message can't be read.
    const sent = await sendWithFiles(1, 'ana-laptop', 'body', [
      { n: 'x.txt', m: 'text/plain', k: toBase64(new Uint8Array(32)) },
    ])

    const envelope = unpackEnvelope(sent.wire)!
    const mangled = { ...envelope, f: { n: 12345, c: 'still base64ish' } }

    expect(unpackEnvelope(toBase64(utf8(JSON.stringify(mangled))))).toBeNull()
  })
})
