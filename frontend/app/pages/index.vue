<script setup lang="ts">
import { Compass, Plus } from 'lucide-vue-next'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'

definePageMeta({ middleware: 'auth' })

const { servers, fetchServers } = useServers()
const { conversations, fetchConversations } = useConversations()
const { skipped } = useOnboarding()

// Four destinations, and which one applies isn't known until the lists land: straight into
// your first server, into your chats if you have those but no servers, the
// create-your-first-server flow, or — for someone who has opted out of that — the empty
// state below.
const ready = ref(false)
const invite = ref('')
const inviteError = ref('')

/**
 * Take the code out of whatever they pasted.
 *
 * People paste the whole invite URL far more often than the bare code, and refusing that
 * would mean refusing the exact thing we handed them.
 */
function inviteCode(input: string) {
  const trimmed = input.trim()
  const fromUrl = trimmed.match(/\/invite\/([\w-]+)\/?$/)
  if (fromUrl) return fromUrl[1]

  return /^[\w-]+$/.test(trimmed) ? trimmed : null
}

function openInvite() {
  const code = inviteCode(invite.value)
  if (!code) {
    inviteError.value = 'That doesn’t look like an invite link.'
    return
  }
  navigateTo(`/invite/${code}`)
}

onMounted(async () => {
  await Promise.all([fetchServers(), fetchConversations()])

  if (servers.value.length) {
    await navigateTo(`/servers/${servers.value[0]!.id}`)
    return
  }
  // No servers but somebody has DM'd you: your chats are the app, as far as you're
  // concerned. Sending you to "create a server" would be answering a question you didn't
  // ask.
  if (conversations.value.length) {
    await navigateTo('/chats')
    return
  }
  if (!skipped.value) {
    await navigateTo('/onboarding')
    return
  }

  ready.value = true
})
</script>

<template>
  <div v-if="!ready" class="grid min-h-screen place-items-center text-muted-foreground">
    Loading…
  </div>

  <div v-else class="grid min-h-screen place-items-center p-6">
    <div class="w-full max-w-md space-y-6 text-center">
      <div class="space-y-2">
        <span class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-muted text-muted-foreground">
          <Compass class="h-6 w-6" />
        </span>
        <h1 class="text-2xl font-semibold">You’re not in any servers yet</h1>
        <p class="text-muted-foreground">
          Open an invite link someone sent you, or start a server of your own.
        </p>
      </div>

      <form class="space-y-2" @submit.prevent="openInvite">
        <div class="flex gap-2">
          <Input
            v-model="invite"
            placeholder="Paste an invite link"
            class="flex-1"
            @input="inviteError = ''"
          />
          <Button type="submit" variant="outline">Join</Button>
        </div>
        <p v-if="inviteError" class="text-left text-sm text-destructive">{{ inviteError }}</p>
      </form>

      <div class="flex items-center gap-3 text-xs uppercase tracking-wide text-muted-foreground">
        <span class="h-px flex-1 bg-border" /> or <span class="h-px flex-1 bg-border" />
      </div>

      <Button as-child class="w-full gap-2">
        <NuxtLink to="/onboarding">
          <Plus class="h-4 w-4" /> Create a server
        </NuxtLink>
      </Button>
    </div>
  </div>
</template>
