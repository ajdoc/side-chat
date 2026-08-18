<script setup lang="ts">
/**
 * The pill next to a guest's name.
 *
 * A guest walked in through a meeting link with a name they typed a minute ago and no account
 * behind it — and everywhere else in the app they are an ordinary author, because that is what
 * made guests cheap to build. This label is the other side of that bargain: without it, "Sam
 * from Acme" in a member list is indistinguishable from a colleague, and a name is the only
 * thing a guest ever had to prove.
 *
 * Same shape as {@link BotBadge} and for the same reason — a fact about the author rather than a
 * state of the message — but in the accent-free warning tone, since the thing worth noticing here
 * is *unverified*, not *automated*.
 */
</script>

<template>
  <span
    class="shrink-0 rounded border border-amber-500/40 bg-amber-500/10 px-1 py-px text-[10px] font-semibold uppercase leading-none tracking-wide text-amber-700 dark:text-amber-400"
    title="Joined through a meeting link. The name is self-chosen and unverified."
  >Guest</span>
</template>
