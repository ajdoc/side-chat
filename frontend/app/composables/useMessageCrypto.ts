import type { Message } from '~/types'
import {
  contextBytes,
  fileContextBytes,
  packEnvelope,
  sealedFilesFrom,
  sealedFrom,
  unpackEnvelope,
  type SealedFile,
} from '~/lib/crypto/envelope'
import { fromUtf8, open, seal, utf8 } from '~/lib/crypto/primitives'
import { decryptWithKey, messageKeyAt } from '~/lib/crypto/senderKey'

/**
 * Turning messages into ciphertext on the way out, and back on the way in.
 *
 * The narrow waist of the whole feature: exactly two functions, one per direction, and every
 * path that sends or receives a message goes through them. Keeping it to two is what stops
 * the striped-timeline problem leaking everywhere — nothing else in the app has to know that
 * a body might be an envelope.
 *
 * The rule on the read side is that **a message never fails loudly**. A timeline routinely
 * holds plaintext written before encryption was switched on, ciphertext from an era this
 * device has no key for, and messages from a sender whose bundle we couldn't verify. All of
 * that is ordinary, none of it is exceptional, and each row renders as "can't read this"
 * rather than throwing and taking the page with it.
 *
 * The rule on the write side is the opposite. If a message cannot be encrypted, the send is
 * **refused** — never silently downgraded to plaintext. Somebody typing into a channel with a
 * padlock on it has been promised something, and quietly posting in the clear would break that
 * promise at exactly the moment it mattered. An error they can see is the only honest option.
 */
