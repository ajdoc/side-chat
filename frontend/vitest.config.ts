import { defineConfig } from 'vitest/config'

/**
 * Unit tests, for the parts of the app that are plain TypeScript.
 *
 * Deliberately not `@nuxt/test-utils`: nothing here needs a Nuxt runtime, an auto-import or
 * a mounted component. The crypto layer is pure functions over bytes, which is exactly what
 * makes it testable and exactly why it is worth testing — a mistake in it doesn't throw, it
 * quietly produces messages nobody can read.
 *
 * The environment is node, which has WebCrypto on `globalThis` from 18 onwards. Same API the
 * browser gives us, so the code under test is the code that ships.
 */
export default defineConfig({
  test: {
    environment: 'node',
    include: ['app/**/*.spec.ts'],
  },
})
