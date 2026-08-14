<script setup lang="ts">
import { Loader2, X } from 'lucide-vue-next'
import type { AvatarLook, BodyKind, HairColour, HairKind, OutfitKind, SkinKind } from '~/lib/spaceAvatar'
import type { PetKind } from '~/lib/spacePets'
import {
  BODIES,
  COSTUMES,
  COSTUME_META,
  DEFAULT_LOOK,
  HAIRS,
  HAIR_COLOURS,
  OUTFITS,
  SKINS,
  drawPortrait,
} from '~/lib/spaceAvatar'
import { PETS, PET_KEYS, drawPetPortrait } from '~/lib/spacePets'
import { onSheetLoaded } from '~/lib/spriteSheet'
import { Button } from '~/components/ui/button'

/**
 * Choosing what you look like in a Side Space, and which starter trots after you.
 *
 * ## Why the previews are canvases
 *
 * Every option is drawn with *the same functions the room uses*, at the same 16-pixel grid.
 * That's the entire reason this doesn't have a folder of preview images in it: there is one
 * renderer, so a hairstyle can never look one way in the picker and another in the room, and
 * adding a style is adding a grid rather than a grid and a screenshot.
 *
 * Each swatch redraws when anything about the look changes, because a hairstyle has to be shown
 * on *your* head — in your skin, with your hair colour — or picking between eight of them is
 * picking between eight strangers.
 *
 * ## Saving
 *
 * One save at the end rather than a write per click: this is a form, and a room full of people
 * doesn't need to watch you try on hats. The parent puts the new look on immediately
 * ({@link useSpacePresence.restyle}) so it's live before the request has landed.
 */
const emit = defineEmits<{ close: [], saved: [AvatarLook, PetKind | null] }>()

const { look: savedLook, pet: savedPet, save } = useSpaceAppearance()
const { user } = useAuth()

const look = reactive<AvatarLook>({ ...savedLook.value })
const pet = ref<PetKind | null>(savedPet.value)
const saving = ref(false)
const error = ref('')

/** The shirt colour `auto` resolves to — your id's hue, which is what the room already shows. */
const hue = computed(() => ((user.value?.id ?? 1) * 137.508) % 360)

const HAIR_LABELS: Record<HairKind, string> = {
  short: 'Short',
  bob: 'Bob',
  long: 'Long',
  ponytail: 'Ponytail',
  buzz: 'Buzzed',
  curly: 'Curly',
  spiky: 'Spiky',
  cap: 'Cap',
}

const BODY_LABELS: Record<BodyKind, string> = { slim: 'Slim', sturdy: 'Sturdy', feminine: 'Feminine' }

const SKIN_SWATCH: Record<SkinKind, string> = {
  porcelain: '#ffe0c4',
  fair: '#f7cea8',
  olive: '#e0b183',
  tan: '#c98f5f',
  brown: '#96603a',
  deep: '#6b4229',
}

const HAIR_SWATCH: Record<HairColour, string> = {
  black: '#37323c',
  brown: '#7a4f2d',
  blonde: '#e0b451',
  auburn: '#a44a2c',
  ash: '#b9b3ab',
  blue: '#4a72c0',
  pink: '#d3679c',
  green: '#4f9a5c',
}

const OUTFIT_SWATCH: Record<Exclude<OutfitKind, 'auto'>, string> = {
  red: '#cf4a45',
  orange: '#e0813a',
  yellow: '#e2b93f',
  green: '#57a04c',
  teal: '#3fa3a0',
  blue: '#4277c4',
  indigo: '#6060c0',
  violet: '#8a5cb8',
  pink: '#cd5f96',
  slate: '#6b7280',
}

/** The groups, in the order the picker shows them. Empty ones don't get a heading. */
const petGroups = computed(() => [
  { title: 'First three', keys: PET_KEYS.filter(k => PETS[k].region === 'first') },
  { title: 'Second three', keys: PET_KEYS.filter(k => PETS[k].region === 'second') },
  { title: 'Visitors', keys: PET_KEYS.filter(k => PETS[k].region === 'guest') },
].filter(g => g.keys.length > 0))

// --- the previews ---

const big = ref<HTMLCanvasElement | null>(null)
const hairCanvases = ref<Record<string, HTMLCanvasElement | null>>({})
const petCanvases = ref<Record<string, HTMLCanvasElement | null>>({})
const costumeCanvases = ref<Record<string, HTMLCanvasElement | null>>({})

/**
 * Draw a sprite into a small canvas at device resolution.
 *
 * The backing store is sized in device pixels and the CSS size left to the layout, which is what
 * keeps a 4× rasterised sprite crisp on a retina screen instead of being scaled twice.
 */
function paint(canvas: HTMLCanvasElement | null, size: number, draw: (ctx: CanvasRenderingContext2D) => void) {
  if (!canvas) return

  const dpr = window.devicePixelRatio || 1
  canvas.width = size * dpr
  canvas.height = size * dpr

  const ctx = canvas.getContext('2d')
  if (!ctx) return

  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
  ctx.clearRect(0, 0, size, size)
  draw(ctx)
}

