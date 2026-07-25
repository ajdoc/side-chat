import type { CapacitorConfig } from '@capacitor/cli'

/**
 * The iOS/Android shell around the Nuxt bundle.
 *
 * There is no mobile-specific build of the app: `npm run web` runs `nuxt generate` and copies
 * `.output/public` into `www/`, which Capacitor serves from its embedded server. Because that
 * server speaks http(s) on a real origin, the SPA's history routing, WebSockets and WebRTC all
 * behave exactly as they do in a browser — which is why the phone app needed no rewrite.
 *
 * The API it talks to is baked in at generate time via `NUXT_PUBLIC_API_BASE` and friends;
 * `localhost` means the phone itself, so a device build must be generated against a LAN
 * address or a deployed API. See mobile/README.md.
 */
const config: CapacitorConfig = {
  appId: 'chat.side.app',
  appName: 'Side Chat',
  webDir: 'www',
  android: {
    // Voice runs over WebRTC and the API may be plain http on a LAN during development.
    // Neither is a concern for a release build pointed at an https API.
    allowMixedContent: true,
  },
  server: {
    androidScheme: 'https',
    cleartext: true,
  },
  plugins: {
    Keyboard: {
      // The composer must ride above the keyboard rather than be covered by it.
      resize: 'body' as any,
    },
  },
}

export default config
