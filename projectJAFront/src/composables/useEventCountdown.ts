import { computed, onBeforeUnmount, ref, watch, type Ref } from 'vue'

export type EventCountdownState = {
  expired: boolean
  running: boolean
  days: number
  hours: number
  minutes: number
  seconds: number
}

function partsFromDiff(diff: number): Omit<EventCountdownState, 'expired' | 'running'> {
  const totalSec = Math.max(0, Math.floor(diff / 1000))
  return {
    days: Math.floor(totalSec / 86400),
    hours: Math.floor((totalSec % 86400) / 3600),
    minutes: Math.floor((totalSec % 3600) / 60),
    seconds: totalSec % 60,
  }
}

export function useEventCountdown(startsAt: Ref<Date | string | null | undefined>, endsAt: Ref<Date | string | null | undefined>) {
  const nowTick = ref(Date.now())
  let timer: ReturnType<typeof setInterval> | undefined

  function start(): void {
    if (timer) return
    timer = setInterval(() => {
      nowTick.value = Date.now()
    }, 1000)
  }

  function stop(): void {
    if (!timer) return
    clearInterval(timer)
    timer = undefined
  }

  const state = computed<EventCountdownState | null>(() => {
    const startMs = startsAt.value ? new Date(startsAt.value).getTime() : NaN
    if (Number.isNaN(startMs)) return null
    const endMs = endsAt.value ? new Date(endsAt.value).getTime() : NaN
    const now = nowTick.value
    if (now < startMs) {
      return { expired: false, running: false, ...partsFromDiff(startMs - now) }
    }
    if (!Number.isNaN(endMs) && now < endMs) {
      return { expired: false, running: true, ...partsFromDiff(endMs - now) }
    }
    if (!Number.isNaN(endMs) && now >= endMs) {
      return { expired: true, running: false, days: 0, hours: 0, minutes: 0, seconds: 0 }
    }
    return { expired: false, running: true, days: 0, hours: 0, minutes: 0, seconds: 0 }
  })

  watch(
    [startsAt, endsAt],
    () => {
      nowTick.value = Date.now()
      if (startsAt.value) start()
      else stop()
    },
    { immediate: true },
  )

  onBeforeUnmount(stop)

  return { state }
}

export function pad2(n: number): string {
  return String(n).padStart(2, '0')
}
