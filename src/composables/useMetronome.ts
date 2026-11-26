import { computed, onUnmounted, ref } from 'vue'
import type { Song } from '../types/song'
import {
	ensureAudioContextRunning,
	getSharedAudioContext,
	scheduleMetronomeClick,
} from '../utils/audio-engine'
import {
	clampBpm,
	computeTapTempo,
	DEFAULT_BPM,
	DEFAULT_TIME_SIGNATURE,
	extractSongTempo,
	extractSongTimeSignature,
	parseTimeSignature,
	type TimeSignatureSpec,
} from '../utils/music-tools'

const LOOKAHEAD_MS = 25
const SCHEDULE_AHEAD_SEC = 0.1

export interface UseMetronomeOptions {
	initialBpm?: number
	initialTimeSignature?: TimeSignatureSpec
}

export function useMetronome(options: UseMetronomeOptions = {}) {
	const bpm = ref(clampBpm(options.initialBpm ?? DEFAULT_BPM))
	const timeSignature = ref<TimeSignatureSpec>(options.initialTimeSignature ?? { ...DEFAULT_TIME_SIGNATURE })
	const accentFirstBeat = ref(true)
	const soundEnabled = ref(true)
	const visualFlashEnabled = ref(true)
	const volume = ref(0.8)

	const isRunning = ref(false)
	const currentBeat = ref(-1)
	const beatPulseCount = ref(0)

	const isAccentedBeat = computed(() =>
		isRunning.value && accentFirstBeat.value && currentBeat.value === 0,
	)

	let schedulerTimer: ReturnType<typeof setInterval> | null = null
	const visualTimeouts = new Set<ReturnType<typeof setTimeout>>()
	let nextBeatTimeSec = 0
	let nextBeatIndex = 0
	let tapTimestamps: number[] = []

	function nowSec(ctx: AudioContext | null): number {
		if (ctx) return ctx.currentTime
		return (typeof performance !== 'undefined' ? performance.now() : Date.now()) / 1000
	}

	function secondsPerBeat(): number {
		return 60 / bpm.value
	}

	function clearTimers(): void {
		if (schedulerTimer !== null) {
			clearInterval(schedulerTimer)
			schedulerTimer = null
		}
		for (const id of visualTimeouts) {
			clearTimeout(id)
		}
		visualTimeouts.clear()
	}

	function triggerVisualBeat(beatIdx: number): void {
		if (!isRunning.value) return
		currentBeat.value = beatIdx
		beatPulseCount.value += 1
	}

	function scheduleBeat(beatIdx: number, scheduledTimeSec: number, ctx: AudioContext | null): void {
		const accented = accentFirstBeat.value && beatIdx === 0
		if (soundEnabled.value && ctx) {
			scheduleMetronomeClick(ctx, scheduledTimeSec, {
				accented,
				volume: volume.value,
			})
		}

		const delayMs = Math.max(0, Math.round((scheduledTimeSec - nowSec(ctx)) * 1000))
		if (delayMs === 0) {
			triggerVisualBeat(beatIdx)
		} else {
			const timeoutId = setTimeout(() => {
				visualTimeouts.delete(timeoutId)
				triggerVisualBeat(beatIdx)
			}, delayMs)
			visualTimeouts.add(timeoutId)
		}
	}

	function runSchedulerTick(): void {
		if (!isRunning.value) return
		const ctx = soundEnabled.value ? getSharedAudioContext() : null
		const current = nowSec(ctx)
		while (nextBeatTimeSec < current + SCHEDULE_AHEAD_SEC) {
			const beatIdx = nextBeatIndex % timeSignature.value.beats
			scheduleBeat(beatIdx, nextBeatTimeSec, ctx)
			nextBeatIndex = (beatIdx + 1) % timeSignature.value.beats
			nextBeatTimeSec += secondsPerBeat()
		}
	}

	function start(): void {
		if (isRunning.value) return
		const ctx = soundEnabled.value ? getSharedAudioContext() : null
		ensureAudioContextRunning(ctx)
		isRunning.value = true
		nextBeatIndex = 0
		nextBeatTimeSec = nowSec(ctx)
		runSchedulerTick()
		schedulerTimer = setInterval(runSchedulerTick, LOOKAHEAD_MS)
	}

	function stop(): void {
		if (!isRunning.value) return
		isRunning.value = false
		clearTimers()
		currentBeat.value = -1
	}

	function toggle(): void {
		if (isRunning.value) {
			stop()
		} else {
			start()
		}
	}

	function setBpm(nextBpm: number): void {
		bpm.value = clampBpm(nextBpm)
	}

	function adjustBpm(delta: number): void {
		bpm.value = clampBpm(bpm.value + delta)
	}

	function setTimeSignature(spec: string | TimeSignatureSpec): void {
		const parsed = typeof spec === 'string' ? parseTimeSignature(spec) : spec
		if (!parsed) return
		timeSignature.value = { ...parsed }
		if (currentBeat.value >= parsed.beats) {
			currentBeat.value = 0
		}
		nextBeatIndex = nextBeatIndex % parsed.beats
	}

	function tapTempo(nowMs = Date.now()): number | null {
		if (tapTimestamps.length > 0 && nowMs - tapTimestamps[tapTimestamps.length - 1] > 2500) {
			tapTimestamps = []
		}
		tapTimestamps.push(nowMs)
		if (tapTimestamps.length > 8) {
			tapTimestamps = tapTimestamps.slice(-8)
		}
		const computedBpm = computeTapTempo(tapTimestamps)
		if (computedBpm !== null) {
			bpm.value = computedBpm
		}
		return computedBpm
	}

	function syncFromSong(song: Song | null | undefined): void {
		const songTempo = extractSongTempo(song)
		if (songTempo !== null) {
			bpm.value = songTempo
		}
		const songSig = extractSongTimeSignature(song)
		if (songSig !== null) {
			setTimeSignature(songSig)
		}
	}

	onUnmounted(() => {
		stop()
	})

	return {
		bpm,
		timeSignature,
		accentFirstBeat,
		soundEnabled,
		visualFlashEnabled,
		volume,
		isRunning,
		currentBeat,
		beatPulseCount,
		isAccentedBeat,
		start,
		stop,
		toggle,
		setBpm,
		adjustBpm,
		setTimeSignature,
		tapTempo,
		syncFromSong,
	}
}
