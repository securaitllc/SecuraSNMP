import type { ThemeDefinition } from 'vuetify'

/**
 * UI accent — blue.
 *
 * The T568B green (#2BA24E) is still the brand: it stays on the mark and it stays as
 * the colour of "up". What it stopped being is the interface accent, because a green
 * that also means "circuit healthy" cannot simultaneously mean "this control is
 * primary" — severity is load-bearing in this app, so the accent has to sit outside
 * the red/amber/green vocabulary entirely.
 *
 * Blue rather than indigo: the indigo carried a purple cast that read as a product
 * accent rather than infrastructure software.
 */
export const staticPrimaryColor = '#2563EB'
export const staticPrimaryDarkenColor = '#1D4ED8'

/** The brand green, kept for the mark and for anything that means "healthy". */
export const brandGreen = '#2BA24E'

export const themes: Record<string, ThemeDefinition> = {
  light: {
    dark: false,
    colors: {
      'primary': staticPrimaryColor,
      'on-primary': '#fff',
      'primary-darken-1': staticPrimaryDarkenColor,
      'secondary': '#6B7590',
      'secondary-darken-1': '#5A6478',
      'on-secondary': '#fff',
      'success': '#16A34A',
      'success-darken-1': '#12833C',
      'on-success': '#fff',
      'info': '#0EA5E9',
      'info-darken-1': '#0C93D1',
      'on-info': '#fff',
      'warning': '#D97706',
      'warning-darken-1': '#C26A05',
      'on-warning': '#fff',
      'error': '#E5484D',
      'error-darken-1': '#CE3F44',
      'on-error': '#fff',
      // Cool off-white ground with white cards lifted off it. The ground is what
      // gives the elevated look somewhere to sit — a white page under white cards
      // has nothing to separate them but shadow, which reads muddy.
      'background': '#F5F7FA',
      'on-background': '#1A2233',
      'surface': '#FFFFFF',
      'on-surface': '#1A2233',
      'grey-50': '#FAFAFA',
      'grey-100': '#F5F5F5',
      'grey-200': '#EEEEEE',
      'grey-300': '#E0E0E0',
      'grey-400': '#BDBDBD',
      'grey-500': '#9E9E9E',
      'grey-600': '#757575',
      'grey-700': '#616161',
      'grey-800': '#424242',
      'grey-900': '#212121',
      'perfect-scrollbar-thumb': '#dbdade',
      'skin-bordered-background': '#fff',
      'skin-bordered-surface': '#fff',
      'expansion-panel-text-custom-bg': '#fafafa',
      'track-bg': '#FAFAFD',
      'chat-bg': '#F7F6FA',
    },

    variables: {
      'code-color': '#d400ff',
      'overlay-scrim-background': '#262B43',
      'tooltip-background': '#282A42',
      'overlay-scrim-opacity': 0.5,
      'hover-opacity': 0.06,
      'focus-opacity': 0.1,
      'selected-opacity': 0.08,
      'activated-opacity': 0.16,
      'pressed-opacity': 0.14,
      'dragged-opacity': 0.1,
      'disabled-opacity': 0.4,
      'border-color': '#1A2233',
      'border-opacity': 0.1,
      'table-header-color': '#FAFBFD',
      'high-emphasis-opacity': 0.9,
      'medium-emphasis-opacity': 0.7,

      // 👉 shadows
      // Two-layer, low-opacity elevation. The old values were heavy enough that
      // cards read as stamped onto the page; the lift should be felt, not seen.
      'shadow-key-umbra-color': '#101828',
      'shadow-xs-opacity': '0.04',
      'shadow-sm-opacity': '0.06',
      'shadow-md-opacity': '0.08',
      'shadow-lg-opacity': '0.10',
      'shadow-xl-opacity': '0.12',
    },
  },

  dark: {
    dark: true,
    colors: {
      'primary': staticPrimaryColor,
      'on-primary': '#fff',
      'primary-darken-1': staticPrimaryDarkenColor,
      'secondary': '#6B7590',
      'secondary-darken-1': '#5A6478',
      'on-secondary': '#fff',
      'success': '#16A34A',
      'success-darken-1': '#12833C',
      'on-success': '#fff',
      'info': '#0EA5E9',
      'info-darken-1': '#0C93D1',
      'on-info': '#fff',
      'warning': '#D97706',
      'warning-darken-1': '#C26A05',
      'on-warning': '#fff',
      'error': '#E5484D',
      'error-darken-1': '#CE3F44',
      'on-error': '#fff',
      // Neutral graphite (Nodus ink family) instead of purple-navy — cleaner,
      // more legible, and screenshots for ISP tickets read far better.
      'background': '#12151C',
      'on-background': '#E4E8ED',
      'surface': '#1A1E27',
      'on-surface': '#E4E8ED',
      'grey-50': '#1B2029',
      'grey-100': '#232833',
      'grey-200': '#4A5072',
      'grey-300': '#5E6692',
      'grey-400': '#7983BB',
      'grey-500': '#8692D0',
      'grey-600': '#AAB3DE',
      'grey-700': '#B6BEE3',
      'grey-800': '#CFD3EC',
      'grey-900': '#E7E9F6',
      'perfect-scrollbar-thumb': '#2E3540',
      'skin-bordered-background': '#20252E',
      'skin-bordered-surface': '#20252E',
      'expansion-panel-text-custom-bg': '#262C36',
      'track-bg': '#2A303B',
      'chat-bg': '#242A34',
    },

    variables: {
      'code-color': '#d400ff',
      'overlay-scrim-background': '#0C0F14',
      'tooltip-background': '#F5F5F5',
      'overlay-scrim-opacity': 0.6,
      'hover-opacity': 0.06,
      'focus-opacity': 0.1,
      'selected-opacity': 0.08,
      'activated-opacity': 0.16,
      'pressed-opacity': 0.14,
      'disabled-opacity': 0.4,
      'dragged-opacity': 0.1,
      'border-color': '#C8D2E0',
      'border-opacity': 0.12,
      'table-header-color': '#1F242E',
      'high-emphasis-opacity': 0.9,
      'medium-emphasis-opacity': 0.7,

      // 👉 Shadows
      'shadow-key-umbra-color': '#101121',
      'shadow-xs-opacity': '0.20',
      'shadow-sm-opacity': '0.24',
      'shadow-md-opacity': '0.26',
      'shadow-lg-opacity': '0.28',
      'shadow-xl-opacity': '0.30',
    },
  },
}

export default themes
