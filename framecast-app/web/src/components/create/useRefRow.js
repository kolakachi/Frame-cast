import { ref, onMounted, onBeforeUnmount } from 'vue'
// A sideways-scrolling row of pills: each edge fades only when there is more to scroll that way.
export function useRefRow() {
  const row = ref(null), fadeStart = ref(false), fadeEnd = ref(false)
  function update() {
    const el = row.value; if (!el) return
    fadeStart.value = el.scrollLeft > 2
    fadeEnd.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 2
  }
  let observer
  onMounted(() => { update(); if (window.ResizeObserver && row.value) { observer = new ResizeObserver(update); observer.observe(row.value) } })
  onBeforeUnmount(() => observer?.disconnect())
  return { row, fadeStart, fadeEnd, update }
}
