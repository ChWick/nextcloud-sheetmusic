import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { usePitchPipe } from '../../../src/composables/usePitchPipe'
import { resetSharedAudioContextForTests } from '../../../src/utils/audio-engine'

function mountPitchPipe() {
	let api!: ReturnType<typeof usePitchPipe>
	const Comp = defineComponent({
		setup() {
			api = usePitchPipe()
			return () => null
		},
	})
	const wrapper = mount(Comp)
	return { wrapper, api }
}

describe('usePitchPipe', () => {
	afterEach(() => {
		resetSharedAudioContextForTests()
		vi.restoreAllMocks()
	})

	it('toggles notes, updates frequency with octave and A4 calibration, and stops cleanly', () => {
		const { wrapper, api } = mountPitchPipe()

		expect(api.isPlaying.value).toBe(false)
		expect(api.activeNoteLabel.value).toBe('')
		expect(api.activeFrequency.value).toBeNull()

		api.toggleNote(9) // A4
		expect(api.isPlaying.value).toBe(true)
		expect(api.activeNoteLabel.value).toBe('A4')
		expect(api.activeFrequency.value).toBeCloseTo(440, 3)

		api.setA4Hz(442)
		expect(api.activeFrequency.value).toBeCloseTo(442, 3)

		api.setOctave(5)
		expect(api.activeNoteLabel.value).toBe('A5')
		expect(api.activeFrequency.value).toBeCloseTo(884, 3)

		// Tapping the same note stops playback
		api.toggleNote(9)
		expect(api.isPlaying.value).toBe(false)
		expect(api.activePitchClass.value).toBeNull()

		// Switching between notes keeps playing the new note
		api.toggleNote(0) // C5
		expect(api.activeNoteLabel.value).toBe('C5')
		api.toggleNote(7) // G5
		expect(api.activeNoteLabel.value).toBe('G5')

		api.stop()
		expect(api.isPlaying.value).toBe(false)
		wrapper.unmount()
	})
})