export function useMessageCrypto() {
  const encryption = useEncryption()

  /**
   * Encrypt an outgoing body for a channel, or hand it back untouched.
   *
   * `epoch` comes from the channel as the composer last saw it. Passing it in rather than
   * re-reading it here is deliberate: it is the same value the send is about to be stamped
   * with server-side, and deriving it twice invites the two disagreeing.
   */
  async function encryptOutgoing(
    channelId: number,
    epoch: number,
    body: string | null | undefined,
    files: SealedFile[] = [],
  ): Promise<string | null | undefined> {
    // A message with attachments and no text is ordinary, and still needs an envelope — the
    // file keys have nowhere else to travel. Only a message with neither is left alone.
    if ((body == null || body === '') && files.length === 0) return body

    const chain = await encryption.chainFor(channelId, epoch)

    /*
     * Derive from the *root*, at the next index — never by advancing the stored key.
     *
     * A stored chain always holds the key the chain started with, and `index` counts how many
     * messages this device has sent on it. That is what lets a message be read more than
     * once: reading winds a copy forward from the root to whatever index the envelope names,
     * and the root has to still be there for that to work.
     *
     * Storing the advanced key instead — the obvious thing, and what this did originally —
     * makes sending work perfectly and quietly breaks reading *your own* messages, because
     * the value the reader winds from is no longer the value it was wound from before. Other
     * people's messages keep working, since their chains are stored at index 0 and never
     * advance, which makes it look like a bug about ownership rather than about state.
     */
    const nextIndex = chain.index + 1
    const header = { v: 1, e: epoch, d: chain.deviceId, i: nextIndex }

    // One ratchet step, two blobs. The body and the file metadata are sealed under the same
    // message key with different authenticated contexts, so they cannot be swapped for one
    // another — see fileContextBytes. Taking a second step for the files would burn a key
    // per attachment and put the two halves of one message in different places in the chain.
    const step = await messageKeyAt({ ...chain, index: 0 }, nextIndex)

    const sealedBody = await seal(step.messageKey, utf8(body ?? ''), contextBytes(header))
    const sealedFiles = files.length
      ? await seal(step.messageKey, utf8(JSON.stringify(files)), fileContextBytes(header))
      : undefined

    // Record the new position *before* the message goes out. The other order loses the count
    // if the request fails midway, and an index that rewinds hands the same message key to
    // two different messages — the one thing AES-GCM cannot survive. The key itself is
    // unchanged: only the counter moves.
    await encryption.saveChain(channelId, { ...chain, index: nextIndex })

    return packEnvelope({ e: epoch, d: chain.deviceId, i: nextIndex }, sealedBody, sealedFiles)
  }

  /**
   * The sealed file metadata on a message — names, MIME types and keys.
   *
   * Separate from {@link decryptIncoming} and called only when somebody actually looks at an
   * attachment. Scrolling past a channel full of photographs shouldn't pay to unwrap metadata
   * for files nobody opened.
   */
  async function decryptFileMeta(message: Message): Promise<SealedFile[]> {
    if (!message.encrypted) return []

    /*
     * `cipher_body` first, because `body` may already have been decrypted in place.
     *
     * A message reaches this function after decryptIncoming has run, and that swaps the
     * envelope for the readable text — so reading `body` here would parse plaintext as an
     * envelope and conclude, wrongly, that the message has no attachments.
     */
    const envelope = unpackEnvelope(message.cipher_body ?? message.body)

    if (!envelope) return why(message, 'no-envelope')
    if (!envelope.f) return why(message, 'no-file-block')

    const chain = await encryption.chainForSender(message.channel_id, envelope.e, envelope.d)
    if (!chain) return why(message, `no-chain (epoch ${envelope.e}, sender ${envelope.d.slice(0, 8)})`)

    try {
      const step = await messageKeyAt({ ...chain, index: 0 }, envelope.i)
      const sealedFiles = sealedFilesFrom(envelope)!
      const plaintext = await open(step.messageKey, sealedFiles, fileContextBytes(envelope))

      const parsed = JSON.parse(fromUtf8(plaintext))

      return Array.isArray(parsed) ? parsed : why(message, 'file-block-not-an-array')
    } catch {
      // Same rule as an unreadable body: no key, no files, no exception.
      return why(message, `open-failed (index ${envelope.i}, body ${message.decryption ?? 'unknown'})`)
    }
  }

  /**
   * Say *why* an attachment couldn't be opened, then carry on as if nothing happened.
   *
   * The failure is deliberately silent to the user — a locked card, not an error — which is
   * right for them and useless for anybody trying to work out which of four different causes
   * is in play. Each one is indistinguishable from the others in the UI and needs a different
   * fix: a missing file block means the message was sealed by a build that didn't add one, a
   * missing chain means this device was never given the sender's key, and `open-failed` with
   * a readable body means the chain is right but the file block specifically won't open.
   *
   * Kept out of production noise by the dev check, and carrying no key material.
   */
  function why(message: Message, reason: string): SealedFile[] {
    if (import.meta.dev) {
      console.warn(`[e2ee] attachment metadata unavailable on message ${message.id}: ${reason}`)
    }

    return []
  }

  /**
   * Decrypt one message in place, returning a new object the timeline can render.
   *
   * Marked `decryption: 'failed'` rather than mutated into something misleading when it can't
   * be read — the body is deliberately left as the envelope rather than blanked, so the UI
   * decides what to draw and nothing downstream mistakes an unreadable message for an empty
   * one.
   */
  async function decryptIncoming(message: Message): Promise<Message> {
    if (!message.encrypted) return message

    const envelope = unpackEnvelope(message.body)
    if (!envelope) return { ...message, decryption: 'failed' }

    const chain = await encryption.chainForSender(message.channel_id, envelope.e, envelope.d)
    if (!chain) return { ...message, decryption: 'failed' }

    try {
      // Wind a *copy* forward. The stored chain must not advance on a read: messages arrive
      // out of order and get re-rendered, and a receive chain that ratcheted past a message
      // would make it permanently unreadable on the second look.
      const step = await messageKeyAt({ ...chain, index: 0 }, envelope.i)
      const plaintext = await decryptWithKey(step.messageKey, sealedFrom(envelope), contextBytes(envelope))

      /*
       * Keep the envelope alongside the plaintext.
       *
       * Decryption replaces `body` with the readable text, which is what every renderer
       * wants — and it also destroys the only copy of the file keys, which live in the same
       * envelope. Anything asking about attachments later (see decryptFileMeta) would be
       * handed the plaintext and find no envelope in it at all.
       *
       * This is not a subtle race or a state problem: it fails identically every time, for
       * every message with an attachment, which is exactly what made it look like a storage
       * or a key bug rather than one line of overwriting.
       */
      return { ...message, body: fromUtf8(plaintext), cipher_body: message.body, decryption: 'ok' }
    } catch {
      // Wrong key, tampered header, a chain from a different era. All the same to a reader.
      return { ...message, decryption: 'failed' }
    }
  }

  /**
   * A whole page of messages.
   *
   * Sequential rather than `Promise.all`: the chains live in one IndexedDB store and the
   * ratchet for a given sender has to be wound in order, so a burst of parallel reads would
   * contend for the same records to no benefit. A page is 200 rows of symmetric crypto —
   * microseconds each.
   */
  async function decryptAll(messages: Message[]): Promise<Message[]> {
    const out: Message[] = []
    for (const message of messages) out.push(await decryptIncoming(message))

    return out
  }

  return { encryptOutgoing, decryptIncoming, decryptAll, decryptFileMeta }
}
