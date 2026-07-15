<script setup lang="ts">
const { isDark, color, systemDark } = useTheme()

// Drive <html> so both the `.dark` class and the accent apply everywhere (incl. portals).
useHead({
  // "Side Chat - <server>" when a page sets a title, otherwise just "Side Chat".
  titleTemplate: title => (title ? `Side Chat - ${title}` : 'Side Chat'),
  htmlAttrs: {
    class: computed(() => (isDark.value ? 'dark' : '')),
    'data-accent': computed(() => color.value),
  },
})

onMounted(() => {
  const mq = window.matchMedia('(prefers-color-scheme: dark)')
  systemDark.value = mq.matches
  mq.addEventListener('change', e => (systemDark.value = e.matches))
})
</script>

<template>
  <div class="app-shell min-h-screen text-foreground antialiased">
    <NuxtRouteAnnouncer />
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </div>
</template>
