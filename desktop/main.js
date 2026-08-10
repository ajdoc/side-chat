// The desktop shell: one window, and the same generated Nuxt bundle the web serves.
//
// Three things here are load-bearing.
//
// 1. The bundle is served over **http on loopback**, not `file://` and not a custom scheme.
//    Nuxt routes with the History API, so `file://` — which has no notion of a path that
//    isn't a file — would 404 on a reload of /servers/3/channels/9. A custom `app://` scheme
//    fixes that much and is still registered below as a fallback, but it is not good enough
//    for the music widget: an embedded third party sees the embedder's origin, and the two
//    engines the player runs on both refuse a scheme they don't recognise. YouTube's IFrame
//    player won't start under an `app://` embedder and the Spotify Web Playback SDK requires
//    https-or-localhost outright, so on the desktop build the music card sat there and never
//    made a sound. `http://localhost` is a *potentially trustworthy* origin per the spec — a
//    secure context, so getUserMedia, EME and service workers all still work — and it is the
//    one origin every embeddable player already understands. The hostname matters as much as
//    the scheme: served from the bare IP `127.0.0.1` instead, YouTube refuses every embed
//    outright (`errorCode: "auth"`, player error 150), in plain Chrome as much as in Electron.
//    The port is fixed so the origin is stable across launches; the origin is what
//    localStorage (and therefore the auth token) hangs off.
// 2. Media permission requests are answered here. Electron denies getUserMedia by default,
//    which would silently break every voice channel.
// 3. Screen sharing needs a source picker. Chromium's own picker isn't available to an
//    Electron app, so `setDisplayMediaRequestHandler` supplies one.

const { app, BrowserWindow, desktopCapturer, ipcMain, Menu, nativeImage, net, protocol, safeStorage, session, shell, Tray } = require('electron')
const http = require('node:http')
const fs = require('node:fs')
const fsp = require('node:fs/promises')
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

// Two copies of the app would fight over the fixed port, and the loser would silently fall
// back to `app://` — a different origin, so a second window logged out of the account the
// first one is signed into. One instance; a second launch just raises the window we have.
const gotLock = app.requestSingleInstanceLock()
if (!gotLock) app.quit()

/**
 * Let media start without Chromium demanding its own idea of a user gesture.
 *
 * Chromium gates autoplay on a "media engagement index" — a per-origin score built from a
 * history of you deliberately playing sound there. A browser you have used for months has
 * one for youtube.com; a freshly installed desktop app serving from localhost:43117 never
 * will, and the score is per-origin, so it can never accumulate one either.
 *
 * The music player *does* start from a real click ("Listen along"), but the player lives in
 * a cross-origin YouTube iframe and the gesture doesn't cross that boundary — so the embed
 * refused to start, the player noticed it had stalled, and offered "resume audio", which
 * refused for exactly the same reason. Hence a room playing on and a desktop app that never
 * made a sound.
 *
 * Set as a command-line switch rather than a webPreferences flag because it has to be in
 * place before the first renderer is created. Safe here in a way it would not be in a
 * browser: nothing loads in this window except our own app.
 */
app.commandLine.appendSwitch('autoplay-policy', 'no-user-gesture-required')

/** Where the bundle is being served from, once the server is up. Set before any window opens. */
let appOrigin = 'app://side-chat'

// A second launch raises what we already have — including from the tray, which is the
// normal way back in once the window has been closed.
app.on('second-instance', showWindow)

app.whenReady().then(async () => {
  const partition = session.defaultSession

  registerAppProtocol()
  passAsAChrome(partition)
  appOrigin = await startBundleServer()
  grantMediaPermissions(partition)
  provideScreenSources(partition)
  provideRemoteControl()
  provideUploads()
  provideSecrets()
  createWindow()
  createTray()

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createWindow()
  })
})

