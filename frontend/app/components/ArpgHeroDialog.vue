<script setup lang="ts">
import { Loader2, Plus, Skull, Sparkles } from 'lucide-vue-next'
import type { HeroClass } from '~/lib/arpgEngine'
import { Button } from '~/components/ui/button'

/**
 * Who you're taking down there.
 *
 * Shown when somebody picks The Labyrinth out of the propose menu, because a crawl is the one
 * game where *which* of your things is playing is a real question — a pet battle uses the starter
 * you already chose, and Among Us uses you. Picking here is a `select`, which is only a way of
 * saying "I played this one most recently"; the portal then seats you with whatever's top of the
 * list, so there is no second piece of state to keep in step.
 *
 * It's the same dialog whether you're opening a portal or rolling your first hero, which is why
 * it also carries the create form rather than sending anyone off to a settings screen.
 */
const emit = defineEmits<{
  /** Take this hero in — the caller selects them, then opens or joins the run. */
  enter: [number]
  close: []
}>()

const { characters, loading, load, create, select, retire } = useArpgCharacters()

const rolling = ref(false)
const name = ref('')
const heroClass = ref<HeroClass>('warrior')
const error = ref('')

/**
 * The eight, and what each is actually for.
 *
 * A class is its skill tree, not its opening stat block — any hero can borrow three skills from
 * anywhere ({@link useArpgSkills} serves the catalogue), so what you pick here is what you'll be
 * *best* at, not what you're limited to.
 *
 * It's also the start of a *line* rather than the whole of it: at thirty a mage becomes a wizard,
 * a thief an assassin. What's named here is the beginning, and the second job's tree is a strict
 * addition to it — see App\Support\Arpg\Jobs.
 */
const CLASSES: { id: HeroClass, label: string, blurb: string }[] = [
  { id: 'swordsman', label: 'Swordsman', blurb: 'Strong, tough, and everything in the swing’s arc.' },
  { id: 'crusader', label: 'Crusader', blurb: 'A wall that heals the people behind it.' },
  { id: 'archer', label: 'Archer', blurb: 'Everything at range. Three arrows at a time, later.' },
  { id: 'thief', label: 'Thief', blurb: 'Fast, sharp, and gone before it turns round.' },
  { id: 'mage', label: 'Mage', blurb: 'Frail, and the reason the room is on fire.' },
  { id: 'priest', label: 'Priest', blurb: 'Keeps the party standing. Brings a heavy word.' },
  { id: 'necromancer', label: 'Necromancer', blurb: 'Bone spears, and the dead fighting for you.' },
  { id: 'druid', label: 'Druid', blurb: 'Wolves, weather, and briefly being a bear.' },
]

onMounted(load)

async function onEnter(id: number) {
  await select(id).catch(() => {})
  emit('enter', id)
}

async function onCreate() {
  const trimmed = name.value.trim()
  if (!trimmed) return

  error.value = ''
  try {
    const hero = await create(trimmed, heroClass.value)
    rolling.value = false
    name.value = ''
    await onEnter(hero.id)
  } catch (e: any) {
    // The two the server actually refuses: a duplicate name, and a seventh hero.
    error.value = e?.data?.message ?? 'That name is taken.'
  }
}
</script>

<template>
  <div class="pointer-events-auto absolute inset-0 z-40 grid place-items-center bg-black/60 p-4 backdrop-blur-[1px]">
    <div class="w-full max-w-sm space-y-3 rounded-xl border bg-background p-5 shadow-xl">
      <div class="text-center">
        <Sparkles class="mx-auto h-7 w-7 text-primary" />
        <p class="text-lg font-semibold">Who's going down?</p>
        <p class="text-xs text-muted-foreground">Your hero keeps their levels and their loot between runs.</p>
      </div>

      <p v-if="loading" class="py-4 text-center text-sm text-muted-foreground">
        <Loader2 class="mx-auto h-4 w-4 animate-spin" />
      </p>

      <!-- The roster: the top one is who a portal would seat you with right now. -->
      <div v-else-if="!rolling" class="space-y-1.5">
        <button
          v-for="hero in characters"
          :key="hero.id"
          type="button"
          class="flex w-full items-center gap-2 rounded-lg border px-3 py-2 text-left transition-colors hover:bg-muted"
          @click="onEnter(hero.id)"
        >
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium">{{ hero.name }}</p>
            <p class="text-[11px] capitalize text-muted-foreground">
              <!-- The job, not the class: a level 32 mage is a Wizard and should be told so. -->
              Level {{ hero.level }} {{ hero.job_name }} · {{ hero.gold }}g · deepest floor {{ hero.depth }}
            </p>
          </div>
          <span
            class="shrink-0 rounded p-1 text-muted-foreground transition-colors hover:text-destructive"
            title="Retire this hero"
            @click.stop="retire(hero.id)"
          >
            <Skull class="h-3.5 w-3.5" />
          </span>
        </button>

        <Button variant="outline" class="w-full gap-1.5" @click="rolling = true">
          <Plus class="h-4 w-4" /> Roll a new hero
        </Button>
      </div>

      <!-- Rolling one. Three classes, a name, and down you go. -->
      <div v-else class="max-h-[70vh] space-y-2 overflow-y-auto">
        <input
          v-model="name"
          type="text"
          maxlength="40"
          placeholder="Name"
          class="w-full rounded-md border bg-background px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-primary/40"
          @keydown.enter="onCreate"
        >

        <button
          v-for="option in CLASSES"
          :key="option.id"
          type="button"
          class="flex w-full items-start gap-2 rounded-lg border px-3 py-2 text-left transition-colors"
          :class="heroClass === option.id ? 'border-primary bg-primary/5' : 'hover:bg-muted'"
          @click="heroClass = option.id"
        >
          <div>
            <p class="text-sm font-medium">{{ option.label }}</p>
            <p class="text-[11px] text-muted-foreground">{{ option.blurb }}</p>
          </div>
        </button>

        <p v-if="error" class="text-xs text-destructive">{{ error }}</p>

        <div class="flex gap-2">
          <Button class="flex-1" :disabled="!name.trim()" @click="onCreate">Begin</Button>
          <Button variant="outline" @click="rolling = false">Back</Button>
        </div>
      </div>

      <button class="w-full text-xs text-muted-foreground underline hover:text-foreground" @click="emit('close')">
        Not now
      </button>
    </div>
  </div>
</template>
