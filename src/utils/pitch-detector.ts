import {
	CHROMATIC_NOTES,
	DEFAULT_A4_HZ,
	formatNoteWithOctave,
	midiToFrequency,
} from './music-tools'

export const TUNER_IN_TUNE_CENTS = 5
export const DEFAULT_MIN_DETECT_HZ = 65 // ~C2
export const DEFAULT_MAX_DETECT_HZ = 1400 // ~F6
export const DEFAULT_RMS_THRESHOLD = 0.003
export const DEFAULT_YIN_THRESHOLD = 0.25

export interface DetectedPitch {
	frequency: number
	midi: number
	pitchClass: number
	octave: number
	noteLabel: string
	shortLabel: string
	targetFrequency: number
	/** Deviation in cents from nearest equal-temperament semitone (-50..+50). */
	cents: number
	inTune: boolean
	clarity: number
}

export interface PitchDetectionOptions {
	minFreq?: number
	maxFreq?: number
	rmsThreshold?: number
	yinThreshold?: number
}

/**
 * Convert a detected frequency in Hz to nearest chromatic note and cents deviation.
 *
 * @param frequencyHz Detected fundamental frequency in Hz
 * @param a4Hz Reference A4 tuning frequency in Hz (default 440)
 * @param clarity Detection confidence in [0..1]
 */
export function frequencyToPitchInfo(
	frequencyHz: number,
	a4Hz = DEFAULT_A4_HZ,
	clarity = 1,
): DetectedPitch | null {
	if (!Number.isFinite(frequencyHz) || frequencyHz <= 0 || !Number.isFinite(a4Hz) || a4Hz <= 0) {
		return null
	}
	const exactMidi = 69 + 12 * Math.log2(frequencyHz / a4Hz)
	const midi = Math.round(exactMidi)
	const cents = Math.max(-50, Math.min(50, Math.round((exactMidi - midi) * 100)))
	const pitchClass = ((midi % 12) + 12) % 12
	const octave = Math.floor(midi / 12) - 1
	const note = CHROMATIC_NOTES[pitchClass]

	return {
		frequency: frequencyHz,
		midi,
		pitchClass,
		octave,
		noteLabel: formatNoteWithOctave(pitchClass, octave),
		shortLabel: note.shortLabel,
		targetFrequency: midiToFrequency(midi, a4Hz),
		cents,
		inTune: Math.abs(cents) <= TUNER_IN_TUNE_CENTS,
		clarity,
	}
}

/**
 * Format signed cents offset for display (e.g. "+7¢", "-12¢", "0¢").
 *
 * @param cents Cents deviation in [-50..+50]
 */
export function formatCentsOffset(cents: number): string {
	const rounded = Math.round(cents)
	if (rounded === 0) return '0¢'
	return rounded > 0 ? `+${rounded}¢` : `${rounded}¢`
}

export interface PitchSmoother {
	push: (frequencyHz: number) => number
	reset: () => void
}

/**
 * Smooth live pitch readings using a short median filter (to reject single-frame
 * octave/noise spikes) followed by an exponential moving average (EMA). When the
 * median pitch jumps by more than `jumpResetCents` (default 80¢), the EMA snaps
 * immediately so switching notes stays responsive.
 *
 * @param options Optional smoothing window, EMA alpha, and jump-reset threshold
 * @param options.windowSize Median window size (default 5)
 * @param options.alpha EMA smoothing factor in (0..1] (default 0.22)
 * @param options.jumpResetCents Cents jump that resets EMA immediately (default 80)
 */