function repaint() {
  // The big one: you, standing still, facing the viewer.
  paint(big.value, 96, ctx => drawPortrait(ctx, look, 48, 74, 44, hue.value))

  // One per hairstyle, each wearing the rest of *your* look, so the choice is between hair
  // rather than between eight different people. Drawn bare-headed while a costume is on: the
  // room would show a hood, and a row of eight identical hoods is a row of no information.
  for (const style of HAIRS) {
    paint(hairCanvases.value[style] ?? null, 44, ctx =>
      drawPortrait(ctx, { ...look, hair: style, costume: 'none' }, 22, 36, 22, hue.value))
  }

  // Costumes, each over the rest of your look — same reason.
  for (const c of COSTUMES) {
    paint(costumeCanvases.value[c] ?? null, 44, ctx =>
      drawPortrait(ctx, { ...look, costume: c }, 22, 36, 22, hue.value))
  }

  for (const key of PET_KEYS) {
    paint(petCanvases.value[key] ?? null, 44, ctx => drawPetPortrait(ctx, key, 22, 32, 30))
  }
}

watch(() => ({ ...look }), () => nextTick(repaint), { deep: true })
onMounted(() => nextTick(repaint))
/*
 * Sheet-backed sprites (the Espurr pet and suit) may finish loading after the previews have been
 * painted, and these canvases are painted once rather than on a loop. Without this the dialog
 * would sit showing the fallback artwork for a creature whose real sheet had since arrived.
 */
onScopeDispose(onSheetLoaded(() => nextTick(repaint)))

// --- saving ---

async function onSave() {
  saving.value = true
  error.value = ''

  try {
    const chosen: AvatarLook = { ...look }
    await save({ avatar: chosen, pet: pet.value })
    emit('saved', chosen, pet.value)
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Could not save how you look.'
  }
  finally {
    saving.value = false
  }
}

function reset() {
  Object.assign(look, DEFAULT_LOOK)
  pet.value = null
}
</script>

