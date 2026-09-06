import { createApp } from 'vue'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import Tooltip from 'primevue/tooltip'
import { ProjectJaPreset } from '@/theme/preset'
import { i18n } from '@/i18n'
import router from '@/router'
import { useThemeStore } from '@/stores/theme'
import { useBrandStore } from '@/stores/brand'
import '@/composables/usePwaInstall'
import App from '@/App.vue'
import 'primeicons/primeicons.css'
import '@/assets/main.css'

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

void (async () => {
  const brand = useBrandStore(pinia)
  if (navigator.onLine) {
    await withTimeout(brand.load(), 3000).catch(() => undefined)
  }
  app.mount('#app')
})()
