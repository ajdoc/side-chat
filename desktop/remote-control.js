// Remote control — the half that actually moves the mouse.
//
// A browser tab cannot type into the machine it is running on, and that is not a gap to work
// around: it's the sandbox doing its job. So the *protocol* (ask, approve, revoke) lives in the
// web app and works everywhere, while the injection below only exists in the desktop shell,
// and only when a native input backend is installed.
//
// The backend is an OPTIONAL dependency on purpose. `@nut-tree-fork/nut-js` pulls a native
// module, and a native module that fails to build must not take the whole desktop app down with
// it — a shell that can't inject input is still a perfectly good chat client. So the require is
// lazy and guarded, `isAvailable()` reports honestly, and the web app greys the feature out
// rather than offering something that will silently do nothing.
//
// Install it with:  npm i @nut-tree-fork/nut-js  (then `npx electron-rebuild` if needed)

const { screen } = require('electron')

/** The backend, once resolved. `undefined` = not tried yet, `null` = tried and unavailable. */
let nut

function backend() {
  if (nut !== undefined) return nut
  try {
    // eslint-disable-next-line global-require
    nut = require('@nut-tree-fork/nut-js')
    // Teleport the cursor rather than animating a path to it — this is a remote pointer being
    // mirrored, not a macro being played back, and any easing shows up as lag.
    nut.mouse.config.mouseSpeed = 99999
    nut.keyboard.config.autoDelayMs = 0
  } catch (err) {
    console.warn('[remote-control] native input backend unavailable:', err.message)
    nut = null
  }
  return nut
}

function isAvailable() {
  return backend() !== null
}

/**
 * Which display a `desktopCapturer` source is showing.
 *
 * Screen sources are `screen:<display_id>:0`, so the id in the middle matches a display from
 * Electron's screen module and gives us the bounds to map into. A *window* source carries no
 * such thing — we'd need the window's live position and size on screen, which Electron doesn't
 * expose for windows we don't own. Rather than guess (and click somewhere the controller never
 * pointed), window shares simply don't support control; see `boundsFor` returning null and
 * `capabilities()` reporting it.
 */
function boundsFor(sourceId) {
  if (typeof sourceId !== 'string' || !sourceId.startsWith('screen:')) return null

  const displayId = sourceId.split(':')[1]
  const displays = screen.getAllDisplays()
  const match = displays.find(d => String(d.id) === displayId)

  // `screen:0:0` is also how some platforms name the primary display when the ids don't line
  // up, so fall back to the primary rather than refusing outright.
  return (match ?? screen.getPrimaryDisplay()).bounds
}

/**
 * Normalised (0..1) point on the shared surface → a point in the OS's coordinate space.
 *
 * The controller sends fractions, never pixels. Their video of the share is whatever size their
 * window happens to be, and the sharer's display is a different size again — sending pixels
 * would mean every resize on either end silently mis-aims the cursor.
 */
function toScreenPoint(bounds, nx, ny) {
  const clamp = v => (v < 0 ? 0 : v > 1 ? 1 : v)
  return {
    x: Math.round(bounds.x + clamp(nx) * (bounds.width - 1)),
    y: Math.round(bounds.y + clamp(ny) * (bounds.height - 1)),
  }
}

const BUTTONS = () => {
  const { Button } = backend()
  return { 0: Button.LEFT, 1: Button.MIDDLE, 2: Button.RIGHT }
}

/**
 * `KeyboardEvent.code` → nut-js `Key`.
 *
 * Keyed on `code` (physical key) rather than `key` (the character produced), because the
 * backend presses physical keys: the sharer's own keyboard layout then decides what character
 * comes out, which is the behaviour you want. Sending `key` would mean a controller on AZERTY
 * typing 'a' produces 'q' on a QWERTY host.
 */
