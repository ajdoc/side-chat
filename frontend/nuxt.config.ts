import tailwindcss from '@tailwindcss/vite'

// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },

  // Pure client-rendered SPA: the app lives entirely behind auth with no SEO
  // surface, so there's nothing to server-render. Lets `npm run generate` emit
  // a static bundle for Render's Static Site hosting.
  ssr: false,

  modules: ['shadcn-nuxt'],

  app: {
    head: {
      // `viewport-fit=cover` is what lets the page reach under a phone's status bar and
      // gesture area — and, crucially, what makes `env(safe-area-inset-*)` report anything
      // other than 0. The insets themselves are applied by `.safe-inset` (tailwind.css);
      // without this line those paddings would silently collapse and the header would sit
      // under the notification tray.
      viewport: 'width=device-width, initial-scale=1, viewport-fit=cover',

      // Every one of these is generated from public/brand/icon-source.png — see the icon
      // section in APPS.md before replacing any of them by hand.
      link: [
        { rel: 'icon', href: '/favicon.ico', sizes: '48x48' },
        { rel: 'icon', type: 'image/png', href: '/icon-192.png', sizes: '192x192' },
        { rel: 'icon', type: 'image/png', href: '/icon-512.png', sizes: '512x512' },
        { rel: 'apple-touch-icon', href: '/apple-touch-icon.png', sizes: '180x180' },
      ],
    },
  },

  css: [
    '~/assets/css/tailwind.css',
    'vue-virtual-scroller/dist/vue-virtual-scroller.css',
  ],

  vite: {
    plugins: [tailwindcss()],
  },

  // shadcn-vue: components are generated into app/components/ui via `npx shadcn-vue@latest add <name>`.
  shadcn: {
    prefix: '',
    componentDir: '~/components/ui',
  },

  // Base URL of the Laravel API + Reverb, overridable via NUXT_PUBLIC_* env vars in docker-compose.
  runtimeConfig: {
    public: {
      apiBase: 'http://localhost:8002',
      reverbKey: '',
      reverbHost: 'localhost',
      reverbPort: '8080',
      reverbScheme: 'http',
      // The largest attachment the chunked path will take, in MB. Mirrors the API's
      // MAX_UPLOAD_MB (config/uploads.php) — a mismatch just means the browser lets through
      // a file the server then refuses.
      maxUploadMb: '2048',
    },
  },
})
