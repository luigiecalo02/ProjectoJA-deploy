import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

const MOBILE_QUERY = '(max-width: 959px)'

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

const deferredPrompt = ref<BeforeInstallPromptEvent | null>(null)
const installed = ref(false)
let listenersBound = false

function isStandalone(): boolean {
  return (
    window.matchMedia('(display-mode: standalone)').matches ||
    window.matchMedia('(display-mode: minimal-ui)').matches ||
    Boolean((window.navigator as Navigator & { standalone?: boolean }).standalone)
  )
}

function isIosDevice(): boolean {
  const agent = window.navigator.userAgent
  const iPhoneLike = /iphone|ipad|ipod/i.test(agent)
  const iPadOs = window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1
  return iPhoneLike || iPadOs
}

function onBeforeInstall(event: Event): void {
  event.preventDefault()
  deferredPrompt.value = event as BeforeInstallPromptEvent
}

function onInstalled(): void {
  installed.value = true
  deferredPrompt.value = null
}

function bindGlobalListeners(): void {
  if (listenersBound || typeof window === 'undefined') return
  listenersBound = true
  installed.value = isStandalone()
  window.addEventListener('beforeinstallprompt', onBeforeInstall)
  window.addEventListener('appinstalled', onInstalled)
}

bindGlobalListeners()

export function usePwaInstall() {
  const isMobile = ref(typeof window !== 'undefined' && window.matchMedia(MOBILE_QUERY).matches)
  const ios = ref(typeof window !== 'undefined' && isIosDevice())
  const helpOpen = ref(false)
  let viewport: MediaQueryList | null = null

  const visible = computed(() => isMobile.value && !installed.value)

  function syncViewport(): void {
    isMobile.value = Boolean(viewport?.matches)
    installed.value = isStandalone()
  }

  async function install(): Promise<void> {
    if (deferredPrompt.value) {
      await deferredPrompt.value.prompt()
      const choice = await deferredPrompt.value.userChoice
      if (choice.outcome === 'accepted') {
        onInstalled()
      }
      deferredPrompt.value = null
      return
    }

    helpOpen.value = true
  }

  onMounted(() => {
    bindGlobalListeners()
    viewport = window.matchMedia(MOBILE_QUERY)
    syncViewport()
    viewport.addEventListener('change', syncViewport)
  })

  onBeforeUnmount(() => {
    viewport?.removeEventListener('change', syncViewport)
  })

  return {
    visible,
    ios,
    helpOpen,
    install,
  }
}
