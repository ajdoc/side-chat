/**
 * Measures what the microphone's spectral noise suppressor actually does. Run it with
 * `make verify-mic`.
 *
 * The worklet at `public/worklets/noise-suppressor.js` is the one piece of this codebase whose
 * correctness is neither a type nor a request/response: it is DSP, it runs on the audio thread
 * inside a browser, and the only ordinary way to know whether a change to it helped is to put
 * headphones on and listen — which is not a thing a diff can be reviewed against, and not a
 * thing anyone can do for the eleven combinations of room, microphone and browser that matter.
 *
 * So the globals an AudioWorklet is given (`sampleRate`, `AudioWorkletProcessor`,
 * `registerProcessor`) are shimmed here, the real processor file is loaded unmodified, and it
 * is driven with synthetic signals whose answers are known in advance: pink-ish noise standing
 * in for a fan or rain, and a harmonic buzz standing in for a voice. What comes out is measured
 * in dB.
 *
 * The two numbers that matter are opposed, which is why both are asserted. Any suppressor can
 * make noise quiet by making everything quiet; the useful claim is that the noise falls a long
 * way *and the speech does not move*. The thresholds below are the floor of what's worth
 * shipping, not the current figures — see the run output for those.
 *
 * What this deliberately cannot tell you: whether the result sounds pleasant. Musical noise,
 * pumping and a hollow "underwater" colouration are all audible long before they show up in an
 * RMS ratio. Passing here means a change is not broken; it doesn't mean it's an improvement.
 */
import fs from 'node:fs'
import vm from 'node:vm'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const SR = 48000
const HOP = 128
const LATENCY = 512 - HOP

let Registered = null
const sandbox = {
  sampleRate: SR,
  AudioWorkletProcessor: class { constructor() { this.port = { onmessage: null, postMessage() {} } } },
  registerProcessor: (name, cls) => { Registered = cls },
  Math, Float32Array, Uint16Array, console,
}
const WORKLET = path.join(
  path.dirname(fileURLToPath(import.meta.url)),
  '../public/worklets/noise-suppressor.js',
)

vm.createContext(sandbox)
vm.runInContext(fs.readFileSync(WORKLET, 'utf8'), sandbox)

/**
 * The strength curve from `app/lib/micProcessing.ts`, mirrored — the worklet's parameters are
 * no longer fixed, so "what does the suppressor do" is only answerable at a stated strength.
 * Kept as the two endpoints plus the shipped default, which are the three points anyone
 * actually gets: drag it to the bottom, leave it alone, drag it to the top.
 */
const lerp = (from, to, t) => from + (to - from) * t
const strength = t => ({ amount: lerp(0.15, 0.9, t), floor: lerp(0.35, 0.05, t) })

const GENTLE = strength(0)
const DEFAULT = strength(0.35)
const MAX = strength(1)

function run(signal, params = {}) {
  const proc = new Registered()
  const p = {
    amount: [params.amount ?? MAX.amount],
    floor: [params.floor ?? MAX.floor],
    threshold: [params.threshold ?? 0.015],
    ratio: [params.ratio ?? 0.55],
    attack: [params.attack ?? 0.006],
    release: [params.release ?? 0.18],
    hold: [params.hold ?? 0.25],
  }
  const out = new Float32Array(signal.length)
  for (let i = 0; i + HOP <= signal.length; i += HOP) {
    const inBlock = signal.subarray(i, i + HOP)
    const outBlock = new Float32Array(HOP)
    proc.process([[inBlock]], [[outBlock]], p)
    out.set(outBlock, i)
  }
  return out
}

const rms = (a, from = 0, to = a.length) => {
  let s = 0
  for (let i = from; i < to; i++) s += a[i] * a[i]
  return Math.sqrt(s / (to - from))
}

/**
 * Seeded, deliberately. With `Math.random()` the SNR measurement below came out bimodal —
 * ~5dB on one run and ~15dB on the next from identical code — because whether the estimator
 * settles on the room or on the room-plus-a-word depends on the exact noise it was handed. A
 * measurement that answers differently each time can't tell you whether a change helped, which
 * is the only thing this file exists to do. One fixed sequence, so a number that moves means
 * the code moved. (mulberry32: three lines, and better distributed than anything shorter.)
 */
const seeded = (seed) => () => {
  seed = (seed + 0x6D2B79F5) | 0
  let t = Math.imul(seed ^ (seed >>> 15), 1 | seed)
  t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
  return ((t ^ (t >>> 14)) >>> 0) / 4294967296
}

const noise = (n, amp) => {
  const a = new Float32Array(n)
  const random = seeded(0x5EED)
  // Pink-ish: a one-pole lowpass over white, which is much closer to a fan or rain than white.
  let last = 0
  for (let i = 0; i < n; i++) {
    const w = (random() * 2 - 1) * amp
    last = last * 0.92 + w * 0.08
    a[i] = last * 6
  }
  return a
}

// A vowel-ish buzz: a 120Hz fundamental plus harmonics, which is what the suppressor has to
// keep. Windowed so it starts and stops like a word.
const speech = (n, amp, startFrac = 0.35, endFrac = 0.95) => {
  const a = new Float32Array(n)
  const s = Math.floor(n * startFrac); const e = Math.floor(n * endFrac)
  for (let i = s; i < e; i++) {
    const t = i / SR
    const env = Math.min(1, (i - s) / 2000) * Math.min(1, (e - i) / 2000)
    a[i] = amp * env * (
      Math.sin(2 * Math.PI * 120 * t)
      + 0.6 * Math.sin(2 * Math.PI * 240 * t)
      + 0.4 * Math.sin(2 * Math.PI * 480 * t)
      + 0.25 * Math.sin(2 * Math.PI * 960 * t)
    ) / 2.25
  }
  return a
}