<template>
  <!-- A sheet rather than a modal card: there are five rows of choices and a live preview, and
       squeezing that into a dialog makes every swatch too small to tell apart. -->
  <div class="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4" @click.self="emit('close')">
    <div class="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-lg border bg-background shadow-xl">
      <header class="flex h-12 shrink-0 items-center justify-between border-b px-4">
        <span class="flex items-center gap-2 font-semibold">
          How you look
        </span>
        <button class="rounded p-1 text-muted-foreground hover:text-foreground" aria-label="Close" @click="emit('close')">
          <X class="h-4 w-4" />
        </button>
      </header>

      <div class="flex min-h-0 flex-1 gap-4 overflow-y-auto p-4">
        <!-- You, as everybody else sees you. -->
        <div class="flex w-28 shrink-0 flex-col items-center gap-2">
          <canvas ref="big" class="h-24 w-24 rounded-md border bg-[#7cb342]" style="image-rendering: pixelated" />
          <p class="text-center text-[11px] leading-snug text-muted-foreground">
            {{ pet ? `You and ${PETS[pet].label}` : 'Nobody with you' }}
          </p>
          <button class="text-[11px] text-muted-foreground underline hover:text-foreground" @click="reset">
            Start over
          </button>
        </div>

        <div class="min-w-0 flex-1 space-y-4">
          <!--
            Costumes, first — a costume replaces the whole sprite, so choosing one changes what
            every control below it is even for. They stay on screen rather than being hidden
            while one is worn: what's underneath is kept, and it's what you get back when you
            take the costume off, so it should still be editable.
          -->
          <section class="space-y-1.5">
            <p class="text-xs font-medium text-muted-foreground">Costume</p>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="c in COSTUMES"
                :key="c"
                type="button"
                class="flex w-[6.5rem] flex-col items-center gap-0.5 rounded-md border p-1 transition-colors"
                :class="look.costume === c ? 'border-primary bg-muted' : 'hover:bg-muted/50'"
                :title="COSTUME_META[c].blurb"
                @click="look.costume = c"
              >
                <canvas
                  :ref="el => (costumeCanvases[c] = el as HTMLCanvasElement)"
                  class="h-11 w-11"
                  style="image-rendering: pixelated"
                />
                <span class="text-[10px] leading-tight">{{ COSTUME_META[c].label }}</span>
              </button>
            </div>
            <p v-if="look.costume !== 'none'" class="text-[11px] leading-snug text-muted-foreground">
              {{ COSTUME_META[look.costume].blurb }} — it covers your build and hair, which are kept
              for when you take it off.
            </p>
          </section>

          <!-- Build -->
          <section class="space-y-1.5">
            <p class="text-xs font-medium text-muted-foreground">Build</p>
            <div class="flex gap-2">
              <button
                v-for="b in BODIES"
                :key="b"
                type="button"
                class="rounded-md border px-3 py-1.5 text-sm transition-colors"
                :class="look.body === b ? 'border-primary bg-muted font-medium' : 'hover:bg-muted/50'"
                @click="look.body = b"
              >{{ BODY_LABELS[b] }}</button>
            </div>
          </section>

          <!-- Hair, drawn on your own head -->
          <section class="space-y-1.5">
            <p class="text-xs font-medium text-muted-foreground">Hair</p>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="style in HAIRS"
                :key="style"
                type="button"
                class="flex w-16 flex-col items-center gap-0.5 rounded-md border p-1 transition-colors"
                :class="look.hair === style ? 'border-primary bg-muted' : 'hover:bg-muted/50'"
                :title="HAIR_LABELS[style]"
                @click="look.hair = style"
              >
                <canvas
                  :ref="el => (hairCanvases[style] = el as HTMLCanvasElement)"
                  class="h-11 w-11"
                  style="image-rendering: pixelated"
                />
                <span class="text-[10px] leading-tight">{{ HAIR_LABELS[style] }}</span>
              </button>
            </div>
          </section>

          <section class="space-y-1.5">
            <p class="text-xs font-medium text-muted-foreground">Hair colour</p>
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="colour in HAIR_COLOURS"
                :key="colour"
                type="button"
                class="h-7 w-7 rounded-full border-2 transition-transform"
                :class="look.hair_color === colour ? 'border-primary scale-110' : 'border-transparent hover:scale-105'"
                :style="{ backgroundColor: HAIR_SWATCH[colour] }"
                :title="colour"
                @click="look.hair_color = colour"
              />
            </div>
          </section>

          <section class="space-y-1.5">
            <p class="text-xs font-medium text-muted-foreground">Skin</p>
            <div class="flex flex-wrap gap-1.5">
              <button
                v-for="skin in SKINS"
                :key="skin"
                type="button"
                class="h-7 w-7 rounded-full border-2 transition-transform"
                :class="look.skin === skin ? 'border-primary scale-110' : 'border-transparent hover:scale-105'"
                :style="{ backgroundColor: SKIN_SWATCH[skin] }"
                :title="skin"
                @click="look.skin = skin"
              />
            </div>
          </section>

          <section class="space-y-1.5">
            <p class="text-xs font-medium text-muted-foreground">Shirt</p>
            <div class="flex flex-wrap gap-1.5">
              <!-- `auto` is the colour your id already gives you — the room's original behaviour,
                   kept as an option so nobody has to pick one to look the way they always have. -->
              <button
                type="button"
                class="h-7 rounded-full border-2 px-2 text-[11px] transition-transform"
                :class="look.outfit === 'auto' ? 'border-primary' : 'border-transparent hover:bg-muted'"
                @click="look.outfit = 'auto'"
              >Mine</button>
              <button
                v-for="colour in OUTFITS.filter(o => o !== 'auto')"
                :key="colour"
                type="button"
                class="h-7 w-7 rounded-full border-2 transition-transform"
                :class="look.outfit === colour ? 'border-primary scale-110' : 'border-transparent hover:scale-105'"
                :style="{ backgroundColor: OUTFIT_SWATCH[colour as Exclude<OutfitKind, 'auto'>] }"
                :title="colour"
                @click="look.outfit = colour"
              />
            </div>
          </section>

          <!-- The starters -->
          <section class="space-y-1.5">
            <p class="text-xs font-medium text-muted-foreground">Companion</p>

            <div v-for="group in petGroups" :key="group.title" class="space-y-1">
              <p class="text-[11px] text-muted-foreground">{{ group.title }}</p>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="key in group.keys"
                  :key="key"
                  type="button"
                  class="flex w-24 flex-col items-center gap-0.5 rounded-md border p-1.5 transition-colors"
                  :class="pet === key ? 'border-primary bg-muted' : 'hover:bg-muted/50'"
                  :title="PETS[key].blurb"
                  @click="pet = pet === key ? null : key"
                >
                  <canvas
                    :ref="el => (petCanvases[key] = el as HTMLCanvasElement)"
                    class="h-11 w-11"
                    style="image-rendering: pixelated"
                  />
                  <span class="text-[11px] font-medium leading-tight">{{ PETS[key].label }}</span>
                  <span class="text-[10px] capitalize leading-tight text-muted-foreground">{{ PETS[key].element }}</span>
                </button>
              </div>
            </div>

            <p class="text-[11px] text-muted-foreground">
              Click the one you've picked again to send it home.
            </p>
          </section>
        </div>
      </div>

      <footer class="flex h-12 shrink-0 items-center justify-end gap-2 border-t px-4">
        <p v-if="error" class="mr-auto truncate text-xs text-destructive">{{ error }}</p>
        <Button variant="outline" size="sm" :disabled="saving" @click="emit('close')">Cancel</Button>
        <Button size="sm" :disabled="saving" @click="onSave">
          <Loader2 v-if="saving" class="mr-1.5 h-4 w-4 animate-spin" />
          {{ saving ? 'Saving…' : 'Save' }}
        </Button>
      </footer>
    </div>
  </div>
</template>
