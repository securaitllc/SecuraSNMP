import { setupLayouts } from 'virtual:meta-layouts'
import type { App } from 'vue'

import type { RouteRecordRaw } from 'vue-router/auto'

import { createRouter, createWebHistory } from 'vue-router/auto'
import { useAuthStore } from '@/stores/auth'

function recursiveLayouts(route: RouteRecordRaw): RouteRecordRaw {
  if (route.children) {
    for (let i = 0; i < route.children.length; i++)
      route.children[i] = recursiveLayouts(route.children[i])

    return route
  }

  return setupLayouts([route])[0]
}

const router = createRouter({
  // App routes live at the site root ("/"). import.meta.env.BASE_URL is Vite's
  // ASSET base ("/build/") and using it here put the whole SPA under /build/,
  // which collides with the real public/build asset dir and 404s on refresh.
  history: createWebHistory('/'),
  scrollBehavior(to) {
    if (to.hash)
      return { el: to.hash, behavior: 'smooth', top: 60 }

    return { top: 0 }
  },
  extendRoutes: pages => [
    ...[...pages].map(route => recursiveLayouts(route)),
  ],
})

router.beforeEach(async to => {
  const auth = useAuthStore()

  if (!auth.initialized)
    await auth.fetchUser()

  const isPublic = to.meta.public === true

  if (!isPublic && !auth.isAuthenticated)
    return { name: 'login' }

  if (to.name === 'login' && auth.isAuthenticated)
    return { name: 'root' }

  if (to.meta.requiresAdmin === true && !auth.isAdmin)
    return { name: 'root' }
})

export { router }

export default function (app: App) {
  app.use(router)
}
