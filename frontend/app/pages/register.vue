<script setup lang="ts">
import { AlertCircle, Eye, EyeOff, LoaderCircle } from 'lucide-vue-next'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import { Label } from '~/components/ui/label'

definePageMeta({ middleware: 'guest' })

const route = useRoute()
const { register } = useAuth()

const form = reactive({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
})
const error = ref('')
const loading = ref(false)
const showPassword = ref(false)

async function onSubmit() {
  error.value = ''
  loading.value = true
  try {
    await register(form)
    await navigateTo(typeof route.query.redirect === 'string' ? route.query.redirect : '/')
  } catch (e: any) {
    error.value = e?.data?.message ?? 'Unable to create your account. Please try again.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <AuthShell title="Create your account" subtitle="Takes about thirty seconds. Bring your friends.">
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
          <Label for="name">Display name</Label>
          <Input id="name" v-model="form.name" type="text" class="h-11" placeholder="What should we call you?" required autocomplete="name" />
        </div>

        <div class="space-y-2">
          <Label for="email">Email</Label>
          <Input id="email" v-model="form.email" type="email" class="h-11" placeholder="you@example.com" required autocomplete="email" />
        </div>

        <div class="space-y-2">
          <Label for="password">Password</Label>
          <!-- One toggle drives both password fields — they're always typed together. -->
          <div class="relative">
            <Input
              id="password"
              v-model="form.password"
              :type="showPassword ? 'text' : 'password'"
              class="h-11 pr-11"
              placeholder="••••••••"
              required
              autocomplete="new-password"
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

        <div class="space-y-2">
          <Label for="password_confirmation">Confirm password</Label>
          <Input
            id="password_confirmation"
            v-model="form.password_confirmation"
            :type="showPassword ? 'text' : 'password'"
            class="h-11"
            placeholder="••••••••"
            required
            autocomplete="new-password"
          />
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
          {{ loading ? 'Creating account…' : 'Create account' }}
        </Button>
      </form>

      <p class="text-center text-sm text-muted-foreground">
        Already have an account?
        <NuxtLink
          :to="{ path: '/login', query: route.query }"
          class="font-medium text-primary underline-offset-4 hover:underline"
        >Sign in</NuxtLink>
      </p>
    </div>
  </AuthShell>
</template>
