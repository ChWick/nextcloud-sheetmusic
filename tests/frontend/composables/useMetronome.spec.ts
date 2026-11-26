import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useMetronome } from '../../../src/composables/useMetronome'
import type { Song } from '../../../src/types/song'
import { resetSharedAudioContextForTests } from '../../../src/utils/audio-engine'

function mountMetronome() {
	let api!: ReturnType<typeof useMetronome>
	const Comp = defineComponent({
		setup() {
			api = useMetronome()
			return () => null
		},
	})
	const wrapper = mount(Comp)
	return { wrapper, api }
}

describe('useMetronome', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		resetSharedAudioContextForTests()
	})

	afterEach(() => {
		vi.useRealTimers()
		resetSharedAudioContextForTests()
	})

	it('starts, advances beats, and stops cleanly', () => {
		const { wrapper, api } = mountMetronome()
		api.setBpm(120) // 500ms per beat
		api.setTimeSignature('3/4')

		expect(api.isRunning.value).toBe(false)
		expect(api.currentBeat.value).toBe(-1)

		api.start()
		expect(api.isRunning.value).toBe(true)
		expect(api.currentBeat.value).toBe(0)
		expect(api.isAccentedBeat.value).toBe(true)

		vi.advanceTimersByTime(520)
		expect(api.currentBeat.value).toBe(1)
		expect(api.isAccentedBeat.value).toBe(false)

		vi.advanceTimersByTime(500)
		expect(api.currentBeat.value).toBe(2)

		vi.advanceTimersByTime(500)
		expect(api.currentBeat.value).toBe(0)

		api.stop()
		expect(api.isRunning.value).toBe(false)
		expect(api.currentBeat.value).toBe(-1)
		wrapper.unmount()
	})

	it('adjusts BPM, computes tap tempo, and syncs from song metadata', () => {
		const { wrapper, api } = mountMetronome()
		api.adjustBpm(10)
		expect(api.bpm.value).toBe(130)

		expect(api.tapTempo(1000)).toBeNull()
		expect(api.tapTempo(1600)).toBe(100)
		expect(api.bpm.value).toBe(100)

		const song = {
			id: 5,
			title: 'Waltz',
			tempos: [{ id: 1, songId: 5, tempo: 96 }],
			signatures: [{ id: 2, name: '3/4' }],
		} as unknown as Song

		api.syncFromSong(song)
		expect(api.bpm.value).toBe(96)
		expect(api.timeSignature.value).toEqual({ beats: 3, unit: 4, label: '3/4' })
		wrapper.unmount()
	})
})
