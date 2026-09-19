/**
 * Typefaces are bundled, not fetched.
 *
 * This used to call webfontloader against fonts.googleapis.com for Inter. Massey's
 * NOC has no route to the internet, so that request never resolved and every screen
 * fell back to the system stack — the fonts the design assumes were never actually
 * loading in production.
 *
 * Plus Jakarta Sans and JetBrains Mono are now imported in `main.ts` from
 * `@fontsource-variable/*`, so they ship inside the bundle: they render offline and
 * they satisfy the `font-src 'self' data:` policy in SecurityHeaders.
 *
 * Kept as a no-op because the plugin loader globs this directory.
 */
export async function loadFonts() {
  // Intentionally empty — see above.
}

export default function () {
  // Intentionally empty — see above.
}
