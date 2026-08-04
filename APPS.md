# Desktop and mobile apps

Side Chat ships as one web bundle wrapped in two native shells:

| Target                  | Shell     | Project    |
| ----------------------- | --------- | ---------- |
| Windows / macOS / Linux | Electron  | `desktop/` |
| iOS / Android           | Capacitor | `mobile/`  |

Neither shell contains a copy of the app. Both package the output of `nuxt generate`
(`frontend/.output/public`) — the same static SPA the web deploy serves — so a change to the
frontend reaches all five platforms with a rebuild and no porting.

## Scope of the first release

The apps carry **chat, voice, the Side Desk, Side Spaces and server administration** — very
nearly the whole web app. Routes outside that set are present in the bundle but gated off at
navigation time by
[`middleware/native-scope.global.ts`](frontend/app/middleware/native-scope.global.ts), which
holds the allowlist. The sidebar and channel header hide the buttons that lead to blocked
places.

No feature is now withheld by platform. The **Side Space** was the last one, and it isn't a
route question — both shells reach the channel; what it needed was to fit. Walking has always
been tap-and-drag on the canvas, so what changed is the furniture around it: on a window
narrower than 768px the room's toolbar keeps the mic and the way out and folds the rest into a
`⋯` menu, the "in earshot" dock — shared screens, per-person volume, per-screen volume, local
mutes, an owner's force-mute — opens as a full sheet over the room from a people button that
carries a dot when somebody nearby starts sharing, and the map editor's tool rail becomes a
drawer that closes when you pick a brush.
Same breakpoint the sidebar uses, and it asks about the *window*, not the shell — a desktop
window dragged narrow gets the same layout.

To widen the apps later, add routes to that allowlist and drop the matching `isNative`
guards. There is nothing else holding the gate shut.

## Building

Everything starts from one generated bundle. **`API_BASE` must be an address the device can
reach** — `localhost` on a phone means the phone.

```bash
# Desktop, against a local stack
make app-desktop                                  # → desktop/dist/   (Linux/macOS)
make app-desktop-win                              # → C:\Users\<you>\side-chat-desktop\dist

# Phones, against your machine on the LAN
make app-mobile API_BASE=http://192.168.1.20:8000 REVERB_HOST=192.168.1.20

# Production — all five, or the ones you omit fall back to dev values
make app-desktop-win API_BASE=https://api.yourhost.com \
                     REVERB_HOST=ws.yourhost.com REVERB_PORT=443 REVERB_SCHEME=https \
                     REVERB_KEY=yourappkey
```

All five settings are baked into the bundle at generate time — a packaged app has no
environment to read at startup — and anything you leave out falls through to the frontend
container's own values, which for a release build means `http` on port `8080`. The target
prints what it used before generating; check that line. `REVERB_KEY` must match the backend's
`REVERB_APP_KEY`, or HTTP keeps working while every real-time feature goes quiet.

### The API has to allow the app's origin

A packaged app is not served from your web domain. Electron serves it from a loopback HTTP
server at `http://127.0.0.1:43117` (and, only if that port is already taken, from the legacy
`app://side-chat` scheme) and Capacitor from `https://localhost` (Android) or
`capacitor://localhost` (iOS), and those are the `Origin` headers the API sees.

The desktop app takes the loopback origin rather than `app://` because embedded players
insist on it: the YouTube IFrame player won't start under an unknown scheme and the Spotify
Web Playback SDK requires https-or-localhost, so under `app://` the music widget never made
a sound. See the comment at the top of [desktop/main.js](desktop/main.js). Until they're allowed, the browser engine blocks every
request before it leaves — which surfaces as "Unable to sign in. Please try again.", because
the login POST never reaches Laravel at all.

Set `CORS_ALLOWED_ORIGINS` on the API (see [config/cors.php](backend/config/cors.php)) to a
comma-separated list including all of them:

```
CORS_ALLOWED_ORIGINS=https://your-web-app.example.com,http://127.0.0.1:43117,app://side-chat,https://localhost,capacitor://localhost
```

To check a deployed API from anywhere:

```bash
curl -i -X OPTIONS https://api.example.com/api/auth/login \
  -H 'Origin: http://127.0.0.1:43117' -H 'Access-Control-Request-Method: POST'
# access-control-allow-origin must come back as http://127.0.0.1:43117
```

`make app-mobile` syncs into the native projects; open them in the platform IDE to run,
sign and ship:

```bash
cd mobile
npm run android   # opens Android Studio
npm run ios       # opens Xcode (macOS only)
```

### Android APKs

`make app-apk` syncs and builds in one go, leaving the APK in `mobile/dist/`:

```bash
make app-apk API_BASE=https://api.example.com REVERB_HOST=ws.example.com \
             REVERB_PORT=443 REVERB_SCHEME=https REVERB_KEY=yourappkey
```

