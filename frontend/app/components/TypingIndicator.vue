<script setup lang="ts">
defineProps<{ label: string }>()
</script>

<template>
  <!--
    Always occupies its line, even when empty: letting it appear and disappear would
    shove the composer (and the message you're mid-way through typing) up and down.
  -->
  <div class="flex h-5 items-center gap-1.5 px-4 text-xs text-muted-foreground">
    <template v-if="label">
      <span class="flex gap-0.5" aria-hidden="true">
        <span v-for="i in 3" :key="i" class="dot h-1 w-1 rounded-full bg-muted-foreground" :style="{ animationDelay: `${(i - 1) * 150}ms` }" />
      </span>
      <span aria-live="polite">{{ label }}</span>
    </template>
  </div>
</template>

<style scoped>
.dot {
  animation: blink 1.2s infinite ease-in-out;
}

@keyframes blink {
  0%, 80%, 100% { opacity: 0.25; }
  40% { opacity: 1; }
}

@media (prefers-reduced-motion: reduce) {
  .dot {
    animation: none;
  }
}
</style>
