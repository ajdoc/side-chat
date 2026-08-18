<script setup lang="ts">
import { CalendarClock, Loader2, LogIn, Map as MapIcon, Mic } from 'lucide-vue-next'
import type { MeetingPreview } from '~/types'
import { Button } from '~/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '~/components/ui/card'

/**
 * What a meeting link opens.
 *
 * ## Signed out
 *
 * **No auth middleware.** This is the one page in the app a stranger is meant to reach, so it
 * loads for anybody and asks the API what the meeting allows:
 *
 *  - a **guest** meeting shows a name field — type it, and you're in, with a real session minted
 *    for a throwaway account (see JoinMeetingAsGuestAction);
 *  - an **account** meeting offers sign-in, with `?redirect=` pointing back here so you land on
 *    this page again afterwards, exactly as an invite link behaves;
 *  - a **members** meeting says so, rather than offering a door that would refuse you.
 *
 * Which of the three is the server's answer, never this page's guess — the level belongs to
 * whoever made the meeting.
 *
 * ## Signed in
 *
 * The preview is deliberately thin — a title, who called it, when, and whether *you* can get in.
 * Somebody deciding whether to follow a link needs to know what it is; they don't need the room's
 * id or its members, and a page that leaked those would make an unguessable token the only thing
 * protecting them.
 *
 * Joining is a button rather than something that happens on arrival. Following a link should not
 * silently add you to a group chat, and in a Side Space or a call it should certainly not put you
 * in a room before you've looked at what it is.
 */
// Deliberately no `middleware: 'auth'` — see above.
const route = useRoute()
const { preview, join, joinAsGuest, roomPath } = useMeetings()
const { isLoggedIn, setSession } = useAuth()

const guestName = ref('')

const token = computed(() => String(route.params.token))
const meeting = ref<MeetingPreview | null>(null)
const loading = ref(true)
const joining = ref(false)
const error = ref('')

const when = computed(() => {
  const at = meeting.value?.scheduled_at
  if (!at) return null
  return new Date(at).toLocaleString([], { dateStyle: 'full', timeStyle: 'short' })
})

async function enter() {
  joining.value = true
  error.value = ''
  try {
    const joined = await join(token.value)
    // Straight into the call: pressing "join the meeting" and then having to press "join call"
    // is two doors for one intention, and this click is the user gesture the browser wants
    // before it will ask for a microphone.
    await navigateTo(roomPath(joined, { call: true }))
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'You couldn’t be let into this meeting.'
  }
  finally {
    joining.value = false
  }
}

/**
 * Walk in with no account.
 *
 * The session that comes back is adopted the way a sign-in's is — token *and* user together, via
 * `setSession`. Fetching the user instead would 401: `useApi` holds its own cookie ref, captured
 * before the token existed, so a request in this same tick carries no Authorization header and
 * the failure clears the very token it was handed. That bug is why this page used to end at the
 * login form.
 *
 * `nextTick` before navigating lets the cookie ref reach `document.cookie`, so the next page's
 * middleware — which builds fresh refs from it — sees the session. The same wait logout does.
 */
async function enterAsGuest() {
  if (!guestName.value.trim()) return
  joining.value = true
  error.value = ''

  try {
    const res = await joinAsGuest(token.value, guestName.value.trim())
    setSession(res.token, res.user)
    await nextTick()
    await navigateTo(roomPath(res.meeting, { call: true }))
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'You couldn’t be let into this meeting.'
  }
  finally {
    joining.value = false
  }
}

/** Sign in, and come back here. The same round trip an invite link takes. */
function signIn() {
  return navigateTo({ path: '/login', query: { redirect: route.fullPath } })
}

onMounted(async () => {
  try {
    meeting.value = await preview(token.value)
  }
  catch {
    error.value = 'This meeting link doesn’t lead anywhere. It may have been deleted.'
  }
  finally {
    loading.value = false
  }
})

useHead({ title: 'Meeting' })
</script>

