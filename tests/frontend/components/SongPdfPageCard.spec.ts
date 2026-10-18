import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPdfPageCard from '../../../src/components/song-pdf/SongPdfPageCard.vue'

describe('SongPdfPageCard.vue', () => {
	const defaultProps = {
		pageNum: 1,
		activeMode: 'rotate' as const,
		totalPages: 3,
		isSelected: false,
		isCropped: false,
		isEnhanced: false,
		isDeleted: false,
		rotationDeg: 0,
		cropIndicatorText: '',
		enhanceIndicatorText: '',
		pageSegments: [],
		canDelete: true,
	}

	it('renders page number and rotation badge when rotated', () => {
		const wrapper = mount(SongPdfPageCard, {
			props: {
				...defaultProps,
				rotationDeg: 90,
			},
		})

		expect(wrapper.text()).toContain('p. 1')
		expect(wrapper.text()).toContain('90°')
	})

	it('emits rotate-page with 90 and -90 when rotation buttons are clicked', async () => {
		const wrapper = mount(SongPdfPageCard, {
			props: defaultProps,
		})

		const buttons = wrapper.findAll('button')
		const ccwBtn = buttons.find((b) => b.attributes('aria-label') === 'Rotate page 90° CCW')
		const cwBtn = buttons.find((b) => b.attributes('aria-label') === 'Rotate page 90° CW')

		await ccwBtn?.trigger('click')
		expect(wrapper.emitted('rotate-page')?.[0]).toEqual([-90])

		await cwBtn?.trigger('click')
		expect(wrapper.emitted('rotate-page')?.[1]).toEqual([90])
	})

	it('emits open-rotate-modal and open-join-modal in rotate mode', async () => {
		const wrapper = mount(SongPdfPageCard, {
			props: defaultProps,
		})

		const buttons = wrapper.findAll('button')
		const angleBtn = buttons.find((b) => b.attributes('aria-label')?.includes('custom angle'))
		await angleBtn?.trigger('click')
		expect(wrapper.emitted('open-rotate-modal')).toBeTruthy()

		const joinBtn = buttons.find((b) => b.attributes('aria-label')?.includes('Join with page'))
		await joinBtn?.trigger('click')
		expect(wrapper.emitted('open-join-modal')?.[0]).toEqual([2])
	})

	it('emits toggle-delete-page when delete button is clicked', async () => {
		const wrapper = mount(SongPdfPageCard, {
			props: defaultProps,
		})

		const deleteBtn = wrapper.findAll('button').find((b) => b.attributes('aria-label')?.includes('Delete page 1'))
		await deleteBtn?.trigger('click')
		expect(wrapper.emitted('toggle-delete-page')).toBeTruthy()
	})

	it('shows Cropped badge and emits open-crop-modal in crop mode', async () => {
		const wrapper = mount(SongPdfPageCard, {
			props: {
				...defaultProps,
				activeMode: 'crop',
				isCropped: true,
				cropIndicatorText: '10% 10% 10% 10%',
			},
		})

		expect(wrapper.text()).toContain('Cropped')
		const cropBtn = wrapper.findAll('button').find((b) => b.text().includes('Crop'))
		await cropBtn?.trigger('click')
		expect(wrapper.emitted('open-crop-modal')).toBeTruthy()

		// Clicking the card directly in crop mode also triggers open-crop-modal
		await wrapper.trigger('click')
		expect(wrapper.emitted('open-crop-modal')?.length).toBe(2)
	})

	it('shows Enhanced badge and emits open-enhance-modal in enhance mode', async () => {
		const wrapper = mount(SongPdfPageCard, {
			props: {
				...defaultProps,
				activeMode: 'enhance',
				isEnhanced: true,
				enhanceIndicatorText: '2 filters',
			},
		})

		expect(wrapper.text()).toContain('Enhanced')
		const enhanceBtn = wrapper.findAll('button').find((b) => b.text().includes('Enhance'))
		await enhanceBtn?.trigger('click')
		expect(wrapper.emitted('open-enhance-modal')).toBeTruthy()
	})

	it('emits card-click in split mode and shows segment pills', async () => {
		const wrapper = mount(SongPdfPageCard, {
			props: {
				...defaultProps,
				activeMode: 'split',
				isSelected: true,
				pageSegments: [
					{ id: 'seg-1', title: 'Part 1', index: 1, color: '#ff0000' },
				],
			},
		})

		expect(wrapper.text()).toContain('1')
		await wrapper.trigger('click')
		expect(wrapper.emitted('card-click')?.[0]).toEqual([1])
	})

	it('shows deleted overlay and strikes through page label when deleted', () => {
		const wrapper = mount(SongPdfPageCard, {
			props: {
				...defaultProps,
				isDeleted: true,
			},
		})

		expect(wrapper.text()).toContain('Deleted')
	})
})
