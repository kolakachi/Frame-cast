import { defineStore } from 'pinia'

const STORAGE_KEY = 'fc_sidebar_collapsed'

function applyWidth(collapsed) {
  document.documentElement.style.setProperty(
    '--sidebar-width',
    collapsed ? '56px' : '220px'
  )
}

// Apply immediately on module load so there's no flash before the sidebar mounts
applyWidth(localStorage.getItem(STORAGE_KEY) === 'true')

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
