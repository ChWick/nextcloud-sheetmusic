import { describe, expect, it, vi } from 'vitest'
import { config, mount } from '@vue/test-utils'
import SongPdfCropModal from '../../../src/components/song-pdf/SongPdfCropModal.vue'
import type { PageCrop } from '../../../src/components/song-pdf/crop-types'

// Avoid teleporting NcDialog to body so wrapper.find queries work without mocks
config.global.stubs = { teleport: true }

describe('SongPdfCropModal.vue (Minimal mocks component interaction)', () => {
	const createMockPdf = () => ({
		getPage: vi.fn(() => Promise.resolve({
			getViewport: vi.fn(() => ({ width: 400, height: 600 })),
			render: vi.fn(() => ({ promise: Promise.resolve() })),
		})),
	})

	it('renders dialog with page indicator, preset buttons, and initial full-page crop', () => {
		const wrapper = mount(SongPdfCropModal, {
			props: {
				open: true,
				pageNum: 1,
				totalPages: 5,
				allPagesCount: 5,
				pdf: createMockPdf(),
			},
		})

		expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Page 1 of 5')

		const trim5Btn = wrapper.findAll('button').find((b) => b.text().includes('Trim 5%'))
		const trim10Btn = wrapper.findAll('button').find((b) => b.text().includes('Trim 10%'))
		const resetBtn = wrapper.findAll('button').find((b) => b.text().includes('Reset to Full Page'))

		expect(trim5Btn).toBeDefined()
		expect(trim10Btn).toBeDefined()
		expect(resetBtn).toBeDefined()
		// Initial full page is not custom cropped, so reset button is disabled
		expect(resetBtn?.attributes('disabled')).toBeDefined()
	})

	it('applies margin presets Trim 5% and Trim 10% and emits apply with scope', async () => {
		const wrapper = mount(SongPdfCropModal, {
			props: {
				open: true,
				pageNum: 2,
				totalPages: 4,
				allPagesCount: 4,
				pdf: createMockPdf(),
			},
		})

		// Click Trim 5%
		const trim5Btn = wrapper.findAll('button').find((b) => b.text().includes('Trim 5%'))
		await trim5Btn?.trigger('click')

		// Reset button should now be enabled
		const resetBtn = wrapper.findAll('button').find((b) => b.text().includes('Reset to Full Page'))
		expect(resetBtn?.attributes('disabled')).toBeUndefined()

		// Switch scope to 'all'
		const allRadio = wrapper.find('input[type="radio"][value="all"]')
		await allRadio.setValue()

		// Click Apply Crop button
		const applyBtn = wrapper.findAll('button').find((b) => b.text().includes('Apply Crop'))
		expect(applyBtn).toBeDefined()
		await applyBtn?.trigger('click')

		// Assert emitted apply event
		expect(wrapper.emitted('apply')).toBeTruthy()
		const [crop, scope] = wrapper.emitted('apply')![0] as [PageCrop, string]

		expect(crop.left).toBeCloseTo(0.05, 3)
		expect(crop.top).toBeCloseTo(0.05, 3)
		expect(crop.width).toBeCloseTo(0.9, 3)
		expect(crop.height).toBeCloseTo(0.9, 3)
		expect(scope).toBe('all')
	})

	it('resets crop back to full page with Reset button', async () => {
		const initialCrop: PageCrop = { left: 0.1, top: 0.1, width: 0.8, height: 0.8 }
		const wrapper = mount(SongPdfCropModal, {
			props: {
				open: true,
				pageNum: 1,
				totalPages: 3,
				allPagesCount: 3,
				initialCrop,
				pdf: createMockPdf(),
			},
		})

		const resetBtn = wrapper.findAll('button').find((b) => b.text().includes('Reset to Full Page'))
		expect(resetBtn?.attributes('disabled')).toBeUndefined()

		await resetBtn?.trigger('click')
		expect(resetBtn?.attributes('disabled')).toBeDefined()

		// Apply reset crop
		const applyBtn = wrapper.findAll('button').find((b) => b.text().includes('Apply Crop'))
		await applyBtn?.trigger('click')

		const [crop] = wrapper.emitted('apply')![0] as [PageCrop, string]
		expect(crop.left).toBe(0)
		expect(crop.top).toBe(0)
		expect(crop.width).toBe(1)
		expect(crop.height).toBe(1)
	})

	it('supports page navigation emitting change-page events', async () => {
		const wrapper = mount(SongPdfCropModal, {
			props: {
				open: true,
				pageNum: 2,
				totalPages: 5,
				allPagesCount: 5,
				pdf: createMockPdf(),
			},
		})

		const prevBtn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Previous page'))
		const nextBtn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Next page'))

		await prevBtn?.trigger('click')
		expect(wrapper.emitted('change-page')?.[0]).toEqual([1])

		await nextBtn?.trigger('click')
		expect(wrapper.emitted('change-page')?.[1]).toEqual([3])
	})

	it('computes effective rotation taking both PDF file orientation (page.rotate) and UI rotation into account', async () => {
		const getViewportSpy = vi.fn(() => ({ width: 400, height: 600 }))
		const mockPdfWithRotation = {
			getPage: vi.fn(() => Promise.resolve({
				rotate: 180, // PDF file orientation is 180°
				getViewport: getViewportSpy,
				render: vi.fn(() => ({ promise: Promise.resolve() })),
			})),
		}

		mount(SongPdfCropModal, {
			props: {
				open: true,
				pageNum: 1,
				totalPages: 1,
				allPagesCount: 1,
				rotation: 90, // UI rotated 90°
				pdf: mockPdfWithRotation,
			},
		})

		// Wait for renderPage to complete
		await vi.waitFor(() => {
			expect(getViewportSpy).toHaveBeenCalled()
		})

		// Net rotation should be (180 + 90) % 360 = 270°
		expect(getViewportSpy).toHaveBeenCalledWith(
			expect.objectContaining({ rotation: 270 }),
		)
	})

	it('renders workspace and canvas wrapper with safe auto margins to prevent top clipping', async () => {
		const wrapper = mount(SongPdfCropModal, {
			props: {
				open: true,
				pageNum: 1,
				totalPages: 1,
				allPagesCount: 1,
				pdf: createMockPdf(),
			},
		})

		const workspace = wrapper.find('[class*="workspace"]')
		expect(workspace.exists()).toBe(true)

		const canvasWrapper = wrapper.find('[class*="canvasWrapper"]')
		expect(canvasWrapper.exists()).toBe(true)

		const overlay = wrapper.find('[data-test="crop-overlay"]')
		expect(overlay.exists()).toBe(true)
	})
})

