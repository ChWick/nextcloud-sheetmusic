import { describe, expect, it } from 'vitest'
import type { Song } from '../../../src/types/song'
import {
	clampA4Hz,
	clampBpm,
	computeTapTempo,
	extractSongKeyPitchClass,
	extractSongTempo,
	extractSongTimeSignature,
	formatNoteWithOctave,
	midiToFrequency,
	noteToMidi,
	parseKeyNameToPitchClass,
	parseTimeSignature,
	pitchClassToFrequency,
} from '../../../src/utils/music-tools'

describe('music-tools', () => {
	it('clamps BPM and A4 Hz to safe bounds', () => {
		expect(clampBpm(10)).toBe(20)
		expect(clampBpm(450)).toBe(300)
		expect(clampBpm(123.6)).toBe(124)
		expect(clampBpm(Number.NaN)).toBe(120)

		expect(clampA4Hz(400)).toBe(415)
		expect(clampA4Hz(500)).toBe(466)
		expect(clampA4Hz(442)).toBe(442)
	})

	it('parses standard and shorthand time signatures', () => {
		expect(parseTimeSignature('4/4')).toEqual({ beats: 4, unit: 4, label: '4/4' })
		expect(parseTimeSignature('6/8')).toEqual({ beats: 6, unit: 8, label: '6/8' })
		expect(parseTimeSignature('C')).toEqual({ beats: 4, unit: 4, label: '4/4' })
		expect(parseTimeSignature('Cut')).toEqual({ beats: 2, unit: 2, label: '2/2' })
		expect(parseTimeSignature('invalid')).toBeNull()
		expect(parseTimeSignature('4/3')).toBeNull()
		expect(parseTimeSignature('')).toBeNull()
	})

	it('parses key names to pitch classes including accidentals and German H', () => {
		expect(parseKeyNameToPitchClass('C Major')).toBe(0)
		expect(parseKeyNameToPitchClass('D Minor')).toBe(2)
		expect(parseKeyNameToPitchClass('F#m')).toBe(6)
		expect(parseKeyNameToPitchClass('E♭ Major')).toBe(3)
		expect(parseKeyNameToPitchClass('Bb')).toBe(10)
		expect(parseKeyNameToPitchClass('H Minor')).toBe(11)
		expect(parseKeyNameToPitchClass('')).toBeNull()
		expect(parseKeyNameToPitchClass('Unknown')).toBeNull()
	})

	it('extracts tempo, time signature, and key pitch class from a Song', () => {
		const song = {
			id: 1,
			title: 'Test',
			tempos: [{ id: 1, songId: 1, tempo: 108 }],
			signatures: [{ id: 1, name: '3/4' }],
			keys: [{ id: 1, name: 'G Minor' }],
		} as unknown as Song

		expect(extractSongTempo(song)).toBe(108)
		expect(extractSongTimeSignature(song)).toEqual({ beats: 3, unit: 4, label: '3/4' })
		expect(extractSongKeyPitchClass(song)).toBe(7)
		expect(extractSongTempo(null)).toBeNull()
	})

	it('computes MIDI numbers, frequencies, and note labels accurately', () => {
		expect(noteToMidi(9, 4)).toBe(69)
		expect(noteToMidi(0, 4)).toBe(60)
		expect(midiToFrequency(69, 440)).toBeCloseTo(440, 4)
		expect(pitchClassToFrequency(9, 4, 442)).toBeCloseTo(442, 4)
		expect(pitchClassToFrequency(9, 3, 440)).toBeCloseTo(220, 4)
		expect(pitchClassToFrequency(0, 4, 440)).toBeCloseTo(261.6256, 3)
		expect(formatNoteWithOctave(9, 4)).toBe('A4')
		expect(formatNoteWithOctave(3, 5)).toBe('E♭5')
	})

	it('computes tap tempo and resets after long pauses', () => {
		expect(computeTapTempo([])).toBeNull()
		expect(computeTapTempo([1000])).toBeNull()
		// 500ms intervals = 120 BPM
		expect(computeTapTempo([1000, 1500, 2000, 2500])).toBe(120)
		// Long gap (>2500ms) ignores earlier taps and uses the 1000ms interval (60 BPM)
		expect(computeTapTempo([1000, 1500, 10000, 11000])).toBe(60)
	})
})
