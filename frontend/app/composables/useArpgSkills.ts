import type { HeroJob, SkillDef } from '~/lib/arpgEngine'

/** One job in the tree, as the server describes it. */
export interface JobDef {
  name: string
  tier: number
  advances_from: string | null
  advances_to: string | null
}

/**
 * The skill catalogue and the job tree, fetched rather than hard-coded.
 *
 * The client fights the fight, so it would be natural to keep the skill table next to the engine.
 * It's served instead, because *learning* a skill is a durable decision the server has to referee
 * — it spends a point, it's gated on a job, and it's bounded by a per-tier inheritance cap — and
 * two copies of that table would drift the moment a number was tuned. So the engine implements six
 * kinds and this fetches what they're worth.
 *
 * The same goes for the job tree: when a third tier lands, it lands here as data.
 *
 * Global and immutable, hence the module-level cache: the catalogue is the same for every hero in
 * every room, and re-fetching it per panel would be one request per portal.
 */
const catalogue = ref<SkillDef[]>([])
const jobs = ref<Record<string, JobDef>>({})
const foreignLimits = ref<Record<number, number>>({ 1: 3, 2: 3 })
const maxSkillLevel = ref(10)
let loaded = false

export function useArpgSkills() {
  const api = useApi()

  async function load() {
    if (loaded) return
    try {
      const res = await api<{
        data: SkillDef[]
        meta: { jobs: Record<string, JobDef>, foreign_limits: Record<number, number>, max_skill_level: number }
      }>('/api/arpg/skills')

      catalogue.value = res.data
      jobs.value = res.meta.jobs
      foreignLimits.value = res.meta.foreign_limits
      maxSkillLevel.value = res.meta.max_skill_level
      loaded = true
    } catch {
      catalogue.value = []
    }
  }

  const byId = computed(() => new Map(catalogue.value.map(skill => [skill.id, skill])))

  const jobName = (job: HeroJob) => jobs.value[job]?.name ?? job

  /**
   * The whole line up to and including a job — every skill the hero counts as their own.
   *
   * Walked backwards through `advances_from`, the same way the server does it: a wizard has
   * necessarily been a mage, so there's no history to store.
   */
  function line(job: HeroJob): HeroJob[] {
    const out: HeroJob[] = []
    let at: string | null = job

    while (at && jobs.value[at]) {
      out.unshift(at)
      at = jobs.value[at]!.advances_from
    }

    return out.length ? out : [job]
  }

  /** Your own trees, in line order, each with its skills in unlock order. */
  function ownTrees(job: HeroJob) {
    return line(job).map(id => ({
      job: id,
      name: jobName(id),
      skills: catalogue.value.filter(s => s.job === id).sort((a, b) => a.level - b.level),
    }))
  }

  /**
   * Everything borrowable, grouped by job and split by tier.
   *
   * Split because the cap is per tier: mixing a wizard's Meteor into the same list as a priest's
   * Heal would hide the fact that they're spending different allowances.
   */
  function foreignTrees(job: HeroJob, tier: number) {
    const mine = new Set(line(job))

    return Object.entries(jobs.value)
      .filter(([id, def]) => !mine.has(id) && def.tier === tier)
      .map(([id, def]) => ({
        job: id,
        name: def.name,
        skills: catalogue.value.filter(s => s.job === id).sort((a, b) => a.level - b.level),
      }))
      .filter(group => group.skills.length > 0)
  }

  /** How many skills of one tier this hero borrowed — exactly what that tier's cap counts. */
  function foreignCount(known: Record<string, number>, job: HeroJob, tier: number) {
    const mine = new Set(line(job))

    return Object.keys(known).filter((id) => {
      const def = byId.value.get(id)

      return def !== undefined && !mine.has(def.job) && def.tier === tier
    }).length
  }

  /** The tiers a hero can currently touch at all — 1, or 1 and 2 once they've advanced. */
  function tiersOpenTo(job: HeroJob) {
    const tier = jobs.value[job]?.tier ?? 1

    return Array.from({ length: tier }, (_, i) => i + 1)
  }

  return {
    catalogue,
    jobs,
    foreignLimits,
    maxSkillLevel,
    byId,
    load,
    jobName,
    line,
    ownTrees,
    foreignTrees,
    foreignCount,
    tiersOpenTo,
  }
}
