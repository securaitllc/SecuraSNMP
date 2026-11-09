<script lang="ts" setup>
/**
 * One-series phone sparkline: plain SVG, no chart library. Nulls break the line
 * (an outage shows as a gap, never as a zero), and a fixed `max` keeps percentage
 * series on a 0–100 scale so CPU at 12% does not look like a cliff.
 */
const props = withDefaults(defineProps<{
  points: (number | null)[]
  color?: string
  max?: number | null
  height?: number
  unit?: string
  label?: string
}>(), { color: 'rgb(var(--v-theme-primary))', max: null, height: 56, unit: '', label: '' })

const W = 320

const valid = computed(() => props.points.filter((p): p is number => p !== null && Number.isFinite(p)))
const top = computed(() => props.max ?? Math.max(1, ...valid.value))
const last = computed(() => {
  for (let i = props.points.length - 1; i >= 0; i--) {
    const p = props.points[i]
    if (p !== null && Number.isFinite(p))
      return p
  }

  return null
})

// Each unbroken run of samples is its own polyline.
const segments = computed(() => {
  const n = props.points.length
  if (n < 2)
    return [] as string[]
  const step = W / (n - 1)
  const out: string[] = []
  let cur: string[] = []
  props.points.forEach((p, i) => {
    if (p === null || !Number.isFinite(p)) {
      if (cur.length > 1)
        out.push(cur.join(' '))
      cur = []

      return
    }
    const y = props.height - 4 - (Math.min(p, top.value) / top.value) * (props.height - 8)
    cur.push(`${(i * step).toFixed(1)},${y.toFixed(1)}`)
  })
  if (cur.length > 1)
    out.push(cur.join(' '))

  return out
})

const gaps = computed(() => props.points.some(p => p === null))
</script>

<template>
  <div class="m-spark">
    <div
      v-if="label"
      class="m-spark-head"
    >
      <span>{{ label }}</span>
      <span class="v">{{ last === null ? '—' : `${Math.round(last * 10) / 10}${unit}` }}<template v-if="valid.length && max === null"> · peak {{ Math.round(Math.max(...valid) * 10) / 10 }}{{ unit }}</template></span>
    </div>
    <svg
      v-if="segments.length"
      :viewBox="`0 0 ${W} ${height}`"
      :height="height"
      width="100%"
      preserveAspectRatio="none"
    >
      <line
        x1="0"
        :y1="height - 4"
        :x2="W"
        :y2="height - 4"
        class="base"
      />
      <polyline
        v-for="(s, i) in segments"
        :key="i"
        :points="s"
        fill="none"
        :stroke="color"
        stroke-width="1.5"
        stroke-linejoin="round"
        vector-effect="non-scaling-stroke"
      />
    </svg>
    <div
      v-else
      class="m-spark-empty"
    >
      No samples yet.
    </div>
    <div
      v-if="gaps && segments.length"
      class="m-spark-note"
    >
      Gaps = no reply.
    </div>
  </div>
</template>

<style lang="scss">
.m-spark {
  border: 1px solid var(--nodus-rule);
  background: rgb(var(--v-theme-surface));
  padding: 8px 10px 6px;

  .m-spark-head {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    color: var(--nodus-ink-dim);
    margin-block-end: 4px;

    .v {
      font-family: 'IBM Plex Mono', ui-monospace, monospace;
      color: rgb(var(--v-theme-on-surface));
    }
  }

  svg { display: block; }

  .base {
    stroke: var(--nodus-rule);
    stroke-width: 1;
  }

  .m-spark-empty, .m-spark-note {
    font-size: 11px;
    color: var(--nodus-ink-dim);
    padding-block: 4px;
  }
}
</style>
