# Screen sharing on iOS

Android's half of this shipped; iOS's has not, and this is why, and what it takes.

## What already works

Nothing on the iOS side is *broken*. The app asks `useDisplayCapture` whether this device can
capture a screen; on iOS the `ScreenCapture` plugin isn't registered, the answer is no, and the
share buttons don't appear. Watching somebody else's shared screen works on iOS exactly as it
always has — that half never needed a capture.

## Why it isn't done

Everywhere else, capturing a screen is one API call. On iOS, sharing anything beyond the app's
own window requires a **Broadcast Upload Extension**: a second process, with its own target,
bundle id, provisioning profile and App Group, that ReplayKit launches and hands sample buffers
to. The app can't start it; the user starts it from `RPSystemBroadcastPickerView`.

That means the work is mostly *project* work, not code:

1. A new **Broadcast Upload Extension** target in `App.xcodeproj`.
2. An **App Group** (`group.chat.side.app`) entitlement on both the app and the extension, and
   matching provisioning profiles for both.
3. A transport between the two processes. The extension gets `CMSampleBuffer`s; the WebView is
   in the *app* process. The Android design here — encode, push over a loopback socket, decode
   into a canvas, `captureStream()` — carries over unchanged except that the socket has to be a
   Unix domain socket in the shared group container rather than a TCP one, because the extension
   is memory-capped (50MB) and sandboxed away from the network.

Steps 1 and 2 can only be done in Xcode. They can't be scripted into `project.pbxproj` with any
confidence, and `npx cap sync` rewrites parts of the iOS project anyway — so this has to be a
deliberate, checked-in Xcode change rather than something generated.

## What to build, when you do

The web side is already finished and platform-agnostic. Implement a Capacitor plugin named
`ScreenCapture` with exactly this contract (see `frontend/app/composables/useDisplayCapture.ts`,
which is the only consumer):

```ts
isSupported(): Promise<{ supported: boolean, reason?: string }>
start({ height, frameRate, audio }): Promise<{
  endpoint: string   // ws:// or ws+unix:// the page can read frames from
  width: number
  height: number
  frameRate: number
  audio: boolean     // whether system audio actually came with it
}>
stop(): Promise<void>
// plus a 'screenCaptureEnded' event when the broadcast stops outside the app
```

Frames on the wire are one type byte then the payload — `1` for a JPEG image, `2` for 48kHz
signed 16-bit interleaved stereo PCM. Match that and nothing in the app has to change: the
stream joins the call through the same pre-negotiated screen slot every other client uses.

`chat.side.app.screencapture` on Android is the reference implementation.
