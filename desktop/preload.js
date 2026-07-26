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
  },
})