function keyMap() {
  const { Key } = backend()
  const map = {
    Escape: Key.Escape, Tab: Key.Tab, CapsLock: Key.CapsLock, Space: Key.Space,
    Enter: Key.Enter, NumpadEnter: Key.Enter, Backspace: Key.Backspace, Delete: Key.Delete,
    Insert: Key.Insert, Home: Key.Home, End: Key.End, PageUp: Key.PageUp, PageDown: Key.PageDown,
    ArrowUp: Key.Up, ArrowDown: Key.Down, ArrowLeft: Key.Left, ArrowRight: Key.Right,
    ShiftLeft: Key.LeftShift, ShiftRight: Key.RightShift,
    ControlLeft: Key.LeftControl, ControlRight: Key.RightControl,
    AltLeft: Key.LeftAlt, AltRight: Key.RightAlt,
    MetaLeft: Key.LeftSuper, MetaRight: Key.RightSuper,
    Minus: Key.Minus, Equal: Key.Equal, BracketLeft: Key.LeftBracket, BracketRight: Key.RightBracket,
    Backslash: Key.Backslash, Semicolon: Key.Semicolon, Quote: Key.Quote,
    Backquote: Key.Grave, Comma: Key.Comma, Period: Key.Period, Slash: Key.Slash,
  }

  for (const c of 'ABCDEFGHIJKLMNOPQRSTUVWXYZ') map[`Key${c}`] = Key[c]
  for (let n = 0; n <= 9; n++) {
    map[`Digit${n}`] = Key[`Num${n}`]
    map[`Numpad${n}`] = Key[`NumPad${n}`]
  }
  for (let n = 1; n <= 12; n++) map[`F${n}`] = Key[`F${n}`]

  return map
}

let keys

/**
 * What the controller is currently holding down.
 *
 * Tracked because control can end at any moment — the sharer hits "stop", the call drops, the
 * controller's tab closes — and whatever was mid-press at that instant never gets its `up`.
 * A stuck Ctrl or a stuck left button on somebody else's machine is the worst failure this
 * feature has, so `releaseAll` lifts them on the way out. See the IPC teardown in main.js.
 */
const held = { buttons: new Set(), keys: new Set() }

/**
 * Apply one input event from the controller.
 *
 * Returns quietly on anything it can't handle — an unmapped key, a window share, a backend that
 * isn't installed. A dropped event is always better than a wrong one when the thing on the other
 * end is somebody's actual desktop.
 */
async function inject(event, sourceId) {
  const n = backend()
  if (!n || !event) return

  const bounds = boundsFor(sourceId)
  if (!bounds) return

  const { mouse, keyboard, Point } = n

  switch (event.t) {
    case 'move': {
      const p = toScreenPoint(bounds, event.x, event.y)
      await mouse.setPosition(new Point(p.x, p.y))
      break
    }

    case 'down':
    case 'up': {
      // Move first: the controller's pointer position and their click arrive as separate
      // events, and a click that lands before the move would fire at the previous spot.
      const p = toScreenPoint(bounds, event.x, event.y)
      await mouse.setPosition(new Point(p.x, p.y))
      const button = BUTTONS()[event.b ?? 0]
      if (button === undefined) break
      if (event.t === 'down') {
        held.buttons.add(button)
        await mouse.pressButton(button)
      } else {
        held.buttons.delete(button)
        await mouse.releaseButton(button)
      }
      break
    }

    case 'wheel': {
      // nut-js scrolls in ticks by direction; the browser gives signed pixel deltas.
      const ticks = d => Math.max(1, Math.round(Math.abs(d) / 100))
      if (event.dy) await (event.dy > 0 ? mouse.scrollDown(ticks(event.dy)) : mouse.scrollUp(ticks(event.dy)))
      if (event.dx) await (event.dx > 0 ? mouse.scrollRight(ticks(event.dx)) : mouse.scrollLeft(ticks(event.dx)))
      break
    }

    case 'key-down':
    case 'key-up': {
      keys ??= keyMap()
      const key = keys[event.code]
      if (key === undefined) break
      if (event.t === 'key-down') {
        held.keys.add(key)
        await keyboard.pressKey(key)
      } else {
        held.keys.delete(key)
        await keyboard.releaseKey(key)
      }
      break
    }
  }
}

/**
 * Lift everything the controller was holding. Called whenever a control session ends, however
 * it ends — see `held` for why this isn't optional. Best-effort and never throws: it runs on
 * teardown paths where there is nobody left to report an error to.
 */
async function releaseAll() {
  const n = backend()
  if (!n) return

  for (const button of held.buttons) {
    try { await n.mouse.releaseButton(button) } catch { /* already up, or backend gone */ }
  }
  for (const key of held.keys) {
    try { await n.keyboard.releaseKey(key) } catch { /* as above */ }
  }
  held.buttons.clear()
  held.keys.clear()
}

/**
 * What this machine can actually offer, for the UI to be honest about before anyone asks.
 * `screenOnly` is why a window share can be granted control and still do nothing — so the app
 * can say "share a whole screen to allow control" instead.
 */
function capabilities() {
  return { available: isAvailable(), screenOnly: true }
}

module.exports = { capabilities, isAvailable, inject, releaseAll }