/**
 * Closing the window hides it. Quitting is a deliberate act.
 *
 * This is the whole of the desktop notification story, and the reason alerts used to stop
 * arriving for people who "closed" the app: an Electron process that exits takes its
 * websocket with it, and a chat client with no connection cannot be told anything. There
 * is no push channel that would fix that — no Electron equivalent of FCM — so the fix is
 * simply not to die. The app goes to the tray, the socket stays up, and the same code that
 * raises a notification while you're in another tab raises it while you're in another app.
 *
 * `isQuitting` is what separates "the user closed the window" from "the user chose Quit",
 * since both arrive here as a close.
 */
let tray = null
let isQuitting = false

app.on('before-quit', () => { isQuitting = true })

// Deliberately empty of the usual `app.quit()`. macOS already worked this way; this makes
// Windows and Linux behave the same, which is what every chat app on the platform does.
app.on('window-all-closed', () => {})

/**
 * The tray icon, and the only visible way back to a hidden window.
 *
 * Built once. Without it, hiding the window on close would strand the app in a state with
 * no UI and no way to reach it short of the task manager — which is a far worse bug than
 * the one being fixed.
 */
function createTray() {
  if (tray) return

  const icon = nativeImage.createFromPath(path.join(BUNDLE, 'icon-512.png'))
  // 16px is the tray's own idiom; handing it a 512px bitmap gets a blurry mess on Windows
  // and a comically large icon on some Linux panels.
  tray = new Tray(icon.resize({ width: 16, height: 16 }))

  tray.setToolTip('Side Chat')
  tray.setContextMenu(Menu.buildFromTemplate([
    { label: 'Open Side Chat', click: showWindow },
    { type: 'separator' },
    { label: 'Quit', click: () => { isQuitting = true; app.quit() } },
  ]))

  // Clicking the icon itself is what most people try first, so it does the obvious thing.
  tray.on('click', showWindow)
}

/** Raise the window, whatever it was doing — hidden, minimised, or behind everything. */
function showWindow() {
  const win = BrowserWindow.getAllWindows()[0]

  if (!win) return createWindow()

  if (!win.isVisible()) win.show()
  if (win.isMinimized()) win.restore()
  win.focus()
}

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

  // The close button hides rather than destroys, so the connection — and therefore every
  // notification that depends on it — outlives the window. Quit is what actually exits.
  win.on('close', (event) => {
    if (isQuitting) return

    event.preventDefault()
    win.hide()
  })

  // Anything that isn't the app itself belongs in the user's browser — an OAuth flow, a
  // link somebody posted. Spotify's link popup is the one exception the app opens itself.
  win.webContents.setWindowOpenHandler(({ url }) => {
    if (url.startsWith('app://') || url.startsWith(appOrigin)) return { action: 'allow' }
    shell.openExternal(url)
    return { action: 'deny' }
  })

  if (DEV_URL) win.loadURL(DEV_URL)
  else win.loadURL(`${appOrigin}/`)
}

/**
 * Stop announcing ourselves as Electron.
 *
 * Electron's default User-Agent carries `side-chat-desktop/0.1.0` and `Electron/33.0.0`
 * alongside the Chrome token. YouTube reads that, decides the client is not a browser it
 * is willing to serve video to, and refuses the embed — `isPlayable: false`,
 * `errorCode: "auth"`, player error 150 — on videos that embed perfectly well anywhere
 * else. Nothing in the page can see that; it surfaces only as a player that never starts,
 * which is why this looked like an autoplay problem for so long.
 *
 * Removing the two custom tokens leaves the honest Chrome UA underneath, which is a fair
 * description: this *is* the same Chromium, rendering in the same way.
 *
 * Set on the session rather than the window, so it also applies to the cross-origin
 * iframes — the YouTube embed being the entire point.
 */
