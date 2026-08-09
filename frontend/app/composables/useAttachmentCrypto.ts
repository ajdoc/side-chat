import type { Attachment, Message } from '~/types'
import { decryptFile } from '~/lib/crypto/attachment'
import { fromBase64 } from '~/lib/crypto/primitives'

/**
 * Turning an encrypted attachment back into one the rest of the app can render.
 *
 * Everything downstream — AttachmentList, the image grid, the audio player, the file card —
 * works off `name`, `mime_type`, `is_image` and a URL. For an encrypted attachment the server
 * can supply none of those honestly: the name is a placeholder, the type is
 * `application/octet-stream`, and the URL serves ciphertext that an `<img>` would render as a
 * broken icon. So rather than teaching every one of those components about encryption, this
 * resolves an encrypted attachment into an ordinary-looking one — real name, real type, and a
 * blob URL holding the decrypted bytes.
 *
 * **When the bytes are fetched** is the one genuine decision here. Rendering an image requires
 * them, so images cannot be lazy. A 300MB video, though, must not download because somebody
 * scrolled past it — and on an encrypted attachment there is no range-request streaming to
 * fall back on, since the authentication tag covers the whole file. The split is by size, and
 * the threshold is a judgement rather than a law: below it, decrypt on sight so the timeline
 * behaves normally; above it, wait to be asked.
 */

/**
 * Files up to this size decrypt as soon as they appear; larger ones wait for a click.
 *
 * 8MB comfortably covers photographs, screenshots, documents and voice notes — the things a
 * timeline is mostly made of — while keeping a long video off the wire until it's wanted.
 */
const EAGER_LIMIT = 8 * 1024 * 1024

export function useAttachmentCrypto() {
  const crypto = useMessageCrypto()

  /**
   * Blob URLs handed out so far, so they can be revoked.
   *
   * A blob URL pins its bytes in memory until revoked or the document goes away, so a long
   * session scrolling an image-heavy channel would otherwise accumulate every photograph it
   * had ever drawn.
   */
  const issued = new Set<string>()

  /**
   * Resolve a message's attachments for rendering.
   *
   * Non-encrypted messages pass through untouched — the overwhelming majority, and not worth
   * a copy. A file whose key is missing or whose bytes fail authentication comes back marked
   * `undecryptable`, which the UI draws as a locked card rather than a broken image.
   */
  async function resolve(message: Message): Promise<Attachment[]> {
    const attachments = message.attachments ?? []

    if (!message.encrypted || attachments.length === 0) return attachments

    // Order is the only link between a key and its file — see sealFiles in useMessages.
    const meta = await crypto.decryptFileMeta(message)

    return Promise.all(attachments.map(async (attachment, index) => {
      const file = meta[index]

      if (!file) return { ...attachment, undecryptable: true }

      const resolved: Attachment = {
        ...attachment,
        name: file.n,
        mime_type: file.m,
        is_image: file.m.startsWith('image/'),
        is_pdf: file.m === 'application/pdf',
        is_gif: file.m === 'image/gif',
        // Cleared until the bytes are in hand: pointing these at the server's URL would hand
        // an <img> or a download link the raw ciphertext. The real URL is kept alongside,
        // because it is still where the bytes come from when somebody asks for them.
        cipher_url: attachment.url,
        url: '',
        download_url: '',
        encryptedKey: file.k,
      }

      return attachment.size <= EAGER_LIMIT ? decryptInto(resolved) : resolved
    }))
  }

  /**
   * Fetch and decrypt one attachment's bytes, filling in its URLs.
   *
   * Also what a "Download" on a large attachment calls. Safe to call twice — a resolved
   * attachment already carrying a blob URL is returned as-is rather than fetched again.
   */
  async function decryptInto(attachment: Attachment): Promise<Attachment> {
    if (attachment.url || !attachment.encryptedKey) return attachment

    try {
      // The signed URL still serves the bytes; they just happen to be ciphertext.
      const response = await fetch(serverUrlFor(attachment))
      if (!response.ok) throw new Error(`attachment fetch failed: ${response.status}`)

      const blob = await decryptFile(
        await response.arrayBuffer(),
        fromBase64(attachment.encryptedKey),
        attachment.mime_type,
      )

      const url = URL.createObjectURL(blob)
      issued.add(url)

      return { ...attachment, url, download_url: url }
    } catch {
      // A tampered file, a missing one, an expired URL. All the same to a reader, and none of
      // them should take the timeline down with them.
      return { ...attachment, undecryptable: true }
    }
  }

  /**
   * Where the ciphertext actually lives.
   *
   * `resolve` blanks `url`, so the original signed URL has to be kept somewhere — it rides on
   * `cipher_url`, set here from whatever the server sent before we overwrote it.
   */
  function serverUrlFor(attachment: Attachment): string {
    return attachment.cipher_url ?? attachment.url
  }

  /** Let go of every blob URL this composable handed out. */
  function revokeAll() {
    for (const url of issued) URL.revokeObjectURL(url)
    issued.clear()
  }

  onScopeDispose(revokeAll)

  return { resolve, decryptInto, revokeAll, EAGER_LIMIT }
}
