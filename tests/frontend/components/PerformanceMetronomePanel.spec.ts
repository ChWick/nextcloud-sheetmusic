import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PerformanceMetronomePanel from '../../../src/components/setlists/PerformanceMetronomePanel.vue'

describe('PerformanceMetronomePanel', () => {
	it('renders BPM, beat dots, song sync badge, and emits user actions', async () => {
		const wrapper = mount(PerformanceMetronomePanel, {
			props: {
				bpm: 120,
				timeSignature: { beats: 4, unit: 4, label: '4/4' },
				isRunning: true,
				currentBeat: 1,
				accentFirstBeat: true,
				soundEnabled: true,
				visualFlashEnabled: true,
				songTempo: 108,
				songTimeSignature: { beats: 3, unit: 4, label: '3/4' },
			},
		})

		expect(wrapper.get('[data-test="metronome-bpm-value"]').text()).toContain('120')
		expect(wrapper.findAll('[data-test="metronome-beat-dots"] span')).toHaveLength(4)
		expect(wrapper.findAll('[data-test="metronome-beat-dots"] span')[1].attributes('data-active')).toBe('true')
		expect(wrapper.get('[data-test="metronome-song-sync"]').text()).toContain('108 BPM · 3/4')

		await wrapper.get('[data-test="metronome-bpm-plus-5"]').trigger('click')
		expect(wrapper.emitted('adjustBpm')).toEqual([[5]])

		await wrapper.get('[data-test="metronome-sig-3-4"]').trigger('click')
		expect(wrapper.emitted('update:timeSignature')).toEqual([['3/4']])

		await wrapper.get('[data-test="metronome-song-sync"]').trigger('click')
		expect(wrapper.emitted('syncSong')).toHaveLength(1)

		await wrapper.get('[data-test="metronome-start-stop-button"]').trigger('click')
		expect(wrapper.emitted('toggle')).toHaveLength(1)
	})
})
