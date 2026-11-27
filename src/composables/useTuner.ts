import { onUnmounted, ref, type Ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { ensureAudioContextRunning, getSharedAudioContext } from '../utils/audio-engine'
import {
	createPitchSmoother,
	detectPitchFromBuffer,
	frequencyToPitchInfo,
	type DetectedPitch,
} from '../utils/pitch-detector'

const ANALYSER_FFT_SIZE = 4096
const HOLD_MS = 450

/**
 * Browser microphone chromatic tuner backed by Web Audio AnalyserNode and YIN pitch detection.
 *
 * @param a4Hz Reactive reference A4 frequency in Hz (shared with pitch pipe calibration)
 */
export function useTuner(a4Hz: Ref<number>) {
	const isListening = ref(false)
	const isStarting = ref(false)
	const error = ref<string | null>(null)
	const detected = ref<DetectedPitch | null>(null)
	const inputLevel = ref(0)

	let stream: MediaStream | null = null
	let sourceNode: MediaStreamAudioSourceNode | null = null
	let analyserNode: AnalyserNode | null = null
	let silentGainNode: GainNode | null = null
	let sampleBuffer: Float32Array<ArrayBuffer> | null = null
	let rafId: number | null = null
	let lastDetectedAt = 0
	const pitchSmoother = createPitchSmoother()

	function isSupported(): boolean {
		return typeof navigator !== 'undefined' && Boolean(navigator.mediaDevices?.getUserMedia)
	}

	function stop(): void {
		if (rafId !== null && typeof cancelAnimationFrame === 'function') {
			cancelAnimationFrame(rafId)
			rafId = null
		}
		if (sourceNode) {
			try {
				sourceNode.disconnect()
			} catch {
				// Ignore disconnect errors
			}
			sourceNode = null
		}
		if (analyserNode) {
			try {
				analyserNode.disconnect()
			} catch {
				// Ignore disconnect errors
			}
			analyserNode = null
		}
		if (silentGainNode) {
			try {
				silentGainNode.disconnect()
			} catch {
				// Ignore disconnect errors
			}
			silentGainNode = null
		}
		if (stream) {
			for (const track of stream.getTracks()) {
				try {
					track.stop()
				} catch {
					// Ignore stop errors
				}
			}
			stream = null
		}
		sampleBuffer = null
		pitchSmoother.reset()
		isListening.value = false
		isStarting.value = false
		detected.value = null
		inputLevel.value = 0
	}

	function pollPitch(sampleRate: number): void {
		if (!isListening.value || !analyserNode || !sampleBuffer) return

		analyserNode.getFloatTimeDomainData(sampleBuffer)
		let sumSq = 0
		for (let i = 0; i < sampleBuffer.length; i++) {
			const v = sampleBuffer[i]
			sumSq += v * v
		}
		const rms = Math.sqrt(sumSq / sampleBuffer.length)
		inputLevel.value = rms > 0.0005 ? Math.min(1, Math.sqrt(rms) * 2.5) : 0

		const raw = detectPitchFromBuffer(sampleBuffer, sampleRate)
		const now = Date.now()
		if (raw) {
			const smoothedHz = pitchSmoother.push(raw.frequency)
			const info = frequencyToPitchInfo(smoothedHz, a4Hz.value, raw.clarity)
			if (info) {
				detected.value = info
				lastDetectedAt = now
			}
		} else if (detected.value && now - lastDetectedAt > HOLD_MS) {
			detected.value = null
			pitchSmoother.reset()
		}

		if (typeof requestAnimationFrame === 'function') {
			rafId = requestAnimationFrame(() => pollPitch(sampleRate))
		}
	}

	async function requestMicStream(): Promise<MediaStream> {
		try {
			return await navigator.mediaDevices.getUserMedia({
				audio: {
					echoCancellation: false,
					noiseSuppression: false,
					autoGainControl: false,
				},
			})
		} catch (err) {
			const name = (err as { name?: string } | null)?.name ?? ''
			if (name === 'OverconstrainedError' || name === 'ConstraintNotSatisfiedError') {
				return navigator.mediaDevices.getUserMedia({ audio: true })
			}
			throw err
		}
	}

	async function start(): Promise<boolean> {
		if (isListening.value || isStarting.value) return isListening.value
		error.value = null

		if (!isSupported()) {
			const insecure = typeof window !== 'undefined' && window.isSecureContext === false
			error.value = insecure
				? t('sheetmusic', 'Microphone access requires HTTPS or localhost.')
				: t('sheetmusic', 'Microphone access is not available in this browser.')
			return false
		}

		const ctx = getSharedAudioContext()
		if (!ctx) {
			error.value = t('sheetmusic', 'Web Audio is not supported in this browser.')
			return false
		}
		ensureAudioContextRunning(ctx)

		isStarting.value = true
		try {
			const mediaStream = await requestMicStream()
			if (ctx.state === 'suspended') {
				try {
					await ctx.resume()
				} catch {
					// Continue even if resume rejects in headless/test environments
				}
			}

			stream = mediaStream
			sourceNode = ctx.createMediaStreamSource(mediaStream)
			analyserNode = ctx.createAnalyser()
			analyserNode.fftSize = ANALYSER_FFT_SIZE
			sourceNode.connect(analyserNode)
			// Keep the Web Audio graph actively pulled in all browsers without audible output
			if (typeof ctx.createGain === 'function' && ctx.destination) {
				silentGainNode = ctx.createGain()
				silentGainNode.gain.value = 0
				analyserNode.connect(silentGainNode)
				silentGainNode.connect(ctx.destination)
			}
			sampleBuffer = new Float32Array(new ArrayBuffer(analyserNode.fftSize * Float32Array.BYTES_PER_ELEMENT))

			isStarting.value = false
			isListening.value = true
			lastDetectedAt = Date.now()
			pollPitch(ctx.sampleRate)
			return true
		} catch (err) {
			stop()
			const name = (err as { name?: string } | null)?.name ?? ''
			if (name === 'NotAllowedError' || name === 'PermissionDeniedError' || name === 'SecurityError') {
				error.value = t('sheetmusic', 'Microphone permission was denied.')
			} else if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
				error.value = t('sheetmusic', 'No microphone was found on this device.')
			} else if (name === 'NotReadableError') {
				error.value = t('sheetmusic', 'Microphone is currently in use by another application.')
			} else {
				error.value = t('sheetmusic', 'Could not start microphone tuner.')
			}
			return false
		}
	}

	async function toggle(): Promise<void> {
		if (isListening.value) {
			stop()
		} else {
			await start()
		}
	}

	onUnmounted(() => {
		stop()
	})

	return {
		isListening,
		isStarting,
		error,
		detected,
		inputLevel,
		start,
		stop,
		toggle,
	}
}
