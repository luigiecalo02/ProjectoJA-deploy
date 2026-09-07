import { createApp } from 'vue'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import Tooltip from 'primevue/tooltip'
import { registerSW } from 'virtual:pwa-register'
import { ProjectJaPreset } from '@/theme/preset'
import { i18n } from '@/i18n'
import router from '@/router'
import { useThemeStore } from '@/stores/theme'
import { useBrandStore } from '@/stores/brand'
import { hasUsableNetwork } from '@/utils/network'
import '@/composables/usePwaInstall'
import App from '@/App.vue'
import 'primeicons/primeicons.css'
import '@/assets/main.css'

registerSW({ immediate: true })

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)
app.use(i18n)
app.use(PrimeVue, {
  theme: {
    preset: ProjectJaPreset,
    options: {
      darkModeSelector: ':root.dark',
      cssLayer: false,
    },
  },
  ripple: true,
})
app.use(ToastService)
app.directive('tooltip', Tooltip)

useThemeStore(pinia).init()

function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T> {
  return new Promise((resolve, reject) => {
    const timer = window.setTimeout(() => reject(new Error('timeout')), ms)
    promise.then(
      (value) => {
        window.clearTimeout(timer)
        resolve(value)
      },
      (error) => {
        window.clearTimeout(timer)
        reject(error)
      },
    )
  })
}

const brand = useBrandStore(pinia)
app.mount('#app')
if (hasUsableNetwork()) {
  void withTimeout(brand.load(), 2000).catch(() => undefined)
}