export function createPitchSmoother(options: {
	windowSize?: number
	alpha?: number
	jumpResetCents?: number
} = {}): PitchSmoother {
	const windowSize = Math.max(1, options.windowSize ?? 5)
	const alpha = Math.max(0.05, Math.min(1, options.alpha ?? 0.22))
	const jumpResetCents = options.jumpResetCents ?? 80

	const recent: number[] = []
	let smoothedHz: number | null = null

	return {
		push(frequencyHz: number): number {
			recent.push(frequencyHz)
			if (recent.length > windowSize) {
				recent.shift()
			}
			const sorted = [...recent].sort((a, b) => a - b)
			const medianHz = sorted[Math.floor(sorted.length / 2)]

			if (smoothedHz === null) {
				smoothedHz = medianHz
				return smoothedHz
			}

			const centsDiff = Math.abs(1200 * Math.log2(medianHz / smoothedHz))
			if (centsDiff > jumpResetCents) {
				smoothedHz = medianHz
			} else {
				smoothedHz = smoothedHz + alpha * (medianHz - smoothedHz)
			}
			return smoothedHz
		},
		reset(): void {
			recent.length = 0
			smoothedHz = null
		},
	}
}

/**
 * Detect fundamental frequency from a time-domain audio buffer using the YIN
 * Cumulative Mean Normalized Difference Function (CMNDF) with parabolic interpolation.
 *
 * @param buffer Mono PCM samples in [-1..1]
 * @param sampleRate AudioContext sample rate in Hz
 * @param options Optional frequency range and sensitivity thresholds
 */
export function detectPitchFromBuffer(
	buffer: Float32Array,
	sampleRate: number,
	options: PitchDetectionOptions = {},
): { frequency: number; clarity: number; rms: number } | null {
	const n = buffer.length
	if (n < 64 || !Number.isFinite(sampleRate) || sampleRate <= 0) {
		return null
	}

	const minFreq = options.minFreq ?? DEFAULT_MIN_DETECT_HZ
	const maxFreq = options.maxFreq ?? DEFAULT_MAX_DETECT_HZ
	const rmsThreshold = options.rmsThreshold ?? DEFAULT_RMS_THRESHOLD
	const yinThreshold = options.yinThreshold ?? DEFAULT_YIN_THRESHOLD

	// 1. Silence / noise gate via RMS
	let sumSq = 0
	for (let i = 0; i < n; i++) {
		const v = buffer[i]
		sumSq += v * v
	}
	const rms = Math.sqrt(sumSq / n)
	if (rms < rmsThreshold) {
		return null
	}

	const halfLen = Math.floor(n / 2)
	const minLag = Math.max(2, Math.floor(sampleRate / maxFreq))
	const maxLag = Math.min(halfLen - 1, Math.ceil(sampleRate / minFreq))
	if (minLag >= maxLag) {
		return null
	}

	// 2. Difference function d(tau) and Cumulative Mean Normalized Difference d'(tau)
	const cmndf = new Float32Array(maxLag + 1)
	cmndf[0] = 1
	let runningSum = 0

	for (let tau = 1; tau <= maxLag; tau++) {
		let diffSum = 0
		for (let i = 0; i < halfLen; i++) {
			const delta = buffer[i] - buffer[i + tau]
			diffSum += delta * delta
		}
		runningSum += diffSum
		cmndf[tau] = runningSum > 0 ? (diffSum * tau) / runningSum : 1
	}

	// 3. Absolute threshold: find the first local minimum below yinThreshold
	let bestLag = -1
	for (let tau = minLag; tau <= maxLag; tau++) {
		if (cmndf[tau] < yinThreshold) {
			while (tau + 1 <= maxLag && cmndf[tau + 1] < cmndf[tau]) {
				tau++
			}
			bestLag = tau
			break
		}
	}

	if (bestLag === -1) {
		return null
	}

	const minVal = cmndf[bestLag]
	const clarity = Math.max(0, Math.min(1, 1 - minVal))

	// 4. Parabolic interpolation around bestLag for sub-sample accuracy
	let refinedLag = bestLag
	if (bestLag > 1 && bestLag < maxLag) {
		const s0 = cmndf[bestLag - 1]
		const s1 = cmndf[bestLag]
		const s2 = cmndf[bestLag + 1]
		const denom = 2 * s1 - s2 - s0
		if (Math.abs(denom) > 1e-12) {
			refinedLag = bestLag + (s2 - s0) / (2 * denom)
		}
	}

	if (refinedLag <= 0) return null
	const frequency = sampleRate / refinedLag
	if (frequency < minFreq || frequency > maxFreq) return null

	return { frequency, clarity, rms }
}
