import { computed, onUnmounted, ref } from 'vue'
import {
	ensureAudioContextRunning,
	getSharedAudioContext,
	startPitchVoice,
	type PitchVoiceHandle,
	type PitchWaveform,
} from '../utils/audio-engine'
import {
	clampA4Hz,
	DEFAULT_A4_HZ,
	formatNoteWithOctave,
	pitchClassToFrequency,
} from '../utils/music-tools'

export type PitchOctave = 3 | 4 | 5

export function usePitchPipe() {
	const activePitchClass = ref<number | null>(null)
	const octave = ref<PitchOctave>(4)
	const a4Hz = ref(DEFAULT_A4_HZ)
	const waveform = ref<PitchWaveform>('triangle')
	const volume = ref(0.75)

	let voice: PitchVoiceHandle | null = null

	const isPlaying = computed(() => activePitchClass.value !== null)

	const activeFrequency = computed<number | null>(() => {
		if (activePitchClass.value === null) return null
		return pitchClassToFrequency(activePitchClass.value, octave.value, a4Hz.value)
	})

	const activeNoteLabel = computed<string>(() => {
		if (activePitchClass.value === null) return ''
		return formatNoteWithOctave(activePitchClass.value, octave.value)
	})

	function stop(): void {
		if (voice) {
			voice.stop()
			voice = null
		}
		activePitchClass.value = null
	}

	function playNote(pitchClass: number): void {
		const normalized = ((Math.round(pitchClass) % 12) + 12) % 12
		const freq = pitchClassToFrequency(normalized, octave.value, a4Hz.value)

		if (voice) {
			voice.setFrequency(freq)
			activePitchClass.value = normalized
			return
		}

		const ctx = getSharedAudioContext()
		ensureAudioContextRunning(ctx)
		if (ctx) {
			voice = startPitchVoice(ctx, freq, {
				waveform: waveform.value,
				volume: volume.value,
			})
		}
		activePitchClass.value = normalized
	}

	function toggleNote(pitchClass: number): void {
		const normalized = ((Math.round(pitchClass) % 12) + 12) % 12
		if (activePitchClass.value === normalized) {
			stop()
		} else {
			playNote(normalized)
		}
	}

	function setOctave(nextOctave: PitchOctave): void {
		octave.value = nextOctave
		if (activePitchClass.value !== null && voice) {
			voice.setFrequency(pitchClassToFrequency(activePitchClass.value, nextOctave, a4Hz.value))
		}
	}

	function setA4Hz(nextHz: number): void {
		const clamped = clampA4Hz(nextHz)
		a4Hz.value = clamped
		if (activePitchClass.value !== null && voice) {
			voice.setFrequency(pitchClassToFrequency(activePitchClass.value, octave.value, clamped))
		}
	}

	function setWaveform(nextWaveform: PitchWaveform): void {
		waveform.value = nextWaveform
		voice?.setWaveform(nextWaveform)
	}

	function setVolume(nextVolume: number): void {
		const clamped = Math.max(0, Math.min(1, nextVolume))
		volume.value = clamped
		voice?.setVolume(clamped)
	}

	onUnmounted(() => {
		stop()
	})

	return {
		activePitchClass,
		octave,
		a4Hz,
		waveform,
		volume,
		isPlaying,
		activeFrequency,
		activeNoteLabel,
		playNote,
		toggleNote,
		stop,
		setOctave,
		setA4Hz,
		setWaveform,
		setVolume,
	}
}
