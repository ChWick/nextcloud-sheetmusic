import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPdfCardActions from '../../../src/components/song-pdf/SongPdfCardActions.vue'

describe('SongPdfCardActions.vue', () => {
	const defaultProps = {
		pageNum: 1,
		activeMode: 'rotate' as const,
		totalPages: 3,
		isSelected: false,
		isCropped: false,
		isEnhanced: false,
		isDeleted: false,
		canDelete: true,
	}

	it('emits rotate-page with -90 and 90 when rotation buttons are clicked', async () => {
		const wrapper = mount(SongPdfCardActions, { props: defaultProps })

		const buttons = wrapper.findAll('button')
		const ccwBtn = buttons.find((b) => b.attributes('aria-label') === 'Rotate page 90° CCW')
		const cwBtn = buttons.find((b) => b.attributes('aria-label') === 'Rotate page 90° CW')

		await ccwBtn?.trigger('click')
		expect(wrapper.emitted('rotate-page')?.[0]).toEqual([-90])

		await cwBtn?.trigger('click')
		expect(wrapper.emitted('rotate-page')?.[1]).toEqual([90])
	})

	it('emits open-rotate-modal and open-join-modal in rotate mode', async () => {
		const wrapper = mount(SongPdfCardActions, { props: defaultProps })

		const buttons = wrapper.findAll('button')
		const angleBtn = buttons.find((b) => b.attributes('aria-label')?.includes('custom angle'))
		await angleBtn?.trigger('click')
		expect(wrapper.emitted('open-rotate-modal')).toBeTruthy()

		const joinBtn = buttons.find((b) => b.attributes('aria-label')?.includes('Join with page'))
		await joinBtn?.trigger('click')
		expect(wrapper.emitted('open-join-modal')?.[0]).toEqual([2])
	})

	it('emits toggle-delete-page when delete button is clicked', async () => {
		const wrapper = mount(SongPdfCardActions, { props: defaultProps })

		const deleteBtn = wrapper.findAll('button').find((b) => b.attributes('aria-label')?.includes('Delete page 1'))
		await deleteBtn?.trigger('click')
		expect(wrapper.emitted('toggle-delete-page')).toBeTruthy()
	})

	it('renders crop button and emits open-crop-modal in crop mode', async () => {
		const wrapper = mount(SongPdfCardActions, {
			props: {
				...defaultProps,
				activeMode: 'crop',
				isCropped: true,
			},
		})

		const cropBtn = wrapper.findAll('button').find((b) => b.text().includes('Crop'))
		expect(cropBtn?.exists()).toBe(true)
		await cropBtn?.trigger('click')
		expect(wrapper.emitted('open-crop-modal')).toBeTruthy()
	})

	it('renders enhance button and emits open-enhance-modal in enhance mode', async () => {
		const wrapper = mount(SongPdfCardActions, {
			props: {
				...defaultProps,
				activeMode: 'enhance',
				isEnhanced: true,
			},
		})

		const enhanceBtn = wrapper.findAll('button').find((b) => b.text().includes('Enhance'))
		expect(enhanceBtn?.exists()).toBe(true)
		await enhanceBtn?.trigger('click')
		expect(wrapper.emitted('open-enhance-modal')).toBeTruthy()
	})

	it('renders selection checkbox indicator in split mode', async () => {
		const wrapper = mount(SongPdfCardActions, {
			props: {
				...defaultProps,
				activeMode: 'split',
				isSelected: true,
			},
		})

		expect(wrapper.find('svg').exists()).toBe(true)

		await wrapper.setProps({ isSelected: false })
		expect(wrapper.find('span').exists()).toBe(true)
	})

	it('emits rotate-page with 180 when Flip page 180° is clicked', async () => {
		const wrapper = mount(SongPdfCardActions, {
			props: defaultProps,
		})

		const flipBtn = wrapper.findAll('button').find((b) => b.attributes('aria-label') === 'Flip page 180°')
		expect(flipBtn).toBeDefined()
		await flipBtn?.trigger('click')
		expect(wrapper.emitted('rotate-page')?.[0]).toEqual([180])
	})
})
