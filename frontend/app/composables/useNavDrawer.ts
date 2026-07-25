import { useMediaQuery } from '@vueuse/core'

/**
 * The sidebar, on a screen too narrow to hold it beside anything.
 *
 * Side Chat's shell was built for a desktop window: a resizable sidebar permanently next to
 * a channel. A phone can't show both, so below `md` the same sidebar becomes a drawer that
 * slides over the conversation — the markup doesn't change, only where it sits and whether
 * it's on screen.
 *
 * Shared state rather than per-component, because the two halves are far apart: the button
 * that opens the drawer lives in the channel header, the drawer itself in the layout.
 */
export function useNavDrawer() {
  const narrow = useMediaQuery('(max-width: 767px)')
  const open = useState('nav-drawer:open', () => false)

  // Going somewhere is the point of the drawer, so arriving closes it. On a wide screen
  // there's nothing to close — the sidebar is simply always there.
  const route = useRoute()
  watch(() => route.fullPath, () => { open.value = false })
  watch(narrow, (isNarrow) => { if (!isNarrow) open.value = false })

  return {
    narrow,
    open,
    toggle: () => { open.value = !open.value },
    close: () => { open.value = false },
  }
}
