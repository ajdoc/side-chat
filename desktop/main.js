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

const { app, BrowserWindow, desktopCapturer, ipcMain, net, protocol, session, shell } = require('electron')
// Remote control is a bonus, never a reason the app won't open. The module itself already
// tolerates its optional native dependency being absent, but a packaging slip that leaves the
// file out entirely would otherwise throw here and take the whole shell down before it draws a
// window. Fall back to the same shape the module reports when injection isn't available.
const remoteControl = (() => {
  try {
    return require('./remote-control')
  } catch (error) {
    console.error('Remote control unavailable:', error)
    return {
      capabilities: () => ({ available: false, screenOnly: true }),
      isAvailable: () => false,
      inject: async () => {},
      releaseAll: async () => {},
    }
  }
})()
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
  provideRemoteControl()
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
    // The icon the *running* window and its taskbar entry use. On Windows and macOS the
    // packaged executable carries its own icon and this is ignored, but on Linux and in
    // `npm run dev` it is the only thing standing between us and the default Electron logo.
    // It comes from the web bundle because `build/` is a build resource and isn't packaged.
    icon: path.join(BUNDLE, 'icon-512.png'),
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
  // `fullscreen` is in here for the same reason `media` is: Electron denies it by default, so
  // Element.requestFullscreen() rejects and the fullscreen button on a shared screen or a video
  // does nothing at all in the desktop app while working fine in a browser tab.
  const allowed = new Set(['media', 'audioCapture', 'videoCapture', 'fullscreen', 'notifications', 'clipboard-sanitized-write'])

  partition.setPermissionRequestHandler((_contents, permission, callback) => {
    callback(allowed.has(permission))
  })
  partition.setPermissionCheckHandler((_contents, permission) => allowed.has(permission))
}

/**
 * System audio can only be captured on Windows.
 *
 * Chromium's loopback capture is a Windows implementation; on macOS and Linux Electron has no
 * equivalent, and asking for it there yields a share with no sound rather than an error. The
 * picker says so out loud instead of offering a tick box that quietly does nothing.
 */
const SUPPORTS_LOOPBACK_AUDIO = process.platform === 'win32'

/**
 * Answer `getDisplayMedia` by asking which screen or window to share.
 *
 * Electron hands the app the responsibility for picking a source — Chromium's own picker is
 * not available to it. Until now that responsibility was discharged by silently taking
 * `sources[0]`, the primary screen, which is why pressing "Share screen" on the desktop app
 * never asked anything and quite often shared the wrong monitor.
 *
 * So the picker is drawn by the app itself, in the renderer, from the source list and
 * thumbnails gathered here. `useSystemPicker` is deliberately off: it only exists on Windows 11
 * and macOS 15, and a control that appears on some machines and not others is worse than one
 * that looks the same everywhere.
 *
 * One request is live at a time — `getDisplayMedia` is only ever called from a button the user
 * pressed — so a second request supersedes the first rather than queueing behind it.
 */
/**
 * The `desktopCapturer` source currently being shared, or null.
 *
 * Lives at module scope because two unrelated features need it: the picker sets it, and remote
 * control reads it to work out which display the controller's pointer is aiming at.
 */
let sharedSourceId = null

/**
 * Remote control — see remote-control.js for why the injection can only live out here.
 *
 * The renderer has already done the asking and approving by the time anything reaches this
 * point; what crosses the bridge is a stream of input events for a session the sharer said yes
 * to. There is deliberately no "grant" concept in the main process: consent is the web app's
 * business, and duplicating it here would just be a second thing to keep in sync.
 */