function passAsAChrome(partition) {
  // An allowlist rather than a list of things to remove. Naming the tokens to strip means
  // guessing what Electron decided to call us — it uses the *product* name with the spaces
  // taken out ("SideChat/0.1.0"), not the package name — and a rename would quietly put the
  // token back. A real Chrome UA contains exactly these four `name/version` pairs, so
  // anything else in there is ours and shouldn't be.
  const ua = partition.getUserAgent()
    .replace(/ (?!Mozilla\/|AppleWebKit\/|Chrome\/|Safari\/)[^\s()]+\/[^\s()]+/g, '')

  partition.setUserAgent(ua)
}

/**
 * The fixed loopback port the bundle is served on.
 *
 * Fixed, not ephemeral, because the port is part of the origin and the origin is where the
 * app's localStorage lives: a port that moved between launches would sign the user out every
 * time they opened the app. Bound to loopback only, so nothing outside this machine can
 * reach it.
 */
const BUNDLE_PORT = 43117

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.webmanifest': 'application/manifest+json; charset=utf-8',
  '.map': 'application/json; charset=utf-8',
  '.txt': 'text/plain; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.webp': 'image/webp',
  '.avif': 'image/avif',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.ttf': 'font/ttf',
  '.wasm': 'application/wasm',
  '.mp3': 'audio/mpeg',
  '.ogg': 'audio/ogg',
  '.webm': 'video/webm',
  '.mp4': 'video/mp4',
}

/**
 * Serve `desktop/web` over `http://localhost:<BUNDLE_PORT>`.
 *
 * **`localhost`, not `127.0.0.1`, and that is not cosmetic.** YouTube refuses to embed into
 * a page whose origin is a bare loopback IP: the player reports `isPlayable: false`,
 * `errorCode: "auth"` and error 150 for every video, including ones that embed anywhere
 * else. It is not an Electron problem — plain Chrome at `http://127.0.0.1:43117` fails
 * identically — which is exactly what made it so hard to see from inside the app.
 *
 * The hostname is the whole of the difference. `localhost` is a *potentially trustworthy*
 * origin in the same way the IP is (secure context, so getUserMedia and EME still work), but
 * it is also the origin every embeddable player is used to seeing from a dev machine.
 *
 * Bound to loopback only, so nothing outside this machine can reach it — the hostname
 * changed, the exposure did not.
 *
 * Resolves to the origin to load. If the port can't be bound — something else on the machine
 * has it — we fall back to the `app://` protocol rather than refusing to start: a desktop app
 * whose music doesn't play is still much better than one that doesn't open.
 */
function startBundleServer() {
  return new Promise((resolve) => {
    const server = http.createServer(serveBundle)

    server.once('error', (error) => {
      console.error(`Could not serve the bundle on ${BUNDLE_PORT}, falling back to app://`, error)
      resolve('app://side-chat')
    })

    // Listening on the *name* rather than on 127.0.0.1 lets Node bind whichever loopback
    // address this machine resolves it to. Windows answers `localhost` with ::1 first, and a
    // server bound only to the IPv4 loopback would leave the browser retrying an address
    // nothing is listening on.
    server.listen(BUNDLE_PORT, 'localhost', () => {
      resolve(`http://localhost:${BUNDLE_PORT}`)
    })

    // Nothing should outlive the app; an unclosed listener keeps the process alive on quit.
    app.once('will-quit', () => server.close())
  })
}

/**
 * One request against the bundle, with the SPA fallback that makes client-side routing work:
 * any path that isn't a real file is the SPA's business, not a 404.
 */
