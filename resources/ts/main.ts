import { createApp } from 'vue'

import App from '@/App.vue'
import { registerPlugins } from '@core/utils/plugins'

// Typefaces, bundled rather than fetched.
//
// The app used to pull Inter from fonts.googleapis.com at runtime, which never
// resolves on Massey's internal network — the whole fleet silently fell back to the
// system stack, which is a large part of why the UI read as unstyled. These ship in
// the bundle, so they render offline and satisfy the `font-src 'self'` policy.
import '@fontsource-variable/plus-jakarta-sans'
import '@fontsource-variable/jetbrains-mono'

// Styles
import '@core-scss/template/index.scss'
import '@styles/styles.scss'

// Create vue app
const app = createApp(App)

// Register plugins
registerPlugins(app)

// Mount vue app
app.mount('#app')
