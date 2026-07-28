import type { InjectionKey } from 'vue'

/**
 * Which *copy* of the channel-scoped stores a component is looking at.
 *
 * A handful of things the channel view needs are held in `useState` so that the timeline,
 * the panels and the header badge all read one list rather than three that can disagree:
 * the side chats, the threads, the pins, the forum groups. Sharing them is the whole point
 * — until there are two channel views on screen, at which point "one list" becomes "the
 * channel that loaded last wins" and the docked pane shows the main column's side chats.
 *
 * The fix is a prefix on the state keys, injected rather than passed: the main column
 * provides nothing and gets `''`, so its keys are exactly what they always were and nothing
 * about it changes; the split view's dock provides `'split:'` and gets a parallel set.
 *
 * Deliberately a scope *name* and not a channel id. Keying by channel would leave a store
 * behind for every channel ever opened — these are caches of "the channel I'm looking at",
 * and there are only ever as many of those as there are columns.
 */
export const channelScopeKey: InjectionKey<string> = Symbol('channelScope')

/** The prefix for this component's channel-scoped state keys. `''` in the main column. */
export function useChannelScope(): string {
  return inject(channelScopeKey, '')
}

/** Give everything below its own copy of the channel-scoped stores. */
export function provideChannelScope(name: string) {
  provide(channelScopeKey, name)
}
