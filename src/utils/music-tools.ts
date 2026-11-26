import type { Song } from '../types/song'

export const MIN_BPM = 20
export const MAX_BPM = 300
export const DEFAULT_BPM = 120

export const MIN_A4_HZ = 415
export const MAX_A4_HZ = 466
export const DEFAULT_A4_HZ = 440
export const A4_PRESETS = [415, 432, 440, 442, 444] as const

export interface TimeSignatureSpec {
	beats: number
	unit: number
	label: string
}

export const DEFAULT_TIME_SIGNATURE: TimeSignatureSpec = {
	beats: 4,
	unit: 4,
	label: '4/4',
}

export const TIME_SIGNATURE_PRESETS: readonly string[] = [
	'2/4',
	'3/4',
	'4/4',
	'5/4',
	'6/8',
	'7/8',
	'9/8',
	'12/8',
]

export interface ChromaticNote {
	pitchClass: number
	label: string
	shortLabel: string
	isAccidental: boolean
}

export const CHROMATIC_NOTES: readonly ChromaticNote[] = [
	{ pitchClass: 0, label: 'C', shortLabel: 'C', isAccidental: false },
	{ pitchClass: 1, label: 'C♯ / D♭', shortLabel: 'C♯', isAccidental: true },
	{ pitchClass: 2, label: 'D', shortLabel: 'D', isAccidental: false },
	{ pitchClass: 3, label: 'D♯ / E♭', shortLabel: 'E♭', isAccidental: true },
	{ pitchClass: 4, label: 'E', shortLabel: 'E', isAccidental: false },
	{ pitchClass: 5, label: 'F', shortLabel: 'F', isAccidental: false },
	{ pitchClass: 6, label: 'F♯ / G♭', shortLabel: 'F♯', isAccidental: true },
	{ pitchClass: 7, label: 'G', shortLabel: 'G', isAccidental: false },
	{ pitchClass: 8, label: 'G♯ / A♭', shortLabel: 'A♭', isAccidental: true },
	{ pitchClass: 9, label: 'A', shortLabel: 'A', isAccidental: false },
	{ pitchClass: 10, label: 'A♯ / B♭', shortLabel: 'B♭', isAccidental: true },
	{ pitchClass: 11, label: 'B', shortLabel: 'B', isAccidental: false },
]

const NOTE_LETTER_TO_PC: Record<string, number> = {
	C: 0,
	D: 2,
	E: 4,
	F: 5,
	G: 7,
	A: 9,
	B: 11,
	H: 11, // German notation for B natural
}

export function clampBpm(bpm: number): number {
	if (!Number.isFinite(bpm)) return DEFAULT_BPM
	return Math.min(MAX_BPM, Math.max(MIN_BPM, Math.round(bpm)))
}

export function clampA4Hz(hz: number): number {
	if (!Number.isFinite(hz)) return DEFAULT_A4_HZ
	return Math.min(MAX_A4_HZ, Math.max(MIN_A4_HZ, Math.round(hz)))
}

export function parseTimeSignature(raw?: string | null): TimeSignatureSpec | null {
	if (!raw || typeof raw !== 'string') return null
	const trimmed = raw.trim()
	if (!trimmed) return null

	const upper = trimmed.toUpperCase()
	if (upper === 'C' || upper === 'COMMON') {
		return { beats: 4, unit: 4, label: '4/4' }
	}
	if (upper === 'C|' || upper === 'CUT' || upper === 'ALLA BREVE') {
		return { beats: 2, unit: 2, label: '2/2' }
	}

	const match = /^(\d{1,2})\s*\/\s*(\d{1,2})$/.exec(trimmed)
	if (!match) return null

	const beats = Number.parseInt(match[1], 10)
	const unit = Number.parseInt(match[2], 10)
	if (beats < 1 || beats > 16 || ![1, 2, 4, 8, 16].includes(unit)) {
		return null
	}
	return { beats, unit, label: `${beats}/${unit}` }
}

export function extractSongTempo(song: Song | null | undefined): number | null {
	const tempos = song?.tempos
	if (!Array.isArray(tempos) || tempos.length === 0) return null
	for (const entry of tempos) {
		const raw = Number(entry?.tempo)
		if (Number.isFinite(raw) && raw >= MIN_BPM && raw <= MAX_BPM) {
			return Math.round(raw)
		}
	}
	return null
}

export function extractSongTimeSignature(song: Song | null | undefined): TimeSignatureSpec | null {
	const signatures = song?.signatures
	if (!Array.isArray(signatures) || signatures.length === 0) return null
	for (const sig of signatures) {
		const parsed = parseTimeSignature(sig?.name)
		if (parsed) return parsed
	}
	return null
}

export function parseKeyNameToPitchClass(raw?: string | null): number | null {
	if (!raw || typeof raw !== 'string') return null
	const trimmed = raw.trim()
	if (!trimmed) return null

	const match = /^([A-Ga-gHh])\s*([#♯b♭])?/.exec(trimmed)
	if (!match) return null

	const letter = match[1].toUpperCase()
	const base = NOTE_LETTER_TO_PC[letter]
	if (base === undefined) return null

	const accidental = match[2]
	if (accidental === '#' || accidental === '♯') {
		return (base + 1) % 12
	}
	if (accidental === 'b' || accidental === '♭') {
		return (base + 11) % 12
	}
	return base
}

export function extractSongKeyPitchClass(song: Song | null | undefined): number | null {
	const keys = song?.keys
	if (!Array.isArray(keys) || keys.length === 0) return null
	for (const key of keys) {
		const pc = parseKeyNameToPitchClass(key?.name)
		if (pc !== null) return pc
	}
	return null
}

export function noteToMidi(pitchClass: number, octave: number): number {
	const normalizedPc = ((Math.round(pitchClass) % 12) + 12) % 12
	return (octave + 1) * 12 + normalizedPc
}

export function midiToFrequency(midi: number, a4Hz = DEFAULT_A4_HZ): number {
	return a4Hz * Math.pow(2, (midi - 69) / 12)
}

export function pitchClassToFrequency(pitchClass: number, octave: number, a4Hz = DEFAULT_A4_HZ): number {
	return midiToFrequency(noteToMidi(pitchClass, octave), a4Hz)
}

export function formatNoteWithOctave(pitchClass: number, octave: number): string {
	const normalizedPc = ((Math.round(pitchClass) % 12) + 12) % 12
	const note = CHROMATIC_NOTES[normalizedPc]
	return `${note.shortLabel}${octave}`
}

/**
 * Compute BPM from a sequence of tap timestamps (ms).
 * Ignores taps older than `maxGapMs` before the latest tap and uses at most the last `maxIntervals` intervals.
 */
export function computeTapTempo(
	timestamps: readonly number[],
	maxGapMs = 2500,
	maxIntervals = 6,
): number | null {
	if (timestamps.length < 2) return null

	const intervals: number[] = []
	for (let i = timestamps.length - 1; i > 0 && intervals.length < maxIntervals; i--) {
		const diff = timestamps[i] - timestamps[i - 1]
		if (diff <= 0 || diff > maxGapMs) {
			break
		}
		intervals.push(diff)
	}

	if (intervals.length === 0) return null
	const avgMs = intervals.reduce((sum, v) => sum + v, 0) / intervals.length
	if (avgMs <= 0) return null
	return clampBpm(60000 / avgMs)
}
