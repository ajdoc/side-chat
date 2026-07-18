// Closes the Spotify-link OAuth loop.
//
// The account link happens in a popup: after the user authorises, our backend redirects the
// popup to `/?spotifyLinked=1` (or `=0`). This runs on every app load, so in that popup it
// tells the opener how it went and closes itself. In the (rare) full-page fallback it just
// strips the param — useSpotifyAuth re-checks status on its own.
export default defineNuxtPlugin(() => {
  if (typeof window === 'undefined') return

  const params = new URLSearchParams(window.location.search)
  if (!params.has('spotifyLinked')) return

  const ok = params.get('spotifyLinked') === '1'

  if (window.opener) {
    window.opener.postMessage({ type: 'spotify-linked', ok }, window.location.origin)
    window.close()
    return
  }

  // No opener — clean the URL so the param doesn't linger.
  params.delete('spotifyLinked')
  const qs = params.toString()
  window.history.replaceState({}, '', window.location.pathname + (qs ? `?${qs}` : ''))
})
