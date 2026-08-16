<script setup lang="ts">
import { ArrowLeft, Gauge, Hash, MessagesSquare, ScrollText, ShieldCheck, Users } from 'lucide-vue-next'

/**
 * The admin panel's frame.
 *
 * Its own layout rather than the app one, deliberately. The app layout is a sidebar of
 * *places you are* — your servers, your chats, your unreads — and the panel is none of
 * those: you administer rooms you aren't in and read chats you aren't part of. Sharing the
 * frame would put an operator's tools one mis-click away from an ordinary conversation, and
 * would leave the panel wearing a sidebar that highlights nothing it can navigate to.
 *
 * So: a flat list of five sections, a way back to the app, and nothing that pretends this is
 * a room. The visible seam is the point — you should be able to tell at a glance that you're
 * looking at the instance rather than at your own account.
 */
const sections = [
  { to: '/admin', label: 'Overview', icon: Gauge },
  { to: '/admin/users', label: 'Users', icon: Users },
  { to: '/admin/servers', label: 'Servers', icon: Hash },
  { to: '/admin/chats', label: 'DMs & groups', icon: MessagesSquare },
  { to: '/admin/audit', label: 'Message audit', icon: ScrollText },
]

const route = useRoute()
// Leaving marks the app as your side, so `/` stops sending you back here. See usePanelSide.
const { goToApp } = usePanelSide()

// Exact match for the overview, prefix for the rest — otherwise `/admin` stays lit on every
// page, since every path here starts with it.
const isActive = (to: string) => to === '/admin' ? route.path === '/admin' : route.path.startsWith(to)
</script>

<template>
  <div class="flex h-dvh bg-background text-foreground">
    <aside class="flex w-60 shrink-0 flex-col border-r bg-muted/30">
      <div class="flex items-center gap-2 border-b px-4 py-4">
        <ShieldCheck class="h-5 w-5 text-primary" />
        <div class="min-w-0">
          <p class="truncate text-sm font-semibold leading-tight">Admin</p>
          <p class="truncate text-[11px] text-muted-foreground">Instance controls</p>
        </div>
      </div>

      <nav class="flex-1 space-y-1 overflow-y-auto p-2">
        <NuxtLink
          v-for="section in sections"
          :key="section.to"
          :to="section.to"
          class="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm transition-colors"
          :class="isActive(section.to)
            ? 'bg-primary/10 font-medium text-primary'
            : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
        >
          <component :is="section.icon" class="h-4 w-4 shrink-0" />
          {{ section.label }}
        </NuxtLink>
      </nav>

      <div class="border-t p-2">
        <button
          type="button"
          class="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-left text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          @click="goToApp"
        >
          <ArrowLeft class="h-4 w-4 shrink-0" />
          Back to Side Chat
        </button>
      </div>
    </aside>

    <main class="min-w-0 flex-1 overflow-y-auto">
      <slot />
    </main>
  </div>
</template>
