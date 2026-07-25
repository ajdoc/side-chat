// The entire desktop surface exposed to the page: a flag saying which shell it's in.
//
// usePlatform() reads `window.sideChatDesktop` to decide it's Electron. Nothing else is
// bridged — the app talks to the API over HTTP like every other client, so there is no
// reason to open a wider door.

const { contextBridge } = require('electron')

contextBridge.exposeInMainWorld('sideChatDesktop', {
  platform: process.platform,
  version: process.env.npm_package_version ?? null,
})
