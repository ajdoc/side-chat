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

The apps are **chat and voice only**. Everything else Side Chat has grown — Side Spaces, the
Side Desk, server administration — is present in the bundle but gated off at navigation time
by [`middleware/native-scope.global.ts`](frontend/app/middleware/native-scope.global.ts),
which holds the allowlist of routes. The sidebar and channel header hide the buttons that
lead to blocked places; a Side Space channel opens as its chat alone.

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

A packaged app is not served from your web domain. Electron loads it from `app://side-chat`
and Capacitor from `https://localhost` (Android) or `capacitor://localhost` (iOS), and those
are the `Origin` headers the API sees. Until they're allowed, the browser engine blocks every
request before it leaves — which surfaces as "Unable to sign in. Please try again.", because
the login POST never reaches Laravel at all.

Set `CORS_ALLOWED_ORIGINS` on the API (see [config/cors.php](backend/config/cors.php)) to a
comma-separated list including all of them:

```
CORS_ALLOWED_ORIGINS=https://your-web-app.example.com,app://side-chat,https://localhost,capacitor://localhost
```

To check a deployed API from anywhere:

```bash
curl -i -X OPTIONS https://api.example.com/api/auth/login \
  -H 'Origin: app://side-chat' -H 'Access-Control-Request-Method: POST'
# access-control-allow-origin must come back as app://side-chat
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

It needs one thing from Windows itself: **Developer Mode on** (Settings → System → For
developers). electron-builder's signing toolchain unpacks an archive containing macOS
symlinks, and creating a symlink needs a privilege ordinary accounts lack — without it the
build dies on `Cannot create symbolic link: A required privilege is not held by the client`.
Running the build from an Administrator shell works too.

### Desktop development loop

Point the Electron window at the live Nuxt dev server instead of a packaged bundle — hot
reload, with the real native shell around it:

```bash
make up                      # frontend dev server on :3000
cd desktop
npm install
npm run dev
```

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