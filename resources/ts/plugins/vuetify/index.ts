import { deepMerge } from '@antfu/utils'
import type { App } from 'vue'
import { createVuetify } from 'vuetify'
import { VBtn } from 'vuetify/components/VBtn'
import { VVideo } from 'vuetify/labs/VVideo'
import defaults from './defaults'
import { icons } from './icons'
import { staticPrimaryColor, staticPrimaryDarkenColor, themes } from './theme'
import { themeConfig } from '@themeConfig'

// Styles
import { cookieRef } from '@/@layouts/stores/config'
import '@core-scss/template/libs/vuetify/index.scss'
import 'vuetify/styles'

export default function (app: App) {
  // The accent cookie is VERSIONED, and the suffix must be bumped whenever the
  // shipped accent changes.
  //
  // These cookies win over the theme file, so every browser that had ever loaded
  // the app held the previous accent and would have kept rendering it — the
  // redesign would simply not have appeared for anyone but a first-time visitor,
  // with nothing to indicate why. Bumping the key retires the stored value.
  const cookieThemeValues = {
    defaultTheme: resolveVuetifyTheme(themeConfig.app.theme),
    themes: {
      light: {
        colors: {
          'primary': cookieRef('lightThemePrimaryColorV3', staticPrimaryColor).value,
          'primary-darken-1': cookieRef('lightThemePrimaryDarkenColorV3', staticPrimaryDarkenColor).value,
        },
      },
      dark: {
        colors: {
          'primary': cookieRef('darkThemePrimaryColorV3', staticPrimaryColor).value,
          'primary-darken-1': cookieRef('darkThemePrimaryDarkenColorV3', staticPrimaryDarkenColor).value,
        },
      },
    },
  }

  const optionTheme = deepMerge({ themes }, cookieThemeValues)

  const vuetify = createVuetify({
    aliases: {
      IconBtn: VBtn,
    },
    components: {
      VVideo,
    },
    defaults,
    icons,
    theme: optionTheme,

  })

  app.use(vuetify)
}
