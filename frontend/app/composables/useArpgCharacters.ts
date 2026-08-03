import type { ArpgCharacter, HeroClass } from '~/types'

/**
 * Your dungeon heroes — the roster, from the client's side.
 *
 * Nothing here is per-room, because a character isn't: you roll one once and take it into
 * whichever Side Space's Labyrinth you walk into. That's why this is a plain fetch with no Echo
 * subscription anywhere in it — a hero changes when *you* change it, and there is nobody else to
 * tell.
 *
 * "Which one am I playing" is an ordering rather than a flag ({@link select} simply says you
 * played this one just now), so the list's first entry is always the hero a portal will seat you
 * with — see ArpgCharacterController.
 */
export function useArpgCharacters() {
  const api = useApi()

  const characters = ref<ArpgCharacter[]>([])
  const loading = ref(false)

  async function load() {
    loading.value = true
    try {
      const res = await api<{ data: ArpgCharacter[] }>('/api/arpg/characters')
      characters.value = res.data
    } catch {
      characters.value = []
    } finally {
      loading.value = false
    }
  }

  /** Roll a new hero. Answers with them, so the caller can take them straight in. */
  async function create(name: string, heroClass: HeroClass) {
    const res = await api<{ data: ArpgCharacter }>('/api/arpg/characters', {
      method: 'POST',
      body: { name, class: heroClass },
    })
    // Newest first — a hero you just rolled is the one you're about to play.
    characters.value = [res.data, ...characters.value]

    return res.data
  }

  /** Take this one in next. */
  async function select(id: number) {
    await api(`/api/arpg/characters/${id}/select`, { method: 'POST' })
    const chosen = characters.value.find(c => c.id === id)
    if (chosen) characters.value = [chosen, ...characters.value.filter(c => c.id !== id)]
  }

  async function retire(id: number) {
    await api(`/api/arpg/characters/${id}`, { method: 'DELETE' })
    characters.value = characters.value.filter(c => c.id !== id)
  }

  return { characters, loading, load, create, select, retire }
}
