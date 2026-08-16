<script setup lang="ts">
import { AlertCircle, Eye, EyeOff, LoaderCircle } from 'lucide-vue-next'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import { Label } from '~/components/ui/label'

definePageMeta({ middleware: 'guest' })

const route = useRoute()
const { login } = useAuth()

const form = reactive({ email: '', password: '' })

/**
 * Blocked accounts land here with the admin's reason already in hand.
 *
 * Two roads to the same sentence. Signing in when you're blocked fails validation with it
 * (LoginUserAction), and being blocked *while* signed in bounces you here with `?blocked=`
 * (useApi). Both end up in this one slot, so there's a single place that explains it and a
 * single thing to read.
 */
const error = ref(typeof route.query.blocked === 'string' ? route.query.blocked : '')
const loading = ref(false)
const showPassword = ref(false)

async function onSubmit() {
  error.value = ''
  loading.value = true
  try {
    await login(form)
    await navigateTo(typeof route.query.redirect === 'string' ? route.query.redirect : '/')
  } catch (e: any) {
    error.value = e?.data?.message ?? 'Unable to sign in. Please try again.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <AuthShell title="Welcome back" subtitle="Sign in and pick up where your crew left off.">
    <div class="space-y-5">
      <SocialButtons />

      <div class="relative">
        <span class="absolute inset-0 flex items-center"><span class="w-full border-t" /></span>
        <span class="relative flex justify-center">
          <span class="bg-background px-3 text-[11px] uppercase tracking-widest text-muted-foreground">or</span>
        </span>
      </div>

      <form class="space-y-4" @submit.prevent="onSubmit">
        <div class="space-y-2">
          <Label for="email">Email</Label>
          <Input
            id="email"
            v-model="form.email"
            type="email"
            class="h-11"
            placeholder="you@example.com"
            required
            autocomplete="email"
          />
        </div>

        <div class="space-y-2">
          <Label for="password">Password</Label>
          <!-- The reveal toggle sits inside the field, so the input keeps its own focus ring. -->
          <div class="relative">
            <Input
              id="password"
              v-model="form.password"
              :type="showPassword ? 'text' : 'password'"
              class="h-11 pr-11"
              placeholder="••••••••"
              required
              autocomplete="current-password"
            />
            <button
              type="button"
              class="absolute inset-y-0 right-0 grid w-11 place-items-center text-muted-foreground transition-colors hover:text-foreground"
              :aria-label="showPassword ? 'Hide password' : 'Show password'"
              tabindex="-1"
              @click="showPassword = !showPassword"
            >
              <component :is="showPassword ? EyeOff : Eye" class="h-4 w-4" />
            </button>
          </div>
        </div>

        <p
          v-if="error"
          class="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2.5 text-sm text-destructive"
          role="alert"
        >
          <AlertCircle class="mt-px h-4 w-4 flex-none" />
          <span>{{ error }}</span>
        </p>

        <Button type="submit" class="h-11 w-full text-[0.95rem] font-medium" :disabled="loading">
          <LoaderCircle v-if="loading" class="mr-2 h-4 w-4 animate-spin" />
          {{ loading ? 'Signing in…' : 'Sign in' }}
        </Button>
      </form>

      <p class="text-center text-sm text-muted-foreground">
        New here?
        <NuxtLink
          :to="{ path: '/register', query: route.query }"
          class="font-medium text-primary underline-offset-4 hover:underline"
        >Create an account</NuxtLink>
      </p>
    </div>
  </AuthShell>
</template>
