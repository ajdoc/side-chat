<script setup lang="ts">
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import { Label } from '~/components/ui/label'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '~/components/ui/card'

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
  <div class="grid min-h-screen place-items-center p-6">
    <Card class="w-full max-w-sm">
      <CardHeader class="text-center">
        <CardTitle class="text-2xl">Create your account</CardTitle>
        <CardDescription>Join side-chat</CardDescription>
      </CardHeader>

      <CardContent class="space-y-4">
        <SocialButtons />

        <div class="relative py-1">
          <div class="absolute inset-0 flex items-center">
            <span class="w-full border-t" />
          </div>
          <div class="relative flex justify-center text-xs uppercase">
            <span class="bg-card px-2 text-muted-foreground">or</span>
          </div>
        </div>

        <form class="space-y-4" @submit.prevent="onSubmit">
          <div class="space-y-2">
            <Label for="name">Name</Label>
            <Input id="name" v-model="form.name" type="text" required autocomplete="name" />
          </div>

          <div class="space-y-2">
            <Label for="email">Email</Label>
            <Input id="email" v-model="form.email" type="email" placeholder="you@example.com" required autocomplete="email" />
          </div>

          <div class="space-y-2">
            <Label for="password">Password</Label>
            <Input id="password" v-model="form.password" type="password" required autocomplete="new-password" />
          </div>

          <div class="space-y-2">
            <Label for="password_confirmation">Confirm password</Label>
            <Input id="password_confirmation" v-model="form.password_confirmation" type="password" required autocomplete="new-password" />
          </div>

          <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

          <Button type="submit" class="w-full" :disabled="loading">
            {{ loading ? 'Creating account…' : 'Create account' }}
          </Button>
        </form>

        <p class="text-center text-sm text-muted-foreground">
          Already have an account?
          <NuxtLink :to="{ path: '/login', query: route.query }" class="font-medium text-foreground underline underline-offset-4">Sign in</NuxtLink>
        </p>
      </CardContent>
    </Card>
  </div>
</template>