function provideRemoteControl() {
  ipcMain.handle('remote-control:capabilities', () => ({
    ...remoteControl.capabilities(),
    // Control needs a whole display to aim at; a window share has no bounds we can resolve.
    sharing: sharedSourceId,
    sharingIsScreen: typeof sharedSourceId === 'string' && sharedSourceId.startsWith('screen:'),
  }))

  // Fire-and-forget: an input event is worthless by the time a round-trip could confirm it,
  // and dropping one under load is better than queueing a backlog of stale pointer positions.
  ipcMain.on('remote-control:input', (_event, payload) => {
    if (!sharedSourceId) return
    void remoteControl.inject(payload, sharedSourceId).catch(() => {})
  })

  // The session ended — however it ended. Lifts anything still held down.
  ipcMain.on('remote-control:stop', () => {
    void remoteControl.releaseAll()
  })

  // A share that stops takes any control session with it: there is nothing left to point at.
  ipcMain.on('screen-share:stopped', () => {
    sharedSourceId = null
    void remoteControl.releaseAll()
  })
}

function provideScreenSources(partition) {
  /** The in-flight request: Electron's callback, plus the sources it may be answered with. */
  let pending = null

  /**
   * Answer the in-flight request, once.
   *
   * `null` is a refusal, and it has to be exactly that: Electron's native handler treats null or
   * undefined as "the user cancelled" and fails the capture quietly, but reads *any object* as an
   * answer and throws `TypeError: Video was requested, but no video stream was provided` when it
   * has no `video` in it. Thrown from the main process, that is an uncaught exception — the
   * "A JavaScript error occurred in the main process" dialog, over the app, every time somebody
   * shut the picker without picking. See ElectronBrowserContext::DisplayMediaDeviceChosen.
   */
  function settle(response) {
    if (!pending) return
    const { callback, abandon } = pending
    pending = null
    // Taken off the window again, or a long session of shares would stack a listener each.
    abandon?.()
    callback(response ?? null)
  }

  ipcMain.on('screen-share:pick', (_event, { sourceId, audio } = {}) => {
    const source = pending?.sources.find(s => s.id === sourceId)
    if (!source) return settle(null) // it went away while the picker was open

    // Remember what's on the wire: remote control has to map the controller's normalised
    // pointer back onto the display being captured, and this pick is the only place that's
    // known. Cleared when the share stops — see `remote-control:stop`.
    sharedSourceId = sourceId

    settle({
      video: source,
      // `loopback` is the whole machine's output, which is what "share the sound too" means
      // for a screen; a window's own audio is not separable on any platform Electron runs on.
      audio: audio && SUPPORTS_LOOPBACK_AUDIO ? 'loopback' : undefined,
    })
  })

  // `null` is how a request is refused — see settle. The renderer's getDisplayMedia rejects,
  // which useVoice already treats as "changed their mind at the picker", not as an error.
  ipcMain.on('screen-share:cancel', () => settle(null))

  partition.setDisplayMediaRequestHandler(async (request, callback) => {
    settle(null) // a previous picker still open is now moot

    let sources
    try {
      sources = await desktopCapturer.getSources({
        types: ['screen', 'window'],
        thumbnailSize: { width: 320, height: 200 },
        fetchWindowIcons: true,
      })
    } catch {
      return callback(null)
    }

    const win = BrowserWindow.getAllWindows()[0]
    if (!win || win.isDestroyed() || !sources.length) return callback(null)

    // Closing the window mid-pick would otherwise leave getDisplayMedia hanging forever.
    const onClosed = () => settle(null)
    win.once('closed', onClosed)
    pending = { callback, sources, abandon: () => win.off('closed', onClosed) }

    win.webContents.send('screen-share:request', {
      sources: sources.map(source => ({
        id: source.id,
        name: source.name,
        kind: source.id.startsWith('screen:') ? 'screen' : 'window',
        thumbnail: source.thumbnail.isEmpty() ? null : source.thumbnail.toDataURL(),
        icon: source.appIcon && !source.appIcon.isEmpty() ? source.appIcon.toDataURL() : null,
      })),
      // Whether the caller asked for sound at all (an audio-only share always does), and
      // whether this machine can actually provide it.
      audioRequested: request.audioRequested !== false,
      audioSupported: SUPPORTS_LOOPBACK_AUDIO,
    })
  }, { useSystemPicker: false })
}