It needs an Android SDK and a JDK, which it finds in the usual Windows locations (Android
Studio's bundled SDK and JBR, or `C:\Program Files\Java`); override with
`ANDROID_SDK=` / `ANDROID_JDK=` if yours live elsewhere. Like the desktop build, it stages the
project onto the Windows disk before running Gradle — `gradlew.bat` runs under `cmd.exe`,
which has no UNC working directory, and the SDK's build tools are Windows executables that a
Linux Gradle couldn't run in any case.

The default `assembleDebug` is signed with the throwaway debug key: installable on your own
device, not distributable. `make app-apk GRADLE_TASK=assembleRelease` (or `bundleRelease` for
a Play Store `.aab`) needs a keystore configured in `mobile/android/app/build.gradle` first.

Gradle's own outputs stay in the staging directory under
`app/build/outputs/apk/<variant>/`; `mobile/dist/` is just where the finished APKs are copied
back for convenience.

### Building the desktop app on Windows + WSL

Windows npm cannot build anything that lives in the WSL filesystem. Two separate failures
say so: `cmd.exe` refuses a UNC working directory, so a package's install script runs from
`C:\Windows` and finds nothing; and Windows can't clean up files WSL created, leaving
half-installed trees behind (`EPERM`/`ENOTEMPTY`). Node modules with native binaries are also
platform-specific, so a Linux install is the wrong artefact for a Windows app anyway.

`make app-desktop-win` therefore generates the bundle in Docker as usual, stages the shell
onto the Windows disk, and installs and builds it entirely there. `make app-desktop` refuses
to run when npm is the Windows one, rather than failing the confusing way.

#### The symlink privilege, and why it used to eat the app icon

Before electron-builder touches the `.exe` it fetches a bundle called `winCodeSign`. That
bundle is not only about signing — it also carries **`rcedit`**, the tool that stamps the app
icon and version info into the executable. Its archive contains two macOS symlinks, and
creating a symlink on Windows needs a privilege ordinary accounts don't have, so unpacking it
died with:

```
Cannot create symbolic link : A required privilege is not held by the client
```

That took the whole build with it — but *after* `dist/win-unpacked/` had been written and
*before* the icon was applied. The result was a runnable app wearing the default Electron
atom, which reads as "my icon didn't work" rather than "my build failed". If you ever see the
wrong icon again, **check the exit code first**; it is far more likely to be a failed build
than an icon problem.

`make app-desktop-win` now handles this itself. The two symlinks live in `darwin/`, which a
Windows build never touches, so [`make win-codesign-cache`](Makefile) unpacks the archive once
*excluding that directory*, straight into the path electron-builder looks for. It then finds
the cache populated and skips the unpack entirely. The target is idempotent and runs as part
of the build, so there is normally nothing to do by hand.

**Developer Mode is therefore no longer required**, and neither is an Administrator shell.
Turning Developer Mode on (Settings → System → For developers) remains a valid alternative if
you'd rather let electron-builder unpack the archive the usual way.

To check what icon an executable actually carries:

```bash
powershell.exe -NoProfile -Command "Add-Type -AssemblyName System.Drawing; \
  \$i=[System.Drawing.Icon]::ExtractAssociatedIcon('C:\path\to\Side Chat.exe'); \
  \$i.ToBitmap().Save('C:\Users\<you>\exe-icon.png')"
```

One more thing worth knowing: each failed attempt abandons its own ~5.6 MB copy of that
archive under `AppData\Local\electron-builder\Cache\winCodeSign\`. A run of broken builds
can leave a gigabyte of them; the directory is a pure download cache and is safe to empty.

### Desktop development loop

Point the Electron window at the live Nuxt dev server instead of a packaged bundle — hot
reload, with the real native shell around it:

```bash
make up                      # frontend dev server on :3000
cd desktop
npm install
npm run dev
```

### Remote control of a shared screen

Letting someone else drive your screen needs to move the real mouse and press real keys, and a
browser tab cannot do that at any price — it's the sandbox working as designed. So the injection
lives in the Electron shell (`desktop/remote-control.js`) behind a native backend, and the split
is:

| | Can *ask for* and hold control | Can *grant* control |
| --- | --- | --- |
| Web / mobile | yes | no |
| Desktop, backend missing | yes | no |
| Desktop, backend installed | yes | yes, for a **whole-screen** share |

Window shares can't be controlled: mapping the controller's pointer needs the captured surface's
bounds on screen, and Electron only exposes those for displays. The app says so up front rather
than at the moment someone's Allow button turns out to do nothing.

The backend is an **optional dependency** on purpose — a native module that fails to build must
not take the desktop app down with it, so the require is guarded and a shell without it simply
reports the feature unavailable. To enable it:

```bash
cd desktop
npm install @nut-tree-fork/nut-js     # already in optionalDependencies; this forces it
npx electron-rebuild                  # only if the prebuilt binary doesn't match your Electron
```

Linux also needs X11 (`libxtst`); under Wayland, input injection is blocked by the compositor and
the backend will load but do nothing. macOS prompts for **Accessibility** permission on first
use, and denies silently until it's granted in System Settings → Privacy & Security.

Consent itself is *not* here — it lives in `frontend/app/composables/useRemoteControl.ts`,
next to the person doing the consenting. The main process only ever sees input events for a
session that was already approved.

## The app icon

One artwork drives every platform: **`frontend/brand/icon-source.png`**, a square 1024×1024
PNG. Everything else in the table below is a derivative and should never be edited by hand —
replace the source and run `make icons`.

The source sits outside `public/` on purpose. Nuxt copies that directory wholesale into the
bundle, so a 1.3 MB build-time input parked there would be served to every web visitor and
packaged into both native shells for nothing.

| Derivative                                              | Used by                                         |
| ------------------------------------------------------- | ----------------------------------------------- |
| `frontend/public/favicon.ico` (16/32/48)                 | Browser tab                                      |
| `frontend/public/icon-192.png`, `icon-512.png`           | Web, declared in `nuxt.config.ts`                |
| `frontend/public/apple-touch-icon.png` (180)             | "Add to Home Screen" on iOS                      |
| `frontend/public/brand/logo.png` (256)                   | The mark in the sidebar header (`layouts/app.vue`) |
| `desktop/build/icon.png` (1024)                          | Electron — electron-builder derives `.ico`/`.icns` |
| `mobile/ios/App/App/Assets.xcassets/AppIcon.appiconset/AppIcon-512@2x.png` | iOS home screen        |
| `mobile/android/.../mipmap-*/ic_launcher{,_round,_foreground}.png`         | Android launcher       |

Android's adaptive icon crops the outer ~22% and masks the rest to whatever shape the
launcher wants, so `ic_launcher_foreground` is the artwork inset to 82% on a transparent
canvas, with `values/ic_launcher_background.xml` holding the cream the artwork fades to.

Two things about the desktop icon are easy to get wrong, and both fail *silently* — the build
succeeds and ships Electron's default logo:

- electron-builder finds the icon **by convention**, at `buildResources/icon.png`. There is
  deliberately no `icon` key in `desktop/package.json`: an explicit `icon` is resolved
  relative to `buildResources`, so the natural-looking `"build/icon.png"` sends it looking
  for `build/build/icon.png`. (It also rejects unknown keys outright, so the `build` object
  can't carry a comment — hence this paragraph.)
- `make app-desktop-win` builds from a staging directory on the Windows disk, so
  `desktop/build/` has to be copied there along with the shell. Forget it and electron-builder
  simply finds no icon to use.

The icon on the *running* window, as opposed to the executable, is a separate setting:
`BrowserWindow`'s `icon` in [desktop/main.js](desktop/main.js). Windows and macOS take it from
the packaged executable and ignore that, but Linux and `npm run dev` do not. It points into
the web bundle (`web/icon-512.png`) because `build/` is a build resource and isn't packaged.

To regenerate, drop the new artwork in as `icon-source.png` and run:

```bash
make icons
```

That runs [the generator](frontend/scripts/gen-icons.mjs) in the frontend container — which
is where `sharp` can be had — into a staging directory, then copies each derivative to its
final home. The staging step exists because the container only has `frontend/` mounted and
so cannot write to `desktop/` or `mobile/` itself.

`sharp` is installed on the fly rather than added to `package.json`: nothing at runtime needs
it, and it drags in a platform-specific native binary that would otherwise have to be right
for every machine that builds the frontend. The derivatives are committed, so this runs once
per artwork change, not per build.

## How each shell works

**Electron** (`desktop/main.js`) serves the bundle over a custom `app://` protocol rather
than `file://`, because the SPA routes with the History API and `file://` has no concept of a
path that isn't a file — a reload on `/servers/3/channels/9` would 404. The real origin also
means storage, WebSockets and WebRTC behave exactly as in a browser. The main process grants
microphone/camera permissions (Electron denies them by default, which would break every voice
channel silently) and answers `getDisplayMedia` with the primary screen.

**Capacitor** (`mobile/capacitor.config.ts`) serves the bundle from its own embedded server on
an `https://` origin, so the same three things hold for free. Microphone and camera are
declared in `AndroidManifest.xml` and `Info.plist`; the runtime prompt appears the first time
`getUserMedia` runs. Screen sharing is hidden on phones — no mobile WebView implements
`getDisplayMedia` — though watching someone else's share works.

## What the shared bundle does differently when native

- **Auth token** — a cookie on the web, `localStorage` in the shells, because both load from a
  synthetic origin whose cookie jar the OS may clear between launches. One `useAuthToken()`
  hides the difference (`frontend/app/composables/useAuthToken.ts`).
- **Platform** — `usePlatform()` detects the shell structurally (`window.sideChatDesktop`,
  `Capacitor.isNativePlatform()`), never from the user agent.
- **Layout** — below `md` the sidebar becomes a drawer over the conversation, opened from the
  channel header (`useNavDrawer()`). This applies to a narrow browser window too.
- **Floating windows** are desktop-only; the phone build has no shelf.

## Known gaps

- Push notifications aren't wired up — the apps only notify while running.
- Electron's screen share grabs the primary display without a source picker.
- Deep links (`side-chat://` / universal links) for invites aren't registered yet; an invite
  link opens the web app.
- The native projects (`mobile/android`, `mobile/ios`) are committed; app icons and splash
  screens are still Capacitor's defaults.