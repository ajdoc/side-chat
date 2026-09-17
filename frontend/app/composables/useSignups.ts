import type { SignupEditorPayload, SignupGroup, SignupPayables, SignupPerson, SignupSchedule, SignupSheet, SignupYear } from '~/types'

/**
 * A channel's Sign-ups — the wall of sheets for a year, the open sheet, and the roster.
 *
 * Same contract as {@link useAppPolls}: state in a {@link useSurfaceStore} so the channel view
 * and a floating window are one list. It rides `TrackerChanged` under the `signup` subject, but
 * the payload is only a sheet id — a sheet can hold any number of names, so the broadcast is a
 * reference and this re-reads (see the "never broadcast an unbounded collection" rule).
 */
export function useSignups(basePath: string, streamName: string) {
  const api = useApi()
  const echo: any = useNuxtApp().$echo
  const { hold, release } = useEchoStream()

  const { state, attach } = useSurfaceStore('signups', basePath, () => ({
    sheets: ref<SignupSheet[]>([]),
    years: ref<SignupYear[]>([]),
    year: ref<number | null>(null),
    current: ref<SignupSheet | null>(null),
    roster: ref<SignupPerson[]>([]),
    groups: ref<SignupGroup[]>([]),
    schedules: ref<SignupSchedule[]>([]),
    canManage: ref(false),
    loaded: ref(false),
    /** Bumped when someone else marks a payment, so an open payables view re-reads. */
    payablesTick: ref(0),
  }))

  const { sheets, years, year, current, roster, groups, schedules, canManage, loaded, payablesTick } = state

  const headers = () => ({ 'X-Socket-ID': echo?.socketId() ?? '' })

  async function load(forYear: number | null = year.value) {
    const res = await api<{
      year: number
      years: SignupYear[]
      sheets: SignupSheet[]
      groups: SignupGroup[]
      schedules: SignupSchedule[]
      can_manage: boolean
    }>(
      `${basePath}/signups`, { query: forYear ? { year: forYear } : {} },
    )
    sheets.value = res.sheets
    years.value = res.years
    year.value = res.year
    groups.value = res.groups
    schedules.value = res.schedules
    canManage.value = res.can_manage
    loaded.value = true
  }

  async function loadRoster() {
    const res = await api<{ data: SignupPerson[] }>(`${basePath}/signups/people`)
    roster.value = res.data
  }

  /** Put a fresh copy of a sheet in both the open slot and the wall. */
  function adopt(sheet: SignupSheet) {
    if (current.value?.id === sheet.id) current.value = sheet
    const counts: Record<string, number> = {}
    for (const e of sheet.entries ?? []) counts[e.column_key] = (counts[e.column_key] ?? 0) + 1
    const summary = { ...sheet, counts, entries: undefined }
    const idx = sheets.value.findIndex(s => s.id === sheet.id)
    if (idx !== -1) sheets.value.splice(idx, 1, summary)
    else if (sheet.year === year.value) sheets.value = [summary, ...sheets.value]
  }

  async function openSheet(id: number) {
    if (current.value?.id !== id) current.value = null
    const res = await api<{ data: SignupSheet }>(`${basePath}/signups/sheets/${id}`)
    current.value = res.data
    return res.data
  }

  function closeSheet() {
    current.value = null
  }

  async function createSheet(input: SignupEditorPayload) {
    const res = await api<{ data: SignupSheet }>(`${basePath}/signups/sheets`, {
      method: 'POST', body: input, headers: headers(),
    })
    // A sheet dated into another year lands there; follow it so it doesn't seem to vanish.
    await load(res.data.year)
    current.value = res.data
    return res.data
  }

  /** Copy a sheet to one or more dates. Lands on the year of the first copy, which it opens. */
  async function duplicateSheet(id: number, input: { title: string | null, dates: string[] | null, group_id: number | null, with_names: boolean }) {
    const res = await api<{ data: SignupSheet, created: number, ids: number[] }>(`${basePath}/signups/sheets/${id}/duplicate`, {
      method: 'POST', body: input, headers: headers(),
    })
    await load(res.data.year)
    current.value = res.data
    return res
  }

  async function updateSheet(id: number, changes: Partial<SignupSheet> | SignupEditorPayload) {
    const res = await api<{ data: SignupSheet, applied: number }>(`${basePath}/signups/sheets/${id}`, {
      method: 'PATCH', body: changes, headers: headers(),
    })
    // Other sheets (and the schedule) changed too, or this one left the year: re-read the wall.
    if (res.applied || res.data.year !== year.value) await load(year.value)
    adopt(res.data)
    return res
  }

  async function deleteSheet(id: number) {
    await api(`${basePath}/signups/sheets/${id}`, { method: 'DELETE', headers: headers() })
    if (current.value?.id === id) current.value = null
    await load()
  }

  /** Write (or, with a blank name, clear) one slot. */
  async function setSlot(sheetId: number, columnKey: string, position: number, name: string, note: string | null = null) {
    const res = await api<{ data: SignupSheet }>(`${basePath}/signups/sheets/${sheetId}/slot`, {
      method: 'PUT', body: { column_key: columnKey, position, name, note }, headers: headers(),
    })
    adopt(res.data)
    return res.data
  }

  async function setArchived(forYear: number, archived: boolean) {
    await api(`${basePath}/signups/archive`, {
      method: 'POST', body: { year: forYear, archived }, headers: headers(),
    })
    await load(forYear)
  }

  async function suggest(q: string) {
    const res = await api<{ data: SignupPerson[] }>(`${basePath}/signups/people`, { query: { q } })
    return res.data
  }

  async function renamePerson(id: number, name: string) {
    await api(`${basePath}/signups/people/${id}`, { method: 'PATCH', body: { name }, headers: headers() })
    await loadRoster()
    if (current.value) await openSheet(current.value.id)
  }

  async function deletePerson(id: number) {
    await api(`${basePath}/signups/people/${id}`, { method: 'DELETE', headers: headers() })
    roster.value = roster.value.filter(p => p.id !== id)
  }

  async function payables(query: { month?: string, year?: number }) {
    return api<SignupPayables>(`${basePath}/signups/payables`, { query })
  }

  async function markPaid(ids: number[], paid: boolean) {
    await api(`${basePath}/signups/payables/paid`, {
      method: 'POST', body: { ids, paid }, headers: headers(),
    })
  }

  // --- groups ---------------------------------------------------------------------------------

  async function addGroup(name: string, color: string) {
    await api(`${basePath}/signups/groups`, { method: 'POST', body: { name, color }, headers: headers() })
    await load()
  }

  async function updateGroup(id: number, changes: { name?: string, color?: string }) {
    await api(`${basePath}/signups/groups/${id}`, { method: 'PATCH', body: changes, headers: headers() })
    await load()
  }

  async function reorderGroups(ids: number[]) {
    groups.value = ids.map(id => groups.value.find(g => g.id === id)!).filter(Boolean)
    await api(`${basePath}/signups/groups/order`, { method: 'PUT', body: { ids }, headers: headers() })
  }

  async function deleteGroup(id: number) {
    await api(`${basePath}/signups/groups/${id}`, { method: 'DELETE', headers: headers() })
    await load()
  }

  // --- schedules ------------------------------------------------------------------------------

  /** The server's own answer for which dates a rule gives — the form never re-implements it. */
  async function previewSchedule(weekdays: number[], weeks: number[] | null, month: string) {
    const res = await api<{ dates: string[] }>(`${basePath}/signups/schedules/preview`, {
      method: 'POST', body: { weekdays, weeks, month },
    })
    return res.dates
  }

  async function createSchedule(input: SignupEditorPayload & { weekdays: number[] }) {
    const res = await api<{ data: SignupSchedule, created: number, first_sheet_id: number | null }>(
      `${basePath}/signups/schedules`, { method: 'POST', body: input, headers: headers() },
    )
    await load(input.month ? Number(input.month.slice(0, 4)) : year.value)
    return res
  }

  async function updateSchedule(id: number, changes: Partial<SignupSchedule> | SignupEditorPayload) {
    const res = await api<{ data: SignupSchedule, applied: number }>(`${basePath}/signups/schedules/${id}`, {
      method: 'PATCH', body: changes, headers: headers(),
    })
    await load()
    return res
  }

  async function generate(id: number, month: string) {
    const res = await api<{ created: number, year: number }>(`${basePath}/signups/schedules/${id}/generate`, {
      method: 'POST', body: { month }, headers: headers(),
    })
    await load(res.year)
    return res
  }

  async function deleteSchedule(id: number) {
    await api(`${basePath}/signups/schedules/${id}`, { method: 'DELETE', headers: headers() })
    await load()
  }

  /** Copy a month's sheets into the next one, names left behind. */
  async function copyMonth(month: string, groupId: number | null) {
    const res = await api<{ month: string, year: number, created: number }>(`${basePath}/signups/copy-month`, {
      method: 'POST', body: { month, group_id: groupId }, headers: headers(),
    })
    await load(res.year)
    return res
  }

  function open() {
    attach(() => {
      void load()

      if (!echo) return
      const channel = hold(streamName)
      const onChange = (e: { subject: string, action: string, payload: { id: number | null } }) => {
        if (e.subject === 'signup_payables') {
          payablesTick.value++
          return
        }
        if (e.subject !== 'signup') return
        void load()
        payablesTick.value++
        const openId = current.value?.id
        if (openId == null || (e.payload.id !== null && e.payload.id !== openId)) return
        if (e.action === 'removed') current.value = null
        else void openSheet(openId)
      }
      channel.listen('.TrackerChanged', onChange)

      return () => {
        // Only our handler: the tracker and polls listen for the same event on this stream.
        channel.stopListening('.TrackerChanged', onChange)
        release(streamName)
      }
    })
  }

  return {
    sheets, years, year, current, roster, groups, schedules, canManage, loaded, payablesTick,
    addGroup, updateGroup, reorderGroups, deleteGroup,
    previewSchedule, createSchedule, updateSchedule, generate, deleteSchedule, copyMonth,
    open, load, loadRoster, openSheet, closeSheet, createSheet, duplicateSheet, updateSheet,
    deleteSheet, setSlot, setArchived, suggest, renamePerson, deletePerson, payables, markPaid,
  }
}
