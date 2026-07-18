// Loads Spotify's Web Playback SDK once, on demand, resolving to `window.Spotify` when ready.
//
// Only Premium accounts can stream through this SDK, and only after the user has linked
// their Spotify account (see useSpotifyAuth). We load it lazily — a free/unlinked viewer
// never pulls it — and memoise the promise. The SDK insists on a global
// `onSpotifyWebPlaybackSDKReady` callback being present before its script runs.
export default defineNuxtPlugin(() => {
  let ready: Promise<any> | null = null

  function load(): Promise<any> {
    if (ready) return ready

    ready = new Promise((resolve) => {
      const w = window as any
      if (w.Spotify) {
        resolve(w.Spotify)
        return
      }

      const prev = w.onSpotifyWebPlaybackSDKReady
      w.onSpotifyWebPlaybackSDKReady = () => {
        prev?.()
        resolve(w.Spotify)
      }

      if (!document.querySelector('script[src="https://sdk.scdn.co/spotify-player.js"]')) {
        const tag = document.createElement('script')
        tag.src = 'https://sdk.scdn.co/spotify-player.js'
        document.head.appendChild(tag)
      }
    })

    return ready
  }

  return { provide: { spotify: { ready: load } } }
})
