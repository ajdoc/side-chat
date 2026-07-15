import type { Message } from '~/types'

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
  removeAttachmentIds?: number[]
  method?: 'PATCH'
}): FormData | Record<string, unknown> {
  const files = opts.files ?? []
  const removals = opts.removeAttachmentIds ?? []

  if (files.length === 0 && removals.length === 0) {
    return {
      ...(opts.body !== undefined ? { body: opts.body } : {}),
      ...(opts.replyToId !== undefined ? { reply_to_id: opts.replyToId ?? null } : {}),
    }
  }

  const form = new FormData()
  if (opts.method) form.append('_method', opts.method)
  if (opts.body) form.append('body', opts.body)
  if (opts.replyToId) form.append('reply_to_id', String(opts.replyToId))
  files.forEach(file => form.append('attachments[]', file))
  removals.forEach(id => form.append('remove_attachment_ids[]', String(id)))

  return form
}

export function isFormData(v: unknown): v is FormData {
  return typeof FormData !== 'undefined' && v instanceof FormData
}

export type { Message }