async function serveBundle(req, res) {
  if (req.method !== 'GET' && req.method !== 'HEAD') {
    res.writeHead(405, { 'Allow': 'GET, HEAD' })
    return res.end()
  }

  // Only ever used to give the relative request URL something to be relative *to*; the
  // host here never leaves this function.
  const { pathname } = new URL(req.url, `http://localhost:${BUNDLE_PORT}`)
  const relative = decodeURIComponent(pathname).replace(/^\/+/, '')
  const candidate = path.resolve(BUNDLE, relative)

  // Refuse to serve anything outside the bundle, however the URL was spelled.
  const withinBundle = candidate === BUNDLE || candidate.startsWith(BUNDLE + path.sep)
  const target = withinBundle && relative && path.extname(relative)
    ? candidate
    : path.join(BUNDLE, 'index.html')

  let stat
  try {
    stat = await fsp.stat(target)
  } catch {
    // A missing *asset* is a real 404; a missing index.html means a broken packaging job, and
    // either way there is nothing useful to send.
    res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' })
    return res.end('Not found')
  }

  const type = MIME[path.extname(target).toLowerCase()] ?? 'application/octet-stream'
  // The hashed `_nuxt` assets are immutable by construction; everything else (index.html
  // above all) must be re-read, or an upgraded app would boot the previous build's HTML.
  const cache = relative.startsWith('_nuxt/') ? 'public, max-age=31536000, immutable' : 'no-cache'
  res.writeHead(200, { 'Content-Type': type, 'Content-Length': stat.size, 'Cache-Control': cache })
  if (req.method === 'HEAD') return res.end()

  fs.createReadStream(target)
    .on('error', () => res.destroy())
    .pipe(res)
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

/**
 * Send a big attachment straight to the object store on the page's behalf.
 *
 * Large uploads take a signed URL and PUT the bytes to the bucket themselves, bypassing the
 * API entirely (see useChunkedUpload). In a browser that works because the bucket's CORS
 * policy names the site's origin. The desktop shell's origin is `http://localhost:43117` — a
 * loopback port belonging to this machine, which no bucket policy will ever have heard of —
 * so the preflight is refused and every large upload failed with "the storage service could
 * not be reached", the browser's indistinguishable-from-offline report of a CORS rejection.
 *
 * The main process is not a browser and enforces no same-origin policy, so the fix is to make
 * the PUT from here. `net.request` also goes through Chromium's network stack, so it keeps the
 * proxy settings and system certificates the renderer would have used.
 *
 * The bytes arrive a slice at a time rather than as one buffer: these are the files big enough
 * to be worth signing a URL for, and moving a 2GB `ArrayBuffer` across the bridge in one piece
 * would copy it twice and be structured-cloned in between. Each `write` resolves only once the
 * socket has taken the slice, which is what paces the renderer's reads to the network.
 */
/**
 * Secrets the page needs kept out of its own profile directory.
 *
 * Exactly one thing uses this today: the encryption vault key, which wraps the sender chain
 * keys sitting in IndexedDB. Those keys have to exist as bytes — the message ratchet derives
 * from them — so on the web they are readable by anyone who can read the profile folder.
 * Here they aren't, because the key that unlocks them lives in the OS keychain instead.
 *
 * `safeStorage` is Electron's front end to Keychain on macOS, DPAPI on Windows and
 * libsecret/kwallet on Linux. It encrypts *for this application on this machine*, which is
 * the property that matters: copying the profile directory to another computer gets you
 * ciphertext and nothing else.
 *
 * The encrypted blob is written next to the app's own data rather than into the keychain
 * itself — that is how safeStorage is meant to be used, and it is why the Linux case degrades
 * gracefully: with no secret service running, `isEncryptionAvailable()` is false, this bridge
 * says so, and the app falls back to unwrapped keys rather than losing them.
 */
function provideSecrets() {
  const file = path.join(app.getPath('userData'), 'secrets.json')

  /** The whole store, or an empty one. A corrupt file is treated as absent, deliberately. */
  function read() {
    try {
      return JSON.parse(fs.readFileSync(file, 'utf8'))
    } catch {
      // Missing is the ordinary first-launch case. Unparseable means somebody or something
      // damaged it, and there is nothing to recover — starting fresh loses the vault key and
      // therefore the local chain keys, which is bad, but guessing at half a file is worse.
      return {}
    }
  }

  ipcMain.handle('secrets:available', () => safeStorage.isEncryptionAvailable())

  ipcMain.handle('secrets:get', (_event, name) => {
    if (typeof name !== 'string') return null

    const stored = read()[name]
    if (typeof stored !== 'string') return null

    try {
      return safeStorage.decryptString(Buffer.from(stored, 'base64'))
    } catch {
      // Written under a keychain this machine no longer has — a restored profile, a reset
      // keyring. Unreadable rather than an error: the caller mints a new vault key, and the
      // chains sealed under the old one are gone. See the note in keyStore.reveal().
      return null
    }
  })

  ipcMain.handle('secrets:set', (_event, name, value) => {
    if (typeof name !== 'string' || typeof value !== 'string') return false
    if (!safeStorage.isEncryptionAvailable()) return false

    const store = read()
    store[name] = safeStorage.encryptString(value).toString('base64')

    // Written 0600: the OS has already encrypted the contents, but there is no reason for
    // another account on the machine to be able to read even the ciphertext.
    fs.writeFileSync(file, JSON.stringify(store), { mode: 0o600 })

    return true
  })
}

function provideUploads() {
  /** In-flight PUTs by id. One per upload; the renderer stages files one at a time. */
  const inFlight = new Map()

  ipcMain.handle('upload:begin', (_event, { url, headers } = {}) => {
    // Only ever a signed URL our own API just handed the page. Anything else — a file:// or
    // a custom scheme — is not something this bridge should be willing to fetch.
    if (typeof url !== 'string' || !/^https?:\/\//i.test(url)) throw new Error('Unsupported upload URL.')

    const request = net.request({ method: 'PUT', url })
    for (const [key, value] of Object.entries(headers ?? {})) {
      if (typeof value === 'string') request.setHeader(key, value)
    }

    const entry = { request, status: null, failure: null, settled: null }

    // The response is read to completion and thrown away: the store answers an empty body on
    // success and an XML error document otherwise, and the status is the whole verdict. Not
    // draining it would leave the socket half-read and the request never finished.
    request.on('response', (response) => {
      response.on('data', () => {})
      response.on('end', () => {
        entry.status = response.statusCode
        entry.settled?.()
      })
    })
    request.on('error', (error) => {
      entry.failure = error
      entry.settled?.()
    })

    const id = `${Date.now()}-${Math.random().toString(36).slice(2)}`
    inFlight.set(id, entry)

    return id
  })

  // Resolves when the slice has been handed to the socket — the callback form of `write` is
  // what makes this backpressure rather than an unbounded buffer in the main process.
  ipcMain.handle('upload:write', (_event, id, chunk) => new Promise((resolve, reject) => {
    const entry = inFlight.get(id)
    if (!entry) return reject(new Error('That upload is no longer open.'))
    if (entry.failure) return reject(entry.failure)

    entry.request.write(Buffer.from(chunk), (error) => {
      if (error) reject(error)
      else resolve()
    })
  }))

  ipcMain.handle('upload:finish', (_event, id) => new Promise((resolve, reject) => {
    const entry = inFlight.get(id)
    if (!entry) return reject(new Error('That upload is no longer open.'))

    const done = () => {
      inFlight.delete(id)
      if (entry.failure) reject(new Error(entry.failure.message ?? 'The storage service could not be reached.'))
      else resolve({ status: entry.status ?? 0 })
    }

    // Either half may already have happened: an error can arrive while the last slice is
    // still being written, and a store that rejects the upload can answer before `end`.
    if (entry.status !== null || entry.failure) done()
    else entry.settled = done

    if (!entry.failure) entry.request.end()
  }))

  ipcMain.on('upload:abort', (_event, id) => {
    const entry = inFlight.get(id)
    if (!entry) return
    inFlight.delete(id)
    try {
      entry.request.abort()
    } catch {
      // Already finished or already aborted; either way there is nothing left to stop.
    }
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