<template>
  <div class="grid min-h-dvh place-items-center p-6">
    <Card class="w-full max-w-sm">
      <CardHeader>
        <CardTitle class="flex items-center gap-2">
          <component
            :is="meeting?.room_type === 'space' ? MapIcon : Mic"
            class="h-4 w-4 shrink-0 text-muted-foreground"
          />
          <span class="truncate">{{ meeting?.title ?? 'Meeting' }}</span>
        </CardTitle>
        <CardDescription>
          <template v-if="meeting?.creator">Called by {{ meeting.creator }}</template>
          <template v-else>A meeting in Side Chat</template>
        </CardDescription>
      </CardHeader>

      <CardContent class="space-y-3">
        <p v-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 class="h-4 w-4 animate-spin" /> Looking it up…
        </p>

        <template v-else-if="meeting">
          <p v-if="when" class="flex items-center gap-1.5 text-sm text-muted-foreground">
            <CalendarClock class="h-3.5 w-3.5 shrink-0" /> {{ when }}
          </p>

          <p class="text-sm text-muted-foreground">
            <template v-if="meeting.room_type === 'space'">
              It's a Side Space — a room you walk around in, with the people near you audible.
            </template>
            <template v-else>It's a voice call.</template>
          </p>

          <!-- Refused, and told why. A link into a server can't admit anybody, and saying so
               plainly beats a 404 that reads as "this meeting doesn't exist". -->
          <p v-if="!meeting.open" class="text-sm text-destructive">This link has expired.</p>
          <p v-else-if="!meeting.can_join" class="text-sm text-destructive">
            This meeting is in a server you’re not in. Ask whoever sent it for an invite to the server.
          </p>

          <!-- Signed in: join as yourself. -->
          <template v-if="isLoggedIn">
            <Button v-if="meeting.open && meeting.can_join" class="gap-2" :disabled="joining" @click="enter">
              <Loader2 v-if="joining" class="h-4 w-4 animate-spin" />
              <LogIn v-else class="h-4 w-4" />
              {{ meeting.member ? 'Go to the room' : 'Join the meeting' }}
            </Button>

            <!-- Said before they press it, not after: following a link should never silently add
                 somebody to a group chat. -->
            <p v-if="meeting.can_join && !meeting.member" class="text-[11px] text-muted-foreground">
              Joining adds you to this meeting’s group chat, so you can find it again afterwards.
            </p>
          </template>

          <!-- Signed out, and the meeting takes guests: a name is the whole of it. -->
          <template v-else-if="meeting.open && meeting.guests">
            <label class="block space-y-1">
              <span class="text-xs font-medium">Your name, as the room will see it</span>
              <input
                v-model="guestName"
                maxlength="40"
                placeholder="Sam from Acme"
                class="w-full rounded-md border bg-background px-2 py-1.5 text-sm"
                @keyup.enter="enterAsGuest"
              >
            </label>

            <Button class="gap-2" :disabled="joining || !guestName.trim()" @click="enterAsGuest">
              <Loader2 v-if="joining" class="h-4 w-4 animate-spin" />
              <LogIn v-else class="h-4 w-4" />
              Join as a guest
            </Button>

            <!-- Stated plainly, because a guest is agreeing to be visible under a name they just
                 invented and to have that arrival recorded. -->
            <p class="text-[11px] text-muted-foreground">
              You’ll appear as a guest, and this meeting keeps a record of who joined. Have an
              account?
              <button type="button" class="underline hover:text-foreground" @click="signIn">Sign in instead</button>.
            </p>
          </template>

          <!-- Signed out, and it needs an account. -->
          <template v-else-if="meeting.open && meeting.can_join">
            <Button class="gap-2" @click="signIn">
              <LogIn class="h-4 w-4" /> Sign in to join
            </Button>
            <p class="text-[11px] text-muted-foreground">
              This meeting needs an account. You’ll come back here once you’ve signed in.
            </p>
          </template>
        </template>

        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
      </CardContent>
    </Card>
  </div>
</template>
