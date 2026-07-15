import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

// Laravel Echo bound to Reverb. Private channels authenticate against the API's
// /broadcasting/auth endpoint using the Passport Bearer token.
export default defineNuxtPlugin(() => {
  const config = useRuntimeConfig()
  const token = useCookie<string | null>('auth_token')

  ;(window as any).Pusher = Pusher

  const echo = new Echo({
    broadcaster: 'reverb',
    key: config.public.reverbKey,
    wsHost: config.public.reverbHost,
    wsPort: Number(config.public.reverbPort),
    wssPort: Number(config.public.reverbPort),
    forceTLS: config.public.reverbScheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, callback: (error: unknown, data: unknown) => void) => {
        $fetch(`${config.public.apiBase}/broadcasting/auth`, {
          method: 'POST',
          headers: {
            Authorization: `Bearer ${token.value}`,
            Accept: 'application/json',
          },
          body: { socket_id: socketId, channel_name: channel.name },
        })
          .then(res => callback(null, res))
          .catch(err => callback(err, null))
      },
    }),
  })

  return {
    provide: { echo: echo as Echo<'reverb'> },
  }
})
