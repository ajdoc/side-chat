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
