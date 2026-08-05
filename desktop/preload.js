// The entire desktop surface exposed to the page: a flag saying which shell it's in, and the
// screen-share picker.
//
// usePlatform() reads `window.sideChatDesktop` to decide it's Electron. The picker is here
// because it has to be: Electron makes the *app* responsible for answering getDisplayMedia,
// and the list of screens and windows only exists in the main process. Everything else the app
// does it does over HTTP like every other client, so there is no reason to open a wider door.

const { contextBridge, ipcRenderer } = require('electron')

contextBridge.exposeInMainWorld('sideChatDesktop', {
  platform: process.platform,
  version: process.env.npm_package_version ?? null,

  screenShare: {
    /**
     * Called when something in the page asks for a screen, with the sources to choose from.
     * Returns an unsubscribe, because the app mounts this once and Nuxt hot-reloads.
     */
    onRequest(handler) {
      const listener = (_event, payload) => handler(payload)
      ipcRenderer.on('screen-share:request', listener)
      return () => ipcRenderer.off('screen-share:request', listener)
    },
    /** Answer with a source id, and whether to send the machine's audio with it. */
    pick(sourceId, audio) {
      ipcRenderer.send('screen-share:pick', { sourceId, audio: !!audio })
    },
    /** Dismissed. getDisplayMedia rejects, which the app reads as "changed their mind". */
    cancel() {
      ipcRenderer.send('screen-share:cancel')
    },
    /** The share ended. Drops the remembered source, and with it any control session. */
    stopped() {
      ipcRenderer.send('screen-share:stopped')
    },
  },

  /**
   * Direct-to-bucket uploads, made from the main process — see main.js for why they can't be
   * made from the page. Nothing here can name a destination the API didn't sign: the URL and
   * its headers come straight back from `POST /api/uploads` and are passed through untouched.
   */
  uploads: {
    /** Open a PUT. Resolves to the id the slices and the finish are addressed to. */
    begin: (url, headers) => ipcRenderer.invoke('upload:begin', { url, headers }),
    /** Hand over one slice. Resolves when the socket has taken it — see main.js. */
    write: (id, chunk) => ipcRenderer.invoke('upload:write', id, chunk),
    /** No more bytes. Resolves to `{ status }` once the store has answered. */
    finish: id => ipcRenderer.invoke('upload:finish', id),
    /** Cancelled, or the page is giving up on it. */
    abort: id => ipcRenderer.send('upload:abort', id),
  },

  /**
   * Remote control, sharer side only.
   *
   * Note what is *not* here: nothing that reads the screen, nothing that grants anything. This
   * is a one-way pipe for input events belonging to a session the user already approved in the
   * app, plus a capability probe so the UI can be honest before they're asked. The consent
   * itself lives in the web app (useRemoteControl), because that's where the other person is.
   */
  remoteControl: {
    /** `{ available, screenOnly, sharing, sharingIsScreen }` — see main.js. */
    capabilities: () => ipcRenderer.invoke('remote-control:capabilities'),
    /** Apply one input event. Fire-and-forget; see main.js for why it isn't awaited. */
    send(event) {
      ipcRenderer.send('remote-control:input', event)
    },
    /** Session over. Lifts any button or key the controller was still holding. */
    stop() {
      ipcRenderer.send('remote-control:stop')
    },
  },
})
