import type {
  Automation,
  AutomationCatalogue,
  Badge,
  Bot,
  BotAuditLine,
  BotOverview,
  BotSchedule,
  BotSettings,
  BotWelcome,
  CustomCommand,
  Giveaway,
  ReactionRoleGroup,
} from '~/types'

/**
 * Everything the bot dashboard reads and writes, for one server.
 *
 * Deliberately *not* a `useState` singleton like useServer. The dashboard is a dialog: it
 * opens on one server, it is the only thing looking at this data, and it closes. Shared
 * global state would mean a stale rule list surviving a close-and-reopen on a different
 * server — and would buy nothing, because nothing else on screen renders automations.
 *
 * The catalogue is the exception to "fetch what you need": it's fetched once alongside the
 * rules because the builder cannot render a single action form without it.
 */
export function useBotDashboard(serverId: number) {
  const api = useApi()

  const overview = ref<BotOverview | null>(null)
  const automations = ref<Automation[]>([])
  const catalogue = ref<AutomationCatalogue | null>(null)
  const badges = ref<Badge[]>([])
  const settings = ref<BotSettings | null>(null)
  const welcome = ref<BotWelcome | null>(null)
  const commands = ref<CustomCommand[]>([])
  const schedules = ref<BotSchedule[]>([])
  const reactionRoles = ref<ReactionRoleGroup[]>([])
  const giveaways = ref<Giveaway[]>([])
  const log = ref<BotAuditLine[]>([])

  const loading = ref(true)
  const error = ref<string | null>(null)

  const base = `/api/servers/${serverId}`

  /**
   * The rules the generic Automations list shows.
   *
   * Built-ins are filtered out here rather than by the API: the welcome message is a real
   * rule and the feature pages need to read it, it just shouldn't appear twice on screen.
   */
  const customAutomations = computed(() => automations.value.filter(a => a.builtin === null))
  const builtinAutomations = computed(() => automations.value.filter(a => a.builtin !== null))

  /**
   * Everything, in parallel.
   *
   * One `Promise.all` rather than per-page loading because the sidebar shows counts for
   * pages you haven't opened yet — the Overview needs the rules, the Automations page needs
   * the badges (to name one in an action), and lazy-loading each would make every tab click
   * a spinner.
   */
  async function load() {
    loading.value = true
    error.value = null
    try {
      const [o, a, c, b, s, w, cmd, sch, rr, gv] = await Promise.all([
        api<{ data: BotOverview }>(`${base}/bot/overview`),
        api<{ data: Automation[] }>(`${base}/automations`),
        api<{ data: AutomationCatalogue }>(`${base}/bot/catalogue`),
        api<{ data: Badge[] }>(`${base}/badges`),
        api<{ data: BotSettings }>(`${base}/bot/settings`),
        api<{ data: BotWelcome }>(`${base}/bot/welcome`),
        api<{ data: CustomCommand[] }>(`${base}/commands`),
        api<{ data: BotSchedule[] }>(`${base}/schedules`),
        api<{ data: ReactionRoleGroup[] }>(`${base}/reaction-roles`),
        api<{ data: Giveaway[] }>(`${base}/giveaways`),
      ])
      overview.value = o.data
      automations.value = a.data
      catalogue.value = c.data
      badges.value = b.data
      settings.value = s.data
      welcome.value = w.data
      commands.value = cmd.data
      schedules.value = sch.data
      reactionRoles.value = rr.data
      giveaways.value = gv.data
    }
    catch {
      error.value = 'Couldn\'t load this server\'s bot settings.'
    }
    finally {
      loading.value = false
    }
  }

  /** Create or replace a rule. Actions go up as a whole ordered list — see the API. */
  async function saveAutomation(draft: Partial<Automation>): Promise<Automation> {
    const payload = {
      name: draft.name,
      trigger: draft.trigger,
      trigger_config: draft.trigger_config ?? {},
      conditions: draft.conditions ?? [],
      enabled: draft.enabled ?? true,
      actions: (draft.actions ?? []).map(a => ({ type: a.type, config: a.config ?? {} })),
    }

    const res = draft.id
      ? await api<{ data: Automation }>(`${base}/automations/${draft.id}`, { method: 'PUT', body: payload })
      : await api<{ data: Automation }>(`${base}/automations`, { method: 'POST', body: payload })

    upsert(res.data)

    return res.data
  }

  async function toggleAutomation(automation: Automation) {
    const res = await api<{ data: Automation }>(`${base}/automations/${automation.id}/toggle`, { method: 'POST' })
    upsert(res.data)
  }

  async function deleteAutomation(automation: Automation) {
    await api(`${base}/automations/${automation.id}`, { method: 'DELETE' })
    automations.value = automations.value.filter(a => a.id !== automation.id)
  }

  /**
   * Run a rule now, against the person who pressed the button.
   *
   * It runs for real — it posts the message, it grants the badge. A dry run would be
   * testing the preview rather than the rule, and the failures worth catching (a deleted
   * channel, a bot without access) only appear on the real path. The overview is refetched
   * after, so the audit line lands on screen.
   */
  async function runAutomation(automation: Automation) {
    await api(`${base}/automations/${automation.id}/run`, { method: 'POST' })
    await refreshOverview()
  }

  async function refreshOverview() {
    const res = await api<{ data: BotOverview }>(`${base}/bot/overview`)
    overview.value = res.data
  }

  async function saveSettings(patch: Partial<BotSettings>) {
    const res = await api<{ data: BotSettings }>(`${base}/bot/settings`, { method: 'PUT', body: patch })
    settings.value = res.data
  }

  async function saveWelcome(patch: Partial<BotWelcome>) {
    const res = await api<{ data: BotWelcome }>(`${base}/bot/welcome`, { method: 'PUT', body: patch })
    welcome.value = res.data
    // The welcome *is* a rule, so saving it changes the rule list — reload it rather than
    // letting the two views of the same row disagree.
    const rules = await api<{ data: Automation[] }>(`${base}/automations`)
    automations.value = rules.data
  }

  async function saveCommand(draft: Partial<CustomCommand>) {
    const body = {
      name: draft.name,
      kind: draft.kind ?? 'both',
      description: draft.description || null,
      response: draft.response,
      required_badge_id: draft.required_badge_id || null,
      cooldown_seconds: draft.cooldown_seconds ?? 0,
      enabled: draft.enabled ?? true,
    }

    const res = draft.id
      ? await api<{ data: CustomCommand }>(`${base}/commands/${draft.id}`, { method: 'PATCH', body })
      : await api<{ data: CustomCommand }>(`${base}/commands`, { method: 'POST', body })

    const index = commands.value.findIndex(c => c.id === res.data.id)
    if (index === -1) commands.value = [...commands.value, res.data].sort((a, b) => a.name.localeCompare(b.name))
    else commands.value[index] = res.data
  }

  async function deleteCommand(command: CustomCommand) {
    await api(`${base}/commands/${command.id}`, { method: 'DELETE' })
    commands.value = commands.value.filter(c => c.id !== command.id)
  }

  async function saveSchedule(draft: Partial<BotSchedule>) {
    const body = {
      name: draft.name,
      channel_id: draft.channel_id || null,
      body: draft.body,
      cron: draft.cron,
      timezone: draft.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
      enabled: draft.enabled ?? true,
    }

    const res = draft.id
      ? await api<{ data: BotSchedule }>(`${base}/schedules/${draft.id}`, { method: 'PATCH', body })
      : await api<{ data: BotSchedule }>(`${base}/schedules`, { method: 'POST', body })

    const index = schedules.value.findIndex(s => s.id === res.data.id)
    if (index === -1) schedules.value = [...schedules.value, res.data].sort((a, b) => a.name.localeCompare(b.name))
    else schedules.value[index] = res.data
  }

  async function toggleSchedule(schedule: BotSchedule) {
    const res = await api<{ data: BotSchedule }>(`${base}/schedules/${schedule.id}/toggle`, { method: 'POST' })
    const index = schedules.value.findIndex(s => s.id === res.data.id)
    if (index !== -1) schedules.value[index] = res.data
  }

  /**
   * Send a schedule now. Doesn't move its clock — the Monday post is still due on Monday.
   *
   * Returns the reason it didn't send, if it didn't: the action's own words ("the bot isn't
   * in #private") are more use than a generic failure.
   */
  async function runSchedule(schedule: BotSchedule): Promise<string | null> {
    const res = await api<{ data: { sent: boolean, reason: string | null } }>(
      `${base}/schedules/${schedule.id}/run`,
      { method: 'POST' },
    )
    await refreshOverview()

    return res.data.sent ? null : (res.data.reason ?? 'It didn\'t send.')
  }

  async function deleteSchedule(schedule: BotSchedule) {
    await api(`${base}/schedules/${schedule.id}`, { method: 'DELETE' })
    schedules.value = schedules.value.filter(s => s.id !== schedule.id)
  }

  async function saveReactionRole(draft: { channel_id: number, body: string, pairs: { emoji: string, badge_id: number }[] }) {
    const res = await api<{ data: ReactionRoleGroup[] }>(`${base}/reaction-roles`, { method: 'POST', body: draft })
    reactionRoles.value = res.data
  }

  async function deleteReactionRole(group: ReactionRoleGroup) {
    await api(`${base}/reaction-roles/${group.message_id}`, { method: 'DELETE' })
    reactionRoles.value = reactionRoles.value.filter(g => g.message_id !== group.message_id)
  }

  async function saveGiveaway(draft: Partial<Giveaway> & { ends_at: string }) {
    const res = await api<{ data: Giveaway }>(`${base}/giveaways`, {
      method: 'POST',
      body: {
        channel_id: draft.channel_id,
        prize: draft.prize,
        emoji: draft.emoji || '🎉',
        winner_count: draft.winner_count ?? 1,
        required_badge_id: (draft as any).required_badge_id || null,
        ends_at: draft.ends_at,
      },
    })
    giveaways.value = [res.data, ...giveaways.value]
  }

  async function drawGiveaway(giveaway: Giveaway) {
    const res = await api<{ data: Giveaway }>(`${base}/giveaways/${giveaway.id}/draw`, { method: 'POST' })
    const index = giveaways.value.findIndex(g => g.id === res.data.id)
    if (index !== -1) giveaways.value[index] = res.data
  }

  /** Cancels rather than deletes — people entered, and the record is more honest. */
  async function cancelGiveaway(giveaway: Giveaway) {
    await api(`${base}/giveaways/${giveaway.id}`, { method: 'DELETE' })
    const index = giveaways.value.findIndex(g => g.id === giveaway.id)
    if (index !== -1) giveaways.value[index] = { ...giveaway, status: 'cancelled' }
  }

  /**
   * The Logging page, fetched on demand rather than with everything else.
   *
   * Unlike the rest of the dashboard this is paged and potentially large, and most visits
   * never open it — loading fifty audit lines to render an Overview nobody asked about
   * would be the one genuinely wasteful part of the initial load.
   */
  async function loadLog(filters: { outcome?: string } = {}) {
    const query = filters.outcome ? `?outcome=${filters.outcome}` : ''
    const res = await api<{ data: BotAuditLine[] }>(`${base}/bot/log${query}`)
    log.value = res.data
  }

  async function saveBadge(draft: Partial<Badge>) {
    const body = {
      name: draft.name,
      emoji: draft.emoji || null,
      color: draft.color || null,
      description: draft.description || null,
    }

    const res = draft.id
      ? await api<{ data: Badge }>(`${base}/badges/${draft.id}`, { method: 'PATCH', body })
      : await api<{ data: Badge }>(`${base}/badges`, { method: 'POST', body })

    const index = badges.value.findIndex(b => b.id === res.data.id)
    if (index === -1) badges.value = [...badges.value, res.data].sort((a, b) => a.name.localeCompare(b.name))
    else badges.value[index] = res.data
  }

  async function deleteBadge(badge: Badge) {
    await api(`${base}/badges/${badge.id}`, { method: 'DELETE' })
    badges.value = badges.value.filter(b => b.id !== badge.id)
  }

  /** Make this bot the one automations speak as, taking it off whichever had it. */
  async function setAutomationBot(bot: Bot) {
    await api(`${base}/bots/${bot.id}/automations`, { method: 'PUT' })
    await refreshOverview()
  }

  function upsert(automation: Automation) {
    const index = automations.value.findIndex(a => a.id === automation.id)
    if (index === -1) automations.value = [automation, ...automations.value]
    else automations.value[index] = automation
  }

  return {
    overview,
    automations,
    customAutomations,
    builtinAutomations,
    catalogue,
    badges,
    settings,
    welcome,
    commands,
    schedules,
    reactionRoles,
    giveaways,
    log,
    loading,
    error,
    load,
    saveAutomation,
    toggleAutomation,
    deleteAutomation,
    runAutomation,
    refreshOverview,
    saveSettings,
    saveWelcome,
    saveBadge,
    deleteBadge,
    saveCommand,
    deleteCommand,
    saveSchedule,
    saveReactionRole,
    deleteReactionRole,
    saveGiveaway,
    drawGiveaway,
    cancelGiveaway,
    loadLog,
    toggleSchedule,
    runSchedule,
    deleteSchedule,
    setAutomationBot,
  }
}
