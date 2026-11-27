/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import A4CalibrationControl from '../../src/components/setlists/A4CalibrationControl.vue'
import PerformancePitchPipePanel from '../../src/components/setlists/PerformancePitchPipePanel.vue'
import PerformancePitchPipeSection from '../../src/components/setlists/PerformancePitchPipeSection.vue'
import TunerMeter from '../../src/components/setlists/TunerMeter.vue'
import { resetSharedAudioContextForTests } from '../../src/utils/audio-engine'
import { MAX_A4_HZ, MIN_A4_HZ } from '../../src/utils/music-tools'
import {
	createPitchSmoother,
	detectPitchFromBuffer,
	formatCentsOffset,
	frequencyToPitchInfo,
} from '../../src/utils/pitch-detector'

function synthesizeWave(
	frequency: number,
	sampleRate = 48000,
	length = 2048,
	harmonics: readonly number[] = [1],
): Float32Array {
	const buf = new Float32Array(length)
	const ampSum = harmonics.reduce((acc, a) => acc + Math.abs(a), 0) || 1
	for (let i = 0; i < length; i++) {
		let v = 0
		for (let h = 0; h < harmonics.length; h++) {
			v += harmonics[h] * Math.sin((2 * Math.PI * frequency * (h + 1) * i) / sampleRate)
		}
		buf[i] = (v / ampSum) * 0.6
	}
	return buf
}

