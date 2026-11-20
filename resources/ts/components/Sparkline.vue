<script setup lang="ts">
/**
 * A tiny inline-SVG sparkline.
 *
 * Exists because the circuits table rendered one ApexCharts instance per row. Each
 * instance carries its own SVG tree, event listeners and ResizeObserver, so a page
 * of 100 circuits built 100 chart engines to draw 100 lines 72px wide — enough to
 * hang the browser tab outright.
 *
 * This draws the same thing with one <path> and no library. Use ApexCharts for the
 * real graphs (axes, tooltips, zoom); use this wherever the chart is decoration on
 * a table row.
 *
 * A null point means "no reading" (a timeout, or a gap while the poller was down)
 * and breaks the line rather than being drawn as zero, which would read as a
 * healthy 0 ms response.
 */
const props = withDefaults(defineProps<{
  points: (number | null)[]
  color?: string
  width?: number
  height?: number
}>(), {
  color: 'currentColor',
  width: 72,
  height: 30,
})

const PAD = 2

const path = computed(() => {
  const pts = props.points ?? []
  const real = pts.filter((p): p is number => p !== null && Number.isFinite(p))

  if (real.length < 2)
    return ''

  const min = Math.min(...real)
  const max = Math.max(...real)
  const span = max - min || 1

  const innerW = props.width - PAD * 2
  const innerH = props.height - PAD * 2
  const stepX = pts.length > 1 ? innerW / (pts.length - 1) : 0

  // Build one path, starting a new subpath after every gap so a timeout leaves a
  // visible break instead of a line drawn through it.
  let d = ''
  let penDown = false

  pts.forEach((p, i) => {
    if (p === null || !Number.isFinite(p)) {
      penDown = false

      return
    }

    const x = PAD + i * stepX
    const y = PAD + innerH - ((p - min) / span) * innerH

    d += `${penDown ? 'L' : 'M'}${x.toFixed(1)} ${y.toFixed(1)} `
    penDown = true
  })

  return d.trim()
})

/** A single reading has no line to draw, so mark it as a dot instead. */
const singlePoint = computed(() => {
  const pts = props.points ?? []
  const real = pts.filter((p): p is number => p !== null && Number.isFinite(p))

  return real.length === 1
})
</script>

<template>
  <svg
    :width="width"
    :height="height"
    :viewBox="`0 0 ${width} ${height}`"
    role="img"
    aria-label="Response time trend"
    preserveAspectRatio="none"
  >
    <path
      v-if="path"
      :d="path"
      fill="none"
      :stroke="color"
      stroke-width="1.5"
      stroke-linecap="round"
      stroke-linejoin="round"
    />
    <circle
      v-else-if="singlePoint"
      :cx="width / 2"
      :cy="height / 2"
      r="1.75"
      :fill="color"
    />
  </svg>
</template>
