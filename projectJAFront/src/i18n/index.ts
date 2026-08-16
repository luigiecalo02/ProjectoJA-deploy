import { createI18n } from 'vue-i18n'
import es from '@/i18n/locales/es'

export const i18n = createI18n({
  legacy: false,
  locale: 'es',
  fallbackLocale: 'es',
  messages: {
    es,
  },
})
