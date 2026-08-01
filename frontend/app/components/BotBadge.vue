<script setup lang="ts">
/**
 * The pill next to a bot's name.
 *
 * A bot is an ordinary author everywhere else in the app — it posts, it gets @mentioned, it
 * shows up in the member list — which is exactly why this label has to exist: a message from
 * "Deploy Bot" is otherwise indistinguishable from one typed by a person with that name, and
 * the whole point of an account you can hand a token to is that somebody might.
 *
 * Deliberately not the accent colour used for Pinned and Decision: the badge is a fact about
 * the author, not a state of the message, and it shouldn't read as one more of those markers.
 */
</script>

<template>
  <span
    class="shrink-0 rounded border border-muted-foreground/30 bg-muted px-1 py-px text-[10px] font-semibold uppercase leading-none tracking-wide text-muted-foreground"
    title="An automated account, not a person."
  >Bot</span>
</template>
