<script setup lang="ts">
import { CheckCircle2, Clock, Loader2, Users } from 'lucide-vue-next'
import type { InvitePreview } from '~/types'
import { Button } from '~/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '~/components/ui/card'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const { preview, requestToJoin } = useInvite()

const code = computed(() => String(route.params.code))
const invite = ref<InvitePreview | null>(null)
const error = ref('')
const loading = ref(true)
const submitting = ref(false)

function initials(name: string) {
  return name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase()
}

async function join() {
  submitting.value = true
  try {
    invite.value = await requestToJoin(code.value)
  } catch (e: any) {
    error.value = e?.data?.message ?? 'Could not send your request. Please try again.'
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  try {
    invite.value = await preview(code.value)
    // Already in? Just go straight to the server.
    if (invite.value.status === 'member') {
      await navigateTo(`/servers/${invite.value.server.id}`)
    }
  } catch {
    error.value = 'This invite link is not valid.'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="grid min-h-screen place-items-center p-6">
    <Card class="w-full max-w-md">
      <template v-if="loading">
        <CardContent class="flex justify-center py-10">
          <Loader2 class="h-5 w-5 animate-spin text-muted-foreground" />
        </CardContent>
      </template>

      <template v-else-if="error">
        <CardHeader class="text-center">
          <CardTitle class="text-2xl">Invalid invite</CardTitle>
          <CardDescription>{{ error }}</CardDescription>
        </CardHeader>
        <CardContent>
          <Button as-child variant="outline" class="w-full">
            <NuxtLink to="/">Back to side-chat</NuxtLink>
          </Button>
        </CardContent>
      </template>

      <template v-else-if="invite">
        <CardHeader class="items-center text-center">
          <div class="mb-2 grid h-16 w-16 place-items-center rounded-2xl bg-primary text-lg font-semibold text-primary-foreground">
            {{ initials(invite.server.name) }}
          </div>
          <CardTitle class="text-2xl">{{ invite.server.name }}</CardTitle>
          <CardDescription class="flex items-center gap-1.5">
            <Users class="h-3.5 w-3.5" />
            {{ invite.server.members_count }}
            {{ invite.server.members_count === 1 ? 'member' : 'members' }}
          </CardDescription>
        </CardHeader>

        <CardContent class="space-y-4">
          <!-- Awaiting approval -->
          <div
            v-if="invite.status === 'pending'"
            class="flex items-start gap-3 rounded-lg border bg-muted/40 p-3 text-sm"
          >
            <Clock class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
            <div>
              <p class="font-medium">Request sent</p>
              <p class="text-muted-foreground">
                A member of this server needs to approve you before you can join.
              </p>
            </div>
          </div>

          <!-- Already a member (redirecting) -->
          <div
            v-else-if="invite.status === 'member'"
            class="flex items-center gap-2 rounded-lg border bg-muted/40 p-3 text-sm"
          >
            <CheckCircle2 class="h-4 w-4 text-green-600 dark:text-green-400" />
            You're already a member — taking you there…
          </div>

          <!-- Can request -->
          <template v-else>
            <p class="text-center text-sm text-muted-foreground">
              You've been invited to join. A member will need to approve your request.
            </p>
            <Button class="w-full" :disabled="submitting" @click="join">
              {{ submitting ? 'Sending request…' : 'Request to join' }}
            </Button>
          </template>

          <Button as-child variant="ghost" class="w-full">
            <NuxtLink to="/">Back to side-chat</NuxtLink>
          </Button>
        </CardContent>
      </template>
    </Card>
  </div>
</template>
