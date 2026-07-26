/**
 * What the first native release is allowed to show.
 *
 * The app builds are chat, voice, the Side Desk, and the server administration that goes with
 * them. What's still withheld is the Side Space — a walkable room needs a keyboard and a window
 * — and it ships in the same bundle as everything else (there is one bundle), so the boundary
 * has to be drawn at navigation time rather than at build time.
 *
 * The Side Desk used to be turned away here too, by stripping its `?desk=` flag. That's gone:
 * its board now pans and zooms under a finger (see Whiteboard), which was the thing that made
 * it unusable on a phone, so there is nothing left to withhold.
 *
 * Remove this file and the `isNative` guards it names to lift the rest of the gate.
 */

/** Route paths the native shells may reach, as patterns against `to.path`. */
const ALLOWED = [
  /^\/$/,
  /^\/login$/,
  /^\/register$/,
  /^\/onboarding$/,
  /^\/auth\/callback$/,
  /^\/invite\/[^/]+$/,
  /^\/chats$/,
  /^\/chats\/\d+$/,
  /^\/servers\/\d+$/,
  /^\/servers\/\d+\/channels\/\d+$/,
  // Running a server is allowed on the phone. It has to be, in a set: "Add a server" leads
  // straight into creating its first channel, and an invite link is useless if nobody on a
  // phone can approve the request it produces. Withholding any one of these left the other
  // two as doors onto a redirect.
  /^\/servers\/\d+\/channels\/new$/,
  /^\/servers\/\d+\/requests$/,
]

export default defineNuxtRouteMiddleware((to) => {
  const { isNative } = usePlatform()
  if (!isNative.value) return

  if (!ALLOWED.some(pattern => pattern.test(to.path))) {
    return navigateTo('/')
  }
})
