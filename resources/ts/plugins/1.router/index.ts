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

/**
 * Recover from a stale page after a deploy.
 *
 * Routes are lazy-loaded chunks whose filenames carry a content hash. A tab that
 * was open across a release still holds the previous entry point, so navigating to
 * a page it has not visited yet requests a chunk the new build no longer contains.
 * The import rejects, the router stops, and the click looks like it did nothing —
 * the page the operator was already on keeps working, which makes it read as "that
 * one feature is broken" rather than "reload me".
 *
 * Reloading at the intended URL fetches the current index and lands where the
 * operator was going. Guarded by a session flag so a genuinely missing chunk cannot
 * turn into a reload loop.
 */
const RELOAD_GUARD = 'nodus:chunk-reload'

router.onError((error, to) => {
  const message = String((error as Error)?.message ?? error)
  const isStaleChunk = /dynamically imported module|Importing a module script failed|Failed to fetch/i.test(message)

  if (!isStaleChunk)
    return

  if (sessionStorage.getItem(RELOAD_GUARD) === to.fullPath) {
    sessionStorage.removeItem(RELOAD_GUARD)

    return
  }

  sessionStorage.setItem(RELOAD_GUARD, to.fullPath)
  window.location.assign(to.fullPath)
})

// A completed navigation means the chunks for it loaded; clear the guard so a later
// deploy can recover the same route again.
router.afterEach(() => sessionStorage.removeItem(RELOAD_GUARD))

export { router }

export default function (app: App) {
  app.use(router)
}
