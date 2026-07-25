// The desktop shell: one window, and the same generated Nuxt bundle the web serves.
//
// Three things here are load-bearing.
//
// 1. The bundle is served over a custom `app://` protocol rather than `file://`. Nuxt uses
//    the History API for routing, and `file://` has no notion of a path that isn't a file —
//    a deep link like /servers/3/channels/9 would 404 on reload. `app://` gives the SPA a
//    real origin, which also makes localStorage, WebSockets and WebRTC behave normally.
// 2. Media permission requests are answered here. Electron denies getUserMedia by default,
//    which would silently break every voice channel.
// 3. Screen sharing needs a source picker. Chromium's own picker isn't available to an
//    Electron app, so `setDisplayMediaRequestHandler` supplies one.

const { app, BrowserWindow, desktopCapturer, net, protocol, session, shell } = require('electron')
const path = require('node:path')
const { pathToFileURL } = require('node:url')

const BUNDLE = path.join(__dirname, 'web')
/**
 * Point the window at the live Nuxt dev server instead of the packaged bundle.
 *
 * A flag rather than only an env var because `npm run dev` has to work from Windows `cmd`
 * too, where a `VAR=value command` prefix isn't syntax. The env var still wins, for a dev
 * server that isn't on the usual port.
 */
const DEV_URL = process.env.SIDE_CHAT_DEV_URL
  ?? (process.argv.includes('--dev') ? 'http://localhost:3000' : undefined)

app.setName('Side Chat')

/**
 * Make `app://` a first-class origin — and do it before the app is ready, which is the only
 * point at which Chromium will listen.
 *
 * Without this the scheme is opaque: no origin, and therefore no storage. The app boots and
 * dies on the first line that touches `localStorage` ("Access is denied for this document"),
 * which is the auth token. `standard` is what gives the pages an origin at all; `secure`
 * puts them in a secure context, which getUserMedia and service workers both insist on;
 * `supportFetchAPI` and `corsEnabled` let the SPA's own asset requests through.
 */
protocol.registerSchemesAsPrivileged([
  {
    scheme: 'app',
    privileges: {
      standard: true,
      secure: true,
      supportFetchAPI: true,
      corsEnabled: true,
      stream: true,
    },
  },
])

app.whenReady().then(() => {
  const partition = session.defaultSession

  registerAppProtocol()
  grantMediaPermissions(partition)
  provideScreenSources(partition)
  createWindow()

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createWindow()
  })
})

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit()
})

function createWindow() {
  const win = new BrowserWindow({
    width: 1280,
    height: 820,
    minWidth: 720,
    minHeight: 520,
    backgroundColor: '#0b0b0e',
    // The app draws its own dark/light chrome; a white flash while the bundle boots reads
    // as a broken launch.
    show: false,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
    },
  })

  win.once('ready-to-show', () => win.show())

  // Anything that isn't the app itself belongs in the user's browser — an OAuth flow, a
  // link somebody posted. Spotify's link popup is the one exception the app opens itself.
  win.webContents.setWindowOpenHandler(({ url }) => {
    if (url.startsWith('app://')) return { action: 'allow' }
    shell.openExternal(url)
    return { action: 'deny' }
  })

  if (DEV_URL) win.loadURL(DEV_URL)
  else win.loadURL('app://side-chat/')
}

/**
 * Serve `desktop/web` over `app://side-chat/`, falling back to index.html.
 *
 * The fallback is what makes client-side routing work: any path that isn't a real file is
 * the SPA's business, not a 404.
 */
function registerAppProtocol() {
  protocol.handle('app', async (request) => {
    const { pathname } = new URL(request.url)
    const relative = decodeURIComponent(pathname).replace(/^\/+/, '')
    const candidate = path.join(BUNDLE, relative)

    // Refuse to serve anything outside the bundle, however the URL was spelled.
    const withinBundle = candidate === BUNDLE || candidate.startsWith(BUNDLE + path.sep)
    const target = withinBundle && relative && path.extname(relative)
      ? candidate
      : path.join(BUNDLE, 'index.html')

    return net.fetch(pathToFileURL(target).toString())
  })
}

/** Voice channels: microphone, camera, and the notifications the app already asks for. */
function grantMediaPermissions(partition) {
  const allowed = new Set(['media', 'audioCapture', 'videoCapture', 'notifications', 'clipboard-sanitized-write'])

  partition.setPermissionRequestHandler((_contents, permission, callback) => {
    callback(allowed.has(permission))
  })
  partition.setPermissionCheckHandler((_contents, permission) => allowed.has(permission))
}

/**
 * Answer `getDisplayMedia` with the whole screen.
 *
 * Electron hands the app the responsibility for picking a source. A proper picker UI is
 * follow-up work; for now the primary screen is offered, with system audio where the
 * platform supports it, which is what "share my screen" means to most people in a call.
 */
function provideScreenSources(partition) {
  partition.setDisplayMediaRequestHandler((_request, callback) => {
    desktopCapturer.getSources({ types: ['screen'] }).then((sources) => {
      callback({ video: sources[0], audio: process.platform === 'win32' ? 'loopback' : undefined })
    }).catch(() => callback({}))
  }, { useSystemPicker: true })
}
