// Loads YouTube's IFrame Player API once, on demand, and hands back a promise that
// resolves to `window.YT` when it's ready.
//
// The music widget is a *listen-along*: nobody re-streams anyone's audio (that would break
// YouTube's terms and need Premium). Instead every viewer runs their own hidden YT player
// and we keep them all seeked to the same server-authoritative position — see MusicPlayer.
// The API attaches a single global `onYouTubeIframeAPIReady`, so we load it lazily (only
// for people who actually open a player) and memoise the promise.
export default defineNuxtPlugin(() => {
  let ready: Promise<any> | null = null

  function load(): Promise<any> {
    if (ready) return ready

    ready = new Promise((resolve) => {
      const w = window as any
      if (w.YT?.Player) {
        resolve(w.YT)
        return
      }

      // Chain rather than clobber, in case anything else ever waits on the same hook.
      const prev = w.onYouTubeIframeAPIReady
      w.onYouTubeIframeAPIReady = () => {
        prev?.()
        resolve(w.YT)
      }

      if (!document.querySelector('script[src="https://www.youtube.com/iframe_api"]')) {
        const tag = document.createElement('script')
        tag.src = 'https://www.youtube.com/iframe_api'
        document.head.appendChild(tag)
      }
    })

    return ready
  }

  return { provide: { youtube: { ready: load } } }
})