const results = []
const check = (name, pass, detail) => { results.push({ name, pass, detail }); }

// ---- 1. With every gain pinned to 1 (floor = 1), the chain must reconstruct its input.
{
  const n = SR
  const sig = speech(n, 0.2, 0.1, 0.9)
  const out = run(sig, { floor: 1 })
  let worst = 0
  for (let i = LATENCY + 2048; i < n - HOP; i++) worst = Math.max(worst, Math.abs(out[i] - sig[i - LATENCY]))
  check('overlap-add reconstructs the input exactly (FFT + COLA correct)', worst < 1e-4, `max sample error ${worst.toExponential(2)}`)
}

// ---- 2. No NaN / no runaway, ever.
{
  const n = SR
  const sig = new Float32Array(n)
  const sp = speech(n, 0.25); const nz = noise(n, 0.05)
  for (let i = 0; i < n; i++) sig[i] = sp[i] + nz[i]
  const out = run(sig)
  let bad = 0; let peak = 0
  for (const v of out) { if (!Number.isFinite(v)) bad++; peak = Math.max(peak, Math.abs(v)) }
  check('output stays finite and bounded', bad === 0 && peak < 2, `non-finite ${bad}, peak ${peak.toFixed(3)}`)
}

// ---- 3. Steady noise alone (a fan, rain) must be substantially attenuated — and the amount
// of it must actually follow the slider, which is the only claim the slider makes.
{
  const n = SR * 2
  const nz = noise(n, 0.05)

  const attenuation = (params) => {
    const out = run(nz, params)
    // Skip the first half-second: the estimate is still converging.
    return 20 * Math.log10(rms(out, SR / 2 + LATENCY, n - HOP) / rms(nz, SR / 2, n - HOP))
  }

  const max = attenuation(MAX)
  const mid = attenuation(DEFAULT)
  const gentle = attenuation(GENTLE)

  check('steady room noise is attenuated by >12dB at full strength', max < -12, `${max.toFixed(1)} dB`)
  check(
    'attenuation follows the strength curve',
    max < mid && mid < gentle && gentle < -1,
    `gentle ${gentle.toFixed(1)} / default ${mid.toFixed(1)} / max ${max.toFixed(1)} dB`,
  )
}

// ---- 4. Speech must survive: the suppressor may not cost more than ~3dB on the voice.
{
  const n = SR * 2
  const sp = speech(n, 0.25, 0.5, 0.95)
  const nz = noise(n, 0.05)
  const mixed = new Float32Array(n)
  for (let i = 0; i < n; i++) mixed[i] = sp[i] + nz[i]

  const out = run(mixed, DEFAULT)
  const from = Math.floor(n * 0.6); const to = Math.floor(n * 0.9)
  const speechIn = rms(sp, from, to)
  const speechOut = rms(out, from + LATENCY, to + LATENCY)
  const db = 20 * Math.log10(speechOut / speechIn)
  check('speech passes within 3dB of its input level', db > -3 && db < 3, `${db.toFixed(1)} dB`)

  // And the gaps around it must be much quieter than the noise that was there.
  const gapIn = rms(mixed, 0, Math.floor(n * 0.45))
  const gapOut = rms(out, SR / 2 + LATENCY, Math.floor(n * 0.45) + LATENCY)
  const gapDb = 20 * Math.log10(gapOut / gapIn)
  check('the noise around the speech is still suppressed', gapDb < -6, `${gapDb.toFixed(1)} dB`)
}

// ---- 5. SNR improvement: the point of the whole exercise, and the measurement that decides
// where the default strength sits.
//
// Attenuation alone is a trap — any suppressor can make the noise quiet by damaging everything
// that shares a band with it, and past a certain oversubtraction that is exactly what happens:
// the noise floor keeps falling and the *speech-to-background* ratio starts falling with it.
// Asserted at the shipped default rather than at the maximum for that reason. The maximum is
// held to the weaker claim that it must not make things actively worse than the input.
{
  const n = SR * 3
  const sp = speech(n, 0.2, 0.5, 0.95)
  const nz = noise(n, 0.06)
  const mixed = new Float32Array(n)
  for (let i = 0; i < n; i++) mixed[i] = sp[i] + nz[i]
  const quiet = [SR, Math.floor(n * 0.45)]
  const loud = [Math.floor(n * 0.6), Math.floor(n * 0.9)]
  const snrIn = 20 * Math.log10(rms(mixed, ...loud) / rms(mixed, ...quiet))

  const snrOut = (params) => {
    const out = run(mixed, params)
    return 20 * Math.log10(
      rms(out, loud[0] + LATENCY, loud[1] + LATENCY) / rms(out, quiet[0] + LATENCY, quiet[1] + LATENCY),
    )
  }

  const atDefault = snrOut(DEFAULT)
  const atMax = snrOut(MAX)

  check(
    'signal-to-noise improves by >6dB at the default strength',
    atDefault - snrIn > 6,
    `${snrIn.toFixed(1)} dB -> ${atDefault.toFixed(1)} dB`,
  )
  check(
    'the maximum strength does not make signal-to-noise worse than the input',
    atMax >= snrIn,
    `${snrIn.toFixed(1)} dB -> ${atMax.toFixed(1)} dB`,
  )
}

let failed = 0
for (const r of results) {
  if (!r.pass) failed++
  console.log(`${r.pass ? 'PASS' : 'FAIL'}  ${r.name}  (${r.detail})`)
}
process.exit(failed ? 1 : 0)
