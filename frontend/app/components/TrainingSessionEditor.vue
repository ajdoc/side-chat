<script setup lang="ts">
import { ArrowDown, ArrowUp, Plus, Trash2, X } from 'lucide-vue-next'
import { Input } from '~/components/ui/input'
import type { TrainingBlock, TrainingContent, TrainingSession, TrainingVideo } from '~/types'
import { newId } from '~/lib/trainingEdit'

/**
 * One session's fields and its exercises, edited in place on the draft it's given.
 *
 * Exercises can be added, removed and reordered freely: each keeps its `id`, which is what
 * ticks point at, so nobody's progress moves.
 */
const props = defineProps<{
  session: TrainingSession
  warmups: TrainingContent['warmups']
}>()

const field = 'w-full rounded-md border bg-background px-2.5 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-ring'

function addBlock() {
  props.session.blocks.push({ id: newId(), name: '', dose: '', cue: '' })
}

function moveBlock(i: number, by: -1 | 1) {
  const blocks = props.session.blocks
  if (i + by < 0 || i + by >= blocks.length) return
  const [b] = blocks.splice(i, 1)
  blocks.splice(i + by, 0, b!)
}

/** A video link is edited as one field: words to search YouTube for, or a full link. */
function videoTarget(v: TrainingVideo) {
  return v.url ?? v.search ?? ''
}

function setVideoTarget(v: TrainingVideo, value: string) {
  if (/^https?:\/\//i.test(value.trim())) {
    v.url = value.trim()
    delete v.search
  }
  else {
    v.search = value
    delete v.url
  }
}

function addVideo(b: TrainingBlock) {
  b.videos = [...(b.videos ?? []), { label: '', search: '' }]
}

function removeVideo(b: TrainingBlock, j: number) {
  b.videos?.splice(j, 1)
  if (!b.videos?.length) delete b.videos
}
</script>

<template>
  <div class="space-y-3">
    <div class="grid gap-3 sm:grid-cols-[1fr_11rem]">
      <label class="block">
        <span class="text-xs font-medium text-muted-foreground">Session title</span>
        <Input v-model="session.title" class="mt-1" maxlength="120" />
      </label>
      <label class="block">
        <span class="text-xs font-medium text-muted-foreground">Length</span>
        <Input v-model="session.time" class="mt-1" placeholder="45 minutes" maxlength="60" />
      </label>
    </div>
    <div class="flex flex-wrap items-end gap-4">
      <label class="flex items-center gap-2 text-sm">
        <input v-model="session.hard" type="checkbox" class="h-4 w-4 accent-emerald-700"> Hard day
      </label>
      <label v-if="Object.keys(warmups).length" class="block">
        <span class="text-xs font-medium text-muted-foreground">Warm-up</span>
        <select v-model="session.warmup" :class="field" class="mt-1">
          <option :value="null">None</option>
          <option v-for="(w, id) in warmups" :key="id" :value="id">{{ id }} ({{ w.items.length }} steps)</option>
        </select>
      </label>
    </div>
    <label class="block">
      <span class="text-xs font-medium text-muted-foreground">What it's for</span>
      <textarea v-model="session.intent" rows="2" :class="field" class="mt-1" maxlength="1000" />
    </label>

    <p class="pt-1 text-sm font-semibold">Exercises</p>
    <ol class="space-y-3">
      <li v-for="(b, i) in session.blocks" :key="b.id" class="rounded-lg border bg-background p-3">
        <div class="flex items-center gap-2">
          <span class="w-6 text-lg font-extrabold text-emerald-700 dark:text-emerald-400">{{ i + 1 }}</span>
          <Input v-model="b.name" placeholder="Exercise name" class="h-8 flex-1 font-medium" maxlength="120" />
          <button type="button" class="rounded p-1 text-muted-foreground hover:bg-muted disabled:opacity-30" :disabled="i === 0" title="Move up" @click="moveBlock(i, -1)"><ArrowUp class="h-4 w-4" /></button>
          <button type="button" class="rounded p-1 text-muted-foreground hover:bg-muted disabled:opacity-30" :disabled="i === session.blocks.length - 1" title="Move down" @click="moveBlock(i, 1)"><ArrowDown class="h-4 w-4" /></button>
          <button type="button" class="rounded p-1 text-muted-foreground hover:bg-muted hover:text-destructive" title="Delete exercise" @click="session.blocks.splice(i, 1)"><Trash2 class="h-4 w-4" /></button>
        </div>
        <div class="mt-2 space-y-2 sm:pl-8">
          <textarea v-model="b.dose" rows="2" placeholder="Dose, e.g. 6 reps: sprint 10m…" :class="field" maxlength="500" />
          <textarea v-model="b.cue" rows="1" placeholder="Coaching cue" :class="field" maxlength="500" />
          <div v-for="(v, j) in b.videos ?? []" :key="j" class="flex gap-2">
            <Input v-model="v.label" placeholder="Link label" class="h-8 w-32 shrink-0 text-sm sm:w-40" maxlength="60" />
            <Input
              :model-value="videoTarget(v)"
              placeholder="YouTube search words, or a https:// link"
              class="h-8 min-w-0 flex-1 text-sm"
              @update:model-value="setVideoTarget(v, String($event))"
            />
            <button type="button" class="rounded p-1 text-muted-foreground hover:bg-muted" title="Remove link" @click="removeVideo(b, j)"><X class="h-4 w-4" /></button>
          </div>
          <button v-if="(b.videos?.length ?? 0) < 8" type="button" class="text-xs text-muted-foreground underline hover:text-foreground" @click="addVideo(b)">
            Add video link
          </button>
        </div>
      </li>
    </ol>
    <button v-if="session.blocks.length < 40" type="button" class="flex items-center gap-1 rounded-md border px-2.5 py-1.5 text-sm hover:bg-muted" @click="addBlock">
      <Plus class="h-4 w-4" /> Exercise
    </button>
  </div>
</template>