describe('pitch-detector & microphone tuner', () => {
	const originalMediaDevices = navigator.mediaDevices
	const originalAudioContext = (window as unknown as { AudioContext?: unknown }).AudioContext

	afterEach(() => {
		resetSharedAudioContextForTests()
		Object.defineProperty(navigator, 'mediaDevices', {
			value: originalMediaDevices,
			configurable: true,
		})
		Object.defineProperty(window, 'AudioContext', {
			value: originalAudioContext,
			configurable: true,
		})
		vi.restoreAllMocks()
	})

	it('detects pure and harmonic-rich instrument tones accurately and rejects silence', () => {
		const a4Buf = synthesizeWave(440, 48000, 2048)
		const a4 = detectPitchFromBuffer(a4Buf, 48000)
		expect(a4).not.toBeNull()
		expect(Math.abs(a4!.frequency - 440)).toBeLessThan(0.5)
		const a4Info = frequencyToPitchInfo(a4!.frequency, 440, a4!.clarity)
		expect(a4Info).toMatchObject({ noteLabel: 'A4', cents: 0, inTune: true })

		// Violin-like harmonic spectrum at C4 (261.63 Hz) must not jump an octave
		const c4Buf = synthesizeWave(261.6256, 48000, 2048, [1, 0.7, 0.45, 0.2])
		const c4 = detectPitchFromBuffer(c4Buf, 48000)
		expect(c4).not.toBeNull()
		expect(Math.abs(c4!.frequency - 261.6256)).toBeLessThan(0.6)
		const c4Info = frequencyToPitchInfo(c4!.frequency, 440, c4!.clarity)
		expect(c4Info).toMatchObject({ noteLabel: 'C4', inTune: true })

		// Sharp A4 (445 Hz vs A4=440) -> positive cents, out of tune
		const sharp = frequencyToPitchInfo(445, 440)
		expect(sharp?.noteLabel).toBe('A4')
		expect(sharp!.cents).toBeGreaterThan(15)
		expect(sharp?.inTune).toBe(false)

		// Calibrating A4 to 442 Hz makes 442 Hz exactly in tune
		const cal = frequencyToPitchInfo(442, 442)
		expect(cal).toMatchObject({ noteLabel: 'A4', cents: 0, inTune: true })

		// Quiet room-level signal (RMS ~0.0056) still detects pitch cleanly
		const quietBuf = synthesizeWave(440, 48000, 4096).map((v) => v * (0.008 / 0.6))
		const quiet = detectPitchFromBuffer(quietBuf, 48000)
		expect(quiet).not.toBeNull()
		expect(Math.abs(quiet!.frequency - 440)).toBeLessThan(0.5)

		// Silence rejected
		expect(detectPitchFromBuffer(new Float32Array(2048), 48000)).toBeNull()

		expect(formatCentsOffset(0)).toBe('0¢')
		expect(formatCentsOffset(8)).toBe('+8¢')
		expect(formatCentsOffset(-12)).toBe('-12¢')
	})

	it('smooths micro-jitter, rejects single-frame octave spikes, and snaps immediately on real note changes', () => {
		const smoother = createPitchSmoother()
		// Steady A4 with a single-frame octave spike (880 Hz) -> stays rock-steady near 440 Hz
		expect(smoother.push(440)).toBeCloseTo(440, 2)
		expect(Math.abs(smoother.push(441) - 440)).toBeLessThan(1)
		expect(smoother.push(880)).toBeLessThan(442) // outlier rejected by median
		expect(Math.abs(smoother.push(439) - 440)).toBeLessThan(1)
		expect(Math.abs(smoother.push(440) - 440)).toBeLessThan(1)

		// Sustained jump to E4 (329.63 Hz) -> once median shifts, EMA snaps immediately
		smoother.push(329.63)
		smoother.push(329.63)
		const snapped = smoother.push(329.63)
		expect(snapped).toBeCloseTo(329.63, 1)
	})

	it('switches between Pitch Pipe and Tuner tabs, stops drone on mic start, and surfaces permission errors', async () => {
		const deniedErr = new Error('Permission denied')
		deniedErr.name = 'NotAllowedError'
		Object.defineProperty(window, 'AudioContext', {
			value: class FakeAudioContext {
				state = 'running'
				sampleRate = 48000
				resume = vi.fn().mockResolvedValue(undefined)
				close = vi.fn().mockResolvedValue(undefined)
			},
			configurable: true,
		})
		Object.defineProperty(navigator, 'mediaDevices', {
			value: {
				getUserMedia: vi.fn().mockRejectedValue(deniedErr),
			},
			configurable: true,
		})

		const wrapper = mount(PerformancePitchPipePanel, {
			props: {
				activePitchClass: 9,
				activeNoteLabel: 'A4',
				activeFrequency: 440,
				octave: 4,
				a4Hz: 440,
				waveform: 'triangle',
			},
		})

		expect(wrapper.find('[data-test="pitch-pipe-note-grid"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="tuner-section"]').exists()).toBe(false)

		await wrapper.find('[data-test="pitch-mode-tuner"]').trigger('click')
		expect(wrapper.find('[data-test="tuner-section"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="pitch-pipe-note-grid"]').exists()).toBe(false)
		// Shared A4 calibration remains visible in Tuner mode
		expect(wrapper.find('[data-test="pitch-a4-value"]').text()).toBe('440 Hz')

		await wrapper.find('[data-test="tuner-toggle-button"]').trigger('click')
		await Promise.resolve()
		await wrapper.vm.$nextTick()

		// Starting tuner stops any playing pitch-pipe drone to avoid feedback
		expect(wrapper.emitted('stop')).toBeTruthy()
		expect(wrapper.find('[data-test="tuner-error"]').text()).toContain('denied')
	})

	it('renders A4CalibrationControl with bounds enforcement and preset clicks', async () => {
		const wrapper = mount(A4CalibrationControl, { props: { a4Hz: MIN_A4_HZ } })
		expect(wrapper.get('[data-test="pitch-a4-minus"]').attributes('disabled')).toBeDefined()
		expect(wrapper.get('[data-test="pitch-a4-plus"]').attributes('disabled')).toBeUndefined()

		await wrapper.get('[data-test="pitch-a4-plus"]').trigger('click')
		expect(wrapper.emitted('update:a4Hz')?.[0]).toEqual([MIN_A4_HZ + 1])

		await wrapper.get('[data-test="pitch-a4-preset-442"]').trigger('click')
		expect(wrapper.emitted('update:a4Hz')?.[1]).toEqual([442])

		await wrapper.setProps({ a4Hz: MAX_A4_HZ })
		expect(wrapper.get('[data-test="pitch-a4-plus"]').attributes('disabled')).toBeDefined()
	})

	it('renders TunerMeter needle only when cents is present and clamps position to [0%, 100%]', async () => {
		const wrapper = mount(TunerMeter, { props: { cents: null, status: 'idle' } })
		expect(wrapper.find('[data-test="tuner-needle"]').exists()).toBe(false)

		await wrapper.setProps({ cents: 0, status: 'in-tune' })
		expect(wrapper.get('[data-test="tuner-needle"]').attributes('style')).toContain('left: 50%')

		await wrapper.setProps({ cents: -75, status: 'off' })
		expect(wrapper.get('[data-test="tuner-needle"]').attributes('style')).toContain('left: 0%')

		await wrapper.setProps({ cents: 60, status: 'off' })
		expect(wrapper.get('[data-test="tuner-needle"]').attributes('style')).toContain('left: 100%')
	})

	it('renders PerformancePitchPipeSection in idle state and emits waveform and octave changes', async () => {
		const wrapper = mount(PerformancePitchPipeSection, {
			props: {
				activePitchClass: null,
				activeNoteLabel: '',
				activeFrequency: null,
				octave: 4,
				waveform: 'triangle',
			},
		})
		expect(wrapper.get('[data-test="pitch-pipe-status"]').text()).toContain('Tap a note')
		await wrapper.get('[data-test="pitch-waveform-sine"]').trigger('click')
		expect(wrapper.emitted('update:waveform')).toEqual([['sine']])
		await wrapper.get('[data-test="pitch-octave-3"]').trigger('click')
		expect(wrapper.emitted('update:octave')).toEqual([[3]])
	})

	it('falls back to { audio: true } on OverconstrainedError and cleans up tracks on unmount', async () => {
		const stopTrack = vi.fn()
		const overErr = new Error('Overconstrained')
		overErr.name = 'OverconstrainedError'
		const getUserMedia = vi.fn()
			.mockRejectedValueOnce(overErr)
			.mockResolvedValueOnce({ getTracks: () => [{ stop: stopTrack }] })

		const fakeAnalyser = {
			fftSize: 4096,
			connect: vi.fn(),
			disconnect: vi.fn(),
			getFloatTimeDomainData: vi.fn((arr: Float32Array) => { arr.fill(0) }),
		}
		Object.defineProperty(window, 'AudioContext', {
			value: class FakeAudioContext {
				state = 'running'
				sampleRate = 48000
				destination = {}
				resume = vi.fn().mockResolvedValue(undefined)
				close = vi.fn().mockResolvedValue(undefined)
				createMediaStreamSource = vi.fn(() => ({ connect: vi.fn(), disconnect: vi.fn() }))
				createAnalyser = vi.fn(() => fakeAnalyser)
				createGain = vi.fn(() => ({ gain: { value: 1 }, connect: vi.fn(), disconnect: vi.fn() }))
			},
			configurable: true,
		})
		Object.defineProperty(navigator, 'mediaDevices', {
			value: { getUserMedia },
			configurable: true,
		})

		const wrapper = mount(PerformancePitchPipePanel, {
			props: {
				activePitchClass: null,
				activeNoteLabel: '',
				activeFrequency: null,
				octave: 4,
				a4Hz: 440,
				waveform: 'triangle',
			},
		})
		await wrapper.get('[data-test="pitch-mode-tuner"]').trigger('click')
		await wrapper.get('[data-test="tuner-toggle-button"]').trigger('click')
		await vi.waitFor(() => {
			expect(getUserMedia).toHaveBeenCalledTimes(2)
			expect(wrapper.find('[data-test="tuner-level"]').exists()).toBe(true)
		})
		expect(getUserMedia).toHaveBeenLastCalledWith({ audio: true })

		// Switching back to Pitch Pipe unmounts PerformanceTunerSection and stops the mic track
		await wrapper.get('[data-test="pitch-mode-pipe"]').trigger('click')
		expect(stopTrack).toHaveBeenCalledTimes(1)
	})
})
