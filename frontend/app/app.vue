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
  <!-- safe-inset here covers the pages that render outside the app layout (login, register,
       onboarding); the layout applies its own for everything behind auth. -->
  <div class="app-shell safe-inset min-h-screen text-foreground antialiased">
    <NuxtRouteAnnouncer />
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </div>
</template>
