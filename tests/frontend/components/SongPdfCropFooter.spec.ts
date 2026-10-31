import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPdfCropFooter from '../../../src/components/song-pdf/SongPdfCropFooter.vue'

describe('SongPdfCropFooter.vue', () => {
	const defaultProps = {
		pageNum: 3,
		allPagesCount: 8,
		applyScope: 'current' as const,
	}

	it('renders scope options and emits update:applyScope when changed', async () => {
		const wrapper = mount(SongPdfCropFooter, {
			props: defaultProps,
		})

		expect(wrapper.text()).toContain('Page 3 only')
		expect(wrapper.text()).toContain('All pages (8)')

		const allRadio = wrapper.find('input[type="radio"][value="all"]')
		await allRadio.trigger('change')

		expect(wrapper.emitted('update:applyScope')?.[0]).toEqual(['all'])
	})

	it('emits cancel and apply events when buttons are clicked', async () => {
		const wrapper = mount(SongPdfCropFooter, {
			props: defaultProps,
		})

		const cancelBtn = wrapper.findAll('button').find((b) => b.text().includes('Cancel'))
		const applyBtn = wrapper.findAll('button').find((b) => b.text().includes('Apply Crop'))

		expect(cancelBtn).toBeDefined()
		expect(applyBtn).toBeDefined()

		await cancelBtn?.trigger('click')
		expect(wrapper.emitted('cancel')).toBeTruthy()

		await applyBtn?.trigger('click')
		expect(wrapper.emitted('apply')).toBeTruthy()
	})
})
