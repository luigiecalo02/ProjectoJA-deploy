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
      darkModeSelector: '.dark',
      cssLayer: false,
    },
  },
  ripple: true,
})
app.use(ToastService)
app.directive('tooltip', Tooltip)

useThemeStore(pinia).init()

void (async () => {
  await useBrandStore(pinia).load()
  app.mount('#app')
})()
