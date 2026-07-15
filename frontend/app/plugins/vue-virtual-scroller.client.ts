import { DynamicScroller, DynamicScrollerItem, RecycleScroller } from 'vue-virtual-scroller'

// Registered client-side only (the library relies on ResizeObserver); scroller usage
// in templates is wrapped in <ClientOnly>.
export default defineNuxtPlugin((nuxtApp) => {
  nuxtApp.vueApp.component('RecycleScroller', RecycleScroller)
  nuxtApp.vueApp.component('DynamicScroller', DynamicScroller)
  nuxtApp.vueApp.component('DynamicScrollerItem', DynamicScrollerItem)
})
