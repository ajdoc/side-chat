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

definePageMeta({ middleware: 'auth' })

const { createServer } = useServers()
const { skip, reset } = useOnboarding()

const name = ref('')
const error = ref('')
const loading = ref(false)

async function submit() {
  if (!name.value.trim()) return
  loading.value = true
  error.value = ''
  try {
    const server = await createServer(name.value.trim())
    reset() // they're in — a past "skip" shouldn't shape where they land next time
    // Guide the user straight into creating their first channel.
    await navigateTo(`/servers/${server.id}/channels/new`)
  } catch (e: any) {
    error.value = e?.data?.message ?? 'Could not create the server.'
  } finally {
    loading.value = false
  }
}

// Not everyone arrives wanting to run a community — plenty are here because someone sent
// them an invite. Remembered, so `/` stops redirecting them back into this page.
function skipOnboarding() {
  skip()
  navigateTo('/')
}
</script>

<template>
  <div class="grid min-h-screen place-items-center p-6">
    <Card class="w-full max-w-md">
      <CardHeader class="text-center">
        <CardTitle class="text-2xl">Create your server</CardTitle>
        <CardDescription>
          Your server is where you and your community hang out. Give it a name to get started.
        </CardDescription>
      </CardHeader>

      <CardContent>
        <form class="space-y-4" @submit.prevent="submit">
          <div class="space-y-2">
            <Label for="name">Server name</Label>
            <Input id="name" v-model="name" placeholder="e.g. Design Team" required autofocus />
          </div>

          <p v-if="error" class="text-sm text-destructive">{{ error }}</p>

          <Button type="submit" class="w-full" :disabled="loading">
            {{ loading ? 'Creating…' : 'Create server' }}
          </Button>

          <p class="text-center text-sm text-muted-foreground">
            Joining someone else’s server instead?
            <button
              type="button"
              class="font-medium text-primary underline-offset-4 hover:underline"
              :disabled="loading"
              @click="skipOnboarding"
            >
              Skip for now
            </button>
          </p>
        </form>
      </CardContent>
    </Card>
  </div>
</template>
