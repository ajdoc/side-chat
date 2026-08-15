/**
 * Session-description plumbing, shared by every transport that speaks WebRTC directly.
 *
 * Extracted from useVoice when the mesh grew a sibling: these are pure functions over an SDP
 * string or a transceiver, they carry no call state, and both the mesh and anything else that
 * negotiates its own peer connections needs exactly the same behaviour out of them. Leaving a
 * second copy inside a transport is how the two would drift — and a codec preference that is
 * right in one place and stale in another is the kind of bug that shows up as "video is black
 * for some people".
 *
 * Nothing here is LiveKit's business: an SFU's SDP is negotiated by its own SDK.
 */

/**
 * gzip a string to base64, and back.
 *
 * The SDP is the one signalling field big enough to matter: an offer can run to ~17KB
 * once every codec and — with TURN — every relay candidate is spelled out, and Reverb
 * closes the socket with a 1009 on any whisper past its message-size limit (which the
 * mesh experiences as peers that flap in and out or never connect). SDP is highly
 * repetitive text, so gzip takes that ~17KB down to a couple of KB, comfortably under
 * the cap and independent of how many codecs or candidates the browser decided to list.
 */
export async function gzipToBase64(text: string): Promise<string> {
  const stream = new Blob([text]).stream().pipeThrough(new CompressionStream('gzip'))
  const bytes = new Uint8Array(await new Response(stream).arrayBuffer())

  // Chunked so a large SDP can't overflow the argument list of String.fromCharCode.
  let binary = ''
  for (let i = 0; i < bytes.length; i += 0x8000) {
    binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000))
  }
  return btoa(binary)
}

export async function base64ToGunzip(b64: string): Promise<string> {
  const binary = atob(b64)
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)

  const stream = new Blob([bytes]).stream().pipeThrough(new DecompressionStream('gzip'))
  return new Response(stream).text()
}

/**
 * Turn Opus DTX and in-band FEC on: while a line is silent the encoder sends only the occasional comfort-noise
 * update instead of a full stream, so quiet costs almost nothing — and in a call most mics are
 * quiet most of the time.
 *
 * FEC is the other half, and it's what a lossy connection actually hears. Opus can carry a
 * coarse copy of the previous frame inside the current one, so a single dropped packet — the
 * common case on wifi, and the one that makes a word arrive as a click — is reconstructed
 * instead of concealed. It costs a few percent of bitrate and only when the encoder judges the
 * loss rate worth it, which is exactly the trade we want on both speech and shared music.
 *
 * Applied to every description we *send*, which is enough on its own: an Opus encoder
 * configures itself from the *remote* fmtp — its peer's declared receive preferences — so both
 * ends running this switch DTX on for both directions, without touching our own local
 * description or the perfect-negotiation state machine. A half-deployed pair simply gets the
 * saving in one direction rather than wedging.
 *
 * These two *only*, deliberately. In BUNDLE both audio m-lines — your microphone and the shared
 * tab/system audio — share one Opus payload type and so one fmtp line; forcing mono or a low
 * bitrate here would also crush shared music. Those two belong to the mic alone, so they live
 * on the mic *sender* (its bitrate cap; channelCount: 1 captures it mono) and leave the
 * shared-audio stream at full quality. These two are the nudges that suit both: DTX does
 * nothing to continuous music (there's no silence to trim) and saves on everything else, and
 * FEC helps anything that has to survive a dropped packet.
 */
export function mungeOpus(sdp: string): string {
  const pt = sdp.match(/a=rtpmap:(\d+) opus\/48000/i)?.[1]
  if (!pt) return sdp

  const want = ['usedtx=1', 'useinbandfec=1']
  const fmtp = new RegExp(`a=fmtp:${pt} ([^\\r\\n]*)`)

  if (fmtp.test(sdp)) {
    // Merge into the existing fmtp: overwrite any key we care about, keep the rest untouched.
    return sdp.replace(fmtp, (_line, existing: string) => {
      const parts = existing.split(';').map(s => s.trim()).filter(Boolean)
      for (const entry of want) {
        const key = entry.split('=')[0]!
        const at = parts.findIndex(p => p.startsWith(`${key}=`))
        if (at === -1) parts.push(entry)
        else parts[at] = entry
      }
      return `a=fmtp:${pt} ${parts.join(';')}`
    })
  }

  // No fmtp line yet — add one right after Opus's rtpmap.
  return sdp.replace(
    new RegExp(`(a=rtpmap:${pt} opus/48000[^\\r\\n]*\\r?\\n)`, 'i'),
    `$1a=fmtp:${pt} ${want.join(';')}\r\n`,
  )
}

/**
 * Reorder a transceiver's codec list, most-wanted first.
 *
 * A *reorder*, never a filter, and never the payload-type pinning that once broke BUNDLE
 * demux: every codec stays offered, so distinct payload types and the fallback path both
 * survive. Best-effort throughout — an engine without the capability API, or one that rejects
 * the list, keeps its default order rather than losing media.
 */
function preferCodecs(
  transceiver: RTCRtpTransceiver,
  kind: 'audio' | 'video',
  rank: (mimeType: string) => number,
) {
  if (typeof RTCRtpReceiver === 'undefined' || !RTCRtpReceiver.getCapabilities) return
  if (!('setCodecPreferences' in transceiver)) return

  const caps = RTCRtpReceiver.getCapabilities(kind)
  if (!caps) return

  // Stable sort so codecs of equal rank keep the browser's own ordering — profiles, rtx pairs,
  // and the red/telephone-event entries that must stay present.
  const ordered = caps.codecs
    .map((codec, index) => ({ codec, index }))
    .sort((a, b) => rank(a.codec.mimeType) - rank(b.codec.mimeType) || a.index - b.index)
    .map(entry => entry.codec)

  try {
    transceiver.setCodecPreferences(ordered)
  } catch {
    // An engine that rejects the list keeps its default order rather than losing media.
  }
}

/**
 * Prefer VP9, then VP8, for a video transceiver. VP9 buys noticeably sharper screen text at
 * the same bitrate than VP8.
 *
 * AV1 is deliberately left where it is and never raised. In a mesh a share is encoded once per
 * peer on the sharer's machine, and realtime AV1 at these sizes is a CPU trap — the exact cost
 * the mesh's tuning is built to avoid.
 */
export function preferEfficientVideo(transceiver: RTCRtpTransceiver) {
  preferCodecs(transceiver, 'video', (mimeType) => {
    switch (mimeType.toLowerCase()) {
      case 'video/vp9': return 0
      case 'video/vp8': return 1
      case 'video/av1': return 4 // available, but never preferred for a realtime mesh encode
      default: return 2 // H.264, and the rtx/red/ulpfec machinery that must stay present
    }
  })
}

/**
 * Put Opus at the head of an audio transceiver's codec list.
 *
 * It is already first almost everywhere, and that "almost" is the point: a browser that has
 * negotiated down to G.722 or PCMU gives you 8kHz telephone audio with no DTX, no FEC and no
 * stereo, and none of the app's audio tuning applies to it.
 */
export function preferOpus(transceiver: RTCRtpTransceiver) {
  preferCodecs(transceiver, 'audio', mimeType => (mimeType.toLowerCase() === 'audio/opus' ? 0 : 1))
}
