import type { GifResult, Message } from '~/types'

/**
 * Builds the request body for sending/editing a message.
 *
 * When files are involved we must send multipart/form-data (PHP can't parse a
 * multipart body on PATCH, so edits are POSTed with `_method=PATCH` spoofing).
 * With no files we keep sending plain JSON.
 */
export function buildMessagePayload(opts: {
  body?: string | null
  replyToId?: number | null
  files?: File[]
  /** Ids of files staged through the chunked path — see useChunkedUpload. */
  uploadIds?: string[]
  removeAttachmentIds?: number[]
  gif?: GifResult | null
  method?: 'PATCH'
}): FormData | Record<string, unknown> {
  const files = opts.files ?? []
  const uploads = opts.uploadIds ?? []
  const removals = opts.removeAttachmentIds ?? []
  const gif = opts.gif ?? null

  // A GIF carries no binary, and a chunked upload has already sent its bytes — so both stay
  // plain JSON. Multipart is only for files travelling *in* this request.
  if (files.length === 0 && removals.length === 0) {
    return {
      ...(opts.body !== undefined ? { body: opts.body } : {}),
      ...(opts.replyToId !== undefined ? { reply_to_id: opts.replyToId ?? null } : {}),
      ...(gif ? { gif: gifFields(gif) } : {}),
      ...(uploads.length ? { uploads } : {}),
    }
  }

  const form = new FormData()
  if (opts.method) form.append('_method', opts.method)
  if (opts.body) form.append('body', opts.body)
  if (opts.replyToId) form.append('reply_to_id', String(opts.replyToId))
  files.forEach(file => form.append('attachments[]', file))
  uploads.forEach(id => form.append('uploads[]', id))
  removals.forEach(id => form.append('remove_attachment_ids[]', String(id)))
  if (gif) {
    Object.entries(gifFields(gif)).forEach(([k, v]) => form.append(`gif[${k}]`, String(v)))
  }

  return form
}

/** The subset of a picked GIF the API stores — see SendMessageData::validationRules(). */
function gifFields(gif: GifResult) {
  return {
    url: gif.url,
    preview_url: gif.preview_url,
    title: gif.title,
    width: gif.width,
    height: gif.height,
  }
}

export function isFormData(v: unknown): v is FormData {
  return typeof FormData !== 'undefined' && v instanceof FormData
}

export type { Message }
