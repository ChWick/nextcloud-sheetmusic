import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPdfToolbar from '../../../src/components/song-pdf/SongPdfToolbar.vue'

describe('SongPdfToolbar.vue', () => {
	const defaultProps = {
		activeMode: 'rotate' as const,
		totalPages: 5,
		hasAnyRotation: false,
		hasAnyCrop: false,
		hasAnyEnhance: false,
		hasAnyDeletion: false,
		deletedPagesCount: 0,
		selectedPagesCount: 0,
	}

	it('renders rotate actions and emits rotate-all with correct angles', async () => {
		const wrapper = mount(SongPdfToolbar, {
			props: defaultProps,
		})

		const cwBtn = wrapper.findAll('button').find((b) => b.text().includes('Rotate All 90° CW'))
		expect(cwBtn).toBeDefined()
		await cwBtn?.trigger('click')
		expect(wrapper.emitted('rotate-all')?.[0]).toEqual([90])

		const ccwBtn = wrapper.findAll('button').find((b) => b.text().includes('Rotate All 90° CCW'))
		await ccwBtn?.trigger('click')
		expect(wrapper.emitted('rotate-all')?.[1]).toEqual([-90])
	})

	it('disables Reset All when hasAnyRotation is false and enables when true', async () => {
		const wrapper = mount(SongPdfToolbar, {
			props: {
				...defaultProps,
				hasAnyRotation: false,
			},
		})

		const resetBtn = wrapper.findAll('button').find((b) => b.text().includes('Reset All'))
		expect(resetBtn?.attributes('disabled')).toBeDefined()

		await wrapper.setProps({ hasAnyRotation: true })
		expect(resetBtn?.attributes('disabled')).toBeUndefined()
		await resetBtn?.trigger('click')
		expect(wrapper.emitted('reset-rotations')).toBeTruthy()
	})

	it('emits open-rotate-modal', async () => {
		const wrapper = mount(SongPdfToolbar, {
			props: defaultProps,
		})

		const angleBtn = wrapper.findAll('button').find((b) => b.text().includes('Rotate by Angle…'))
		await angleBtn?.trigger('click')
		expect(wrapper.emitted('open-rotate-modal')).toBeTruthy()
	})

	it('renders crop actions and handles crop emits in crop mode', async () => {
		const wrapper = mount(SongPdfToolbar, {
			props: {
				...defaultProps,
				activeMode: 'crop',
				hasAnyCrop: true,
			},
		})

		const cropAllBtn = wrapper.findAll('button').find((b) => b.text().includes('Crop All Pages…'))
		await cropAllBtn?.trigger('click')
		expect(wrapper.emitted('open-crop-modal')).toBeTruthy()

		const resetCropBtn = wrapper.findAll('button').find((b) => b.text().includes('Reset All Crops'))
		await resetCropBtn?.trigger('click')
		expect(wrapper.emitted('reset-all-crops')).toBeTruthy()
	})

	it('renders enhance actions and handles enhance emits in enhance mode', async () => {
		const wrapper = mount(SongPdfToolbar, {
			props: {
				...defaultProps,
				activeMode: 'enhance',
				hasAnyEnhance: true,
			},
		})

		const enhanceAllBtn = wrapper.findAll('button').find((b) => b.text().includes('Enhance All Pages…'))
		await enhanceAllBtn?.trigger('click')
		expect(wrapper.emitted('open-enhance-modal')).toBeTruthy()

		const resetEnhanceBtn = wrapper.findAll('button').find((b) => b.text().includes('Reset All Enhancements'))
		await resetEnhanceBtn?.trigger('click')
		expect(wrapper.emitted('reset-all-enhancements')).toBeTruthy()
	})

	it('shows Restore Deleted button when pages are deleted and emits restore-all-pages', async () => {
		const wrapper = mount(SongPdfToolbar, {
			props: {
				...defaultProps,
				hasAnyDeletion: true,
				deletedPagesCount: 2,
			},
		})

		const restoreBtn = wrapper.findAll('button').find((b) => b.text().includes('Restore Deleted (2)'))
		expect(restoreBtn).toBeDefined()
		await restoreBtn?.trigger('click')
		expect(wrapper.emitted('restore-all-pages')).toBeTruthy()
	})

	it('renders selection count and clear selection in split mode', async () => {
		const wrapper = mount(SongPdfToolbar, {
			props: {
				...defaultProps,
				activeMode: 'split',
				selectedPagesCount: 3,
			},
		})

		expect(wrapper.text()).toContain('3 pages selected')
		const clearBtn = wrapper.findAll('button').find((b) => b.text().includes('Clear Selection'))
		expect(clearBtn).toBeDefined()
		await clearBtn?.trigger('click')
		expect(wrapper.emitted('clear-selection')).toBeTruthy()
	})

	it('renders selection count badge and Clear Selection button in movements mode', async () => {
		const wrapper = mount(SongPdfToolbar, {
			props: {
				...defaultProps,
				activeMode: 'movements',
				selectedPagesCount: 4,
			},
		})

		expect(wrapper.text()).toContain('4 pages selected')
		const clearBtn = wrapper.findAll('button').find((b) => b.text().includes('Clear Selection (4)'))
		expect(clearBtn).toBeDefined()
		await clearBtn?.trigger('click')
		expect(wrapper.emitted('clear-selection')).toBeTruthy()
	})

	it('renders with toolbar accessibility role and structured groups following Nextcloud UX patterns', () => {
		const wrapper = mount(SongPdfToolbar, {
			props: defaultProps,
		})

		const toolbarEl = wrapper.find('[role="toolbar"]')
		expect(toolbarEl.exists()).toBe(true)
		expect(toolbarEl.attributes('aria-label')).toBe('PDF modifier toolbar')
	})

	it('ensures all buttons have titles and icons for mobile horizontal flow', () => {
		const wrapper = mount(SongPdfToolbar, {
			props: {
				...defaultProps,
				hasAnyRotation: true,
				hasAnyDeletion: true,
				deletedPagesCount: 1,
			},
		})

		const buttons = wrapper.findAll('button')
		expect(buttons.length).toBeGreaterThan(0)
		for (const btn of buttons) {
			// Each button should have an aria-label or title for accessibility when text is hidden on mobile
			const label = btn.attributes('aria-label') || btn.attributes('title')
			expect(label).toBeTruthy()
			// Each button should contain an SVG icon
			expect(btn.find('svg').exists()).toBe(true)
		}
	})

	it('emits rotate-all with 180 when Flip All 180° is clicked', async () => {
		const wrapper = mount(SongPdfToolbar, {
			props: defaultProps,
		})

		const flipBtn = wrapper.findAll('button').find((b) => b.text().includes('Flip All 180°'))
		expect(flipBtn).toBeDefined()
		await flipBtn?.trigger('click')
		expect(wrapper.emitted('rotate-all')?.[0]).toEqual([180])
	})

	it('emits select-all-pages, discard-all-edits, and update:thumbnailSize', async () => {
		const splitWrapper = mount(SongPdfToolbar, {
			props: {
				...defaultProps,
				activeMode: 'split',
				selectedPagesCount: 1,
			},
		})
		const selectAllBtn = splitWrapper.findAll('button').find((b) => b.text().includes('Select All'))
		await selectAllBtn?.trigger('click')
		expect(splitWrapper.emitted('select-all-pages')).toBeTruthy()

		const editWrapper = mount(SongPdfToolbar, {
			props: {
				...defaultProps,
				hasAnyChange: true,
				thumbnailSize: 'medium',
			},
		})
		const discardBtn = editWrapper.findAll('button').find((b) => b.text().includes('Discard All'))
		await discardBtn?.trigger('click')
		expect(editWrapper.emitted('discard-all-edits')).toBeTruthy()

		const smallerBtn = editWrapper.findAll('button').find((b) => b.attributes('aria-label') === 'Smaller thumbnails')
		await smallerBtn?.trigger('click')
		expect(editWrapper.emitted('update:thumbnailSize')?.[0]).toEqual(['small'])

		const largerBtn = editWrapper.findAll('button').find((b) => b.attributes('aria-label') === 'Larger thumbnails')
		await largerBtn?.trigger('click')
		expect(editWrapper.emitted('update:thumbnailSize')?.[1]).toEqual(['large'])
	})
})

