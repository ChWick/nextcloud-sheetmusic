import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPdfLoadingState from '../../../src/components/song-pdf/SongPdfLoadingState.vue'

describe('SongPdfLoadingState.vue', () => {
	it('renders default loading text', () => {
		const wrapper = mount(SongPdfLoadingState)

		expect(wrapper.find('[data-test="pdf-loading-area"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Loading PDF pages…')
	})

	it('renders custom loading text when provided', () => {
		const wrapper = mount(SongPdfLoadingState, {
			props: {
				loadingText: 'Preparing score…',
			},
		})

		expect(wrapper.text()).toContain('Preparing score…')
	})
})
