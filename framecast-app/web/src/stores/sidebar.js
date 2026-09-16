import { defineStore } from 'pinia'

const STORAGE_KEY = 'fc_sidebar_collapsed'

// Below this the sidebar stops being a column and becomes an overlay. A phone
// is ~390px wide: a fixed 220px rail leaves about 170px for the actual app,
// which is why video was unwatchable and lists would not scroll properly.
const MOBILE = '(max-width: 860px)'

export function isMobileViewport() {
  return typeof window !== 'undefined' && window.matchMedia(MOBILE).matches
}

function applyWidth(collapsed) {
  // This writes an inline custom property, which beats any stylesheet — so the
  // media query cannot undo it and the zero has to come from here.
  document.documentElement.style.setProperty(
    '--sidebar-width',
    isMobileViewport() ? '0px' : collapsed ? '56px' : '220px'
  )
}

// Apply immediately on module load so there's no flash before the sidebar mounts
applyWidth(localStorage.getItem(STORAGE_KEY) === 'true')

// Rotating a phone, or dragging a desktop window narrow, has to re-decide.
if (typeof window !== 'undefined') {
  window.matchMedia(MOBILE).addEventListener('change', () => {
    applyWidth(localStorage.getItem(STORAGE_KEY) === 'true')
  })
}

export const useSidebarStore = defineStore('sidebar', {
  state: () => ({
    collapsed: localStorage.getItem(STORAGE_KEY) === 'true',
    // tracks state before an external override (e.g. timeline) so we can restore it
    _preOverride: null,
  }),

  actions: {
    toggle() {
      this.collapsed = !this.collapsed
      this._preOverride = null
      localStorage.setItem(STORAGE_KEY, String(this.collapsed))
      applyWidth(this.collapsed)
    },

    collapse() {
      if (!this.collapsed) {
        this._preOverride = false
        this.collapsed = true
        applyWidth(true)
      }
    },

    restore() {
      if (this._preOverride !== null) {
        this.collapsed = this._preOverride
        this._preOverride = null
        localStorage.setItem(STORAGE_KEY, String(this.collapsed))
        applyWidth(this.collapsed)
      }
    },

    applyStored() {
      applyWidth(this.collapsed)
    },
  },
})
