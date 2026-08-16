import { definePreset } from '@primeuix/themes'
import Aura from '@primeuix/themes/aura'

/** Escala primary alineada al navy de los logos clubes. */
const navy = {
  50: '#eef4fb',
  100: '#d9e6f6',
  200: '#b4cceb',
  300: '#82abd9',
  400: '#4f84c2',
  500: '#2f63a8',
  600: '#1e4c8c',
  700: '#0b2f6b',
  800: '#092654',
  900: '#071e48',
  950: '#04122c',
}

export const ProjectJaPreset = definePreset(Aura, {
  semantic: {
    primary: navy,
    colorScheme: {
      light: {
        primary: {
          color: navy[700],
          inverseColor: '#ffffff',
          hoverColor: navy[800],
          activeColor: navy[900],
        },
        highlight: {
          background: 'rgba(255, 204, 0, 0.18)',
          focusBackground: 'rgba(255, 204, 0, 0.28)',
          color: navy[900],
          focusColor: navy[900],
        },
        surface: {
          0: '#ffffff',
          50: '{slate.50}',
          100: '{slate.100}',
          200: '{slate.200}',
          300: '{slate.300}',
          400: '{slate.400}',
          500: '{slate.500}',
          600: '{slate.600}',
          700: '{slate.700}',
          800: '{slate.800}',
          900: '{slate.900}',
          950: '{slate.950}',
        },
      },
      dark: {
        primary: {
          color: '#4f84c2',
          inverseColor: '#04122c',
          hoverColor: '#82abd9',
          activeColor: '#b4cceb',
        },
        highlight: {
          background: 'rgba(255, 204, 0, 0.16)',
          focusBackground: 'rgba(255, 204, 0, 0.24)',
          color: '#ffcc00',
          focusColor: '#ffcc00',
        },
        surface: {
          0: '#0b1220',
          50: '{slate.950}',
          100: '{slate.900}',
          200: '{slate.800}',
          300: '{slate.700}',
          400: '{slate.600}',
          500: '{slate.500}',
          600: '{slate.400}',
          700: '{slate.300}',
          800: '{slate.200}',
          900: '{slate.100}',
          950: '{slate.50}',
        },
      },
    },
  },
  components: {
    drawer: {
      root: {
        borderRadius: '0',
      },
      header: {
        // Evita el “marco” blanco: Aura usa overlay.modal.padding en el header.
        padding: '0.85rem 1.1rem',
      },
      content: {
        padding: '1rem 1.1rem',
      },
      footer: {
        padding: '0.85rem 1.1rem',
      },
    },
  },
})
