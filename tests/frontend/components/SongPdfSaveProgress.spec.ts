import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPdfSaveProgress from '../../../src/components/song-pdf/SongPdfSaveProgress.vue'

describe('SongPdfSaveProgress.vue', () => {
	it('renders default save text and 0% progress', () => {
		const wrapper = mount(SongPdfSaveProgress)

		expect(wrapper.find('[data-test="pdf-save-progress"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="pdf-save-progress-text"]').text()).toBe('Saving PDF…')
		expect(wrapper.find('[data-test="pdf-save-progress-percent"]').text()).toBe('0%')
		expect(wrapper.find('[data-test="pdf-save-progress-fill"]').attributes('style')).toContain('width: 0%')
	})

	it('renders custom progress text and percentage width correctly', () => {
		const wrapper = mount(SongPdfSaveProgress, {
			props: {
				progressText: 'Processing page 3 of 10',
				progressPercent: 65,
			},
		})

		expect(wrapper.find('[data-test="pdf-save-progress-text"]').text()).toBe('Processing page 3 of 10')
		expect(wrapper.find('[data-test="pdf-save-progress-percent"]').text()).toBe('65%')
		expect(wrapper.find('[data-test="pdf-save-progress-fill"]').attributes('style')).toContain('width: 65%')
		const track = wrapper.find('[role="progressbar"]')
		expect(track.attributes('aria-valuenow')).toBe('65')
		expect(track.attributes('aria-valuemin')).toBe('0')
		expect(track.attributes('aria-valuemax')).toBe('100')
	})
})
