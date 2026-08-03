<script setup lang="ts">
import type { MemberBadge } from '~/types'

/**
 * The badges a member holds, as small pills next to their name.
 *
 * Sits alongside {@link BotBadge}, and is deliberately a different shape: BOT is one fixed
 * fact about an account, while these are a server's own labels — several of them, in the
 * server's own colours, meaning whatever that server decided they mean.
 *
 * Purely presentational. Whoever renders a roster already has the badges on the payload
 * (see ChannelMemberController); this never fetches.
 */
withDefaults(defineProps<{
  badges: MemberBadge[]
  /**
   * How many to show before collapsing the rest into a "+2".
   *
   * A roster is a list of *people*, and somebody who has collected nine badges shouldn't
   * push everybody else's name out of alignment.
   */
  max?: number
}>(), { max: 3 })
</script>

<template>
  <span v-if="badges.length" class="inline-flex flex-wrap items-center gap-1 align-middle">
    <span
      v-for="badge in badges.slice(0, max)"
      :key="badge.id"
      class="rounded px-1 py-px text-[10px] font-medium leading-tight"
      :class="badge.color ? '' : 'bg-muted text-muted-foreground'"
      :style="badge.color ? { backgroundColor: `${badge.color}22`, color: badge.color } : undefined"
      :title="badge.name"
    >{{ badge.emoji }} {{ badge.name }}</span>

    <span
      v-if="badges.length > max"
      class="text-[10px] text-muted-foreground"
      :title="badges.slice(max).map(b => b.name).join(', ')"
    >+{{ badges.length - max }}</span>
  </span>
</template>
