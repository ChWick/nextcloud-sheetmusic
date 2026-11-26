export type PitchWaveform = 'triangle' | 'sine'

export interface PitchVoiceHandle {
	setFrequency: (frequency: number) => void
	setVolume: (volume: number) => void
	setWaveform: (waveform: PitchWaveform) => void
	stop: () => void
}

type AudioContextCtor = new () => AudioContext

let sharedAudioContext: AudioContext | null = null

function getAudioContextConstructor(): AudioContextCtor | null {
	if (typeof window === 'undefined') return null
	const win = window as unknown as {
		AudioContext?: AudioContextCtor
		webkitAudioContext?: AudioContextCtor
	}
	return win.AudioContext ?? win.webkitAudioContext ?? null
}

export function getSharedAudioContext(): AudioContext | null {
	if (sharedAudioContext && sharedAudioContext.state !== 'closed') {
		return sharedAudioContext
	}
	const Ctor = getAudioContextConstructor()
	if (!Ctor) return null
	try {
		sharedAudioContext = new Ctor()
		return sharedAudioContext
	} catch {
		return null
	}
}

export function ensureAudioContextRunning(ctx: AudioContext | null): void {
	if (!ctx || ctx.state !== 'suspended') return
	try {
		void ctx.resume()
	} catch {
		// Ignore autoplay policy errors
	}
}

export function resetSharedAudioContextForTests(): void {
	if (sharedAudioContext && sharedAudioContext.state !== 'closed') {
		try {
			void sharedAudioContext.close()
		} catch {
			// Ignore close errors in tests
		}
	}
	sharedAudioContext = null
}

/**
 * Schedule a short, percussive metronome click at `time` (in AudioContext seconds).
 */
export function scheduleMetronomeClick(
	ctx: AudioContext,
	time: number,
	options: { accented: boolean; volume: number },
): void {
	const clampedVolume = Math.max(0.001, Math.min(1, options.volume))
	const osc = ctx.createOscillator()
	const gain = ctx.createGain()

	osc.type = 'triangle'
	osc.frequency.setValueAtTime(options.accented ? 1200 : 840, time)

	gain.gain.setValueAtTime(0.0001, time)
	gain.gain.linearRampToValueAtTime(clampedVolume * (options.accented ? 0.65 : 0.45), time + 0.003)
	gain.gain.exponentialRampToValueAtTime(0.0001, time + 0.045)

	osc.connect(gain)
	gain.connect(ctx.destination)

	osc.start(time)
	osc.stop(time + 0.05)
}

/**
 * Start a smooth continuous pitch-pipe voice with soft attack/release to prevent pops.
 */
export function startPitchVoice(
	ctx: AudioContext,
	frequency: number,
	options: { waveform: PitchWaveform; volume: number },
): PitchVoiceHandle {
	const osc = ctx.createOscillator()
	const gain = ctx.createGain()
	const now = ctx.currentTime
	const targetGain = Math.max(0.001, Math.min(1, options.volume)) * 0.35

	osc.type = options.waveform
	osc.frequency.setValueAtTime(frequency, now)

	gain.gain.setValueAtTime(0.0001, now)
	gain.gain.linearRampToValueAtTime(targetGain, now + 0.03)

	osc.connect(gain)
	gain.connect(ctx.destination)
	osc.start(now)

	let stopped = false

	return {
		setFrequency(nextFreq: number) {
			if (stopped) return
			osc.frequency.setTargetAtTime(nextFreq, ctx.currentTime, 0.015)
		},
		setVolume(nextVolume: number) {
			if (stopped) return
			const nextGain = Math.max(0.001, Math.min(1, nextVolume)) * 0.35
			gain.gain.setTargetAtTime(nextGain, ctx.currentTime, 0.02)
		},
		setWaveform(nextWaveform: PitchWaveform) {
			if (stopped) return
			osc.type = nextWaveform
		},
		stop() {
			if (stopped) return
			stopped = true
			const stopTime = ctx.currentTime
			try {
				gain.gain.cancelScheduledValues(stopTime)
				gain.gain.setValueAtTime(Math.max(0.0001, gain.gain.value), stopTime)
				gain.gain.exponentialRampToValueAtTime(0.0001, stopTime + 0.04)
				osc.stop(stopTime + 0.05)
			} catch {
				// Ignore errors if already stopped or context closed
			}
		},
	}
}
