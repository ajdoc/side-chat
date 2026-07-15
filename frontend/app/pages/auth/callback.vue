<script setup lang="ts">
// Landing page for the social-login redirect: FRONTEND_URL/auth/callback?token=...
const route = useRoute()
const { setToken, fetchUser } = useAuth()

onMounted(async () => {
  const token = route.query.token as string | undefined
  const error = route.query.error as string | undefined

  if (error || !token) {
    await navigateTo('/login?error=social')
    return
  }

  setToken(token)
  await fetchUser()
  await navigateTo('/')
})
</script>

<template>
  <div class="grid min-h-screen place-items-center text-muted-foreground">
    Signing you in…
  </div>
</template>
