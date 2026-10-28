import { describe, expect, it, vi } from 'vitest'
import { config, flushPromises, mount } from '@vue/test-utils'
import SongPdfEnhanceModal from '../../../src/components/song-pdf/SongPdfEnhanceModal.vue'

// Avoid teleporting NcDialog to body so wrapper.find queries work without mocks
config.global.stubs = { teleport: true }

describe('SongPdfEnhanceModal.vue', () => {
	const createMockPdf = () => ({
		getPage: vi.fn(() => Promise.resolve({
			getViewport: vi.fn(({ scale = 1 }: { scale?: number } = {}) => ({
				width: 400 * scale,
				height: 600 * scale,
			})),
			render: vi.fn(() => ({ promise: Promise.resolve() })),
		})),
	})

	it('renders dialog with page indicator, operation stack, and preset buttons', () => {
		const wrapper = mount(SongPdfEnhanceModal, {
			props: {
				open: true,
				pageNum: 1,
				totalPages: 5,
				pdf: createMockPdf(),
			},
		})

		expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Page 1 of 5')
		expect(wrapper.text()).toContain('Operation Stack')

		expect(wrapper.text()).toContain('Clean Sheet')
		expect(wrapper.text()).toContain('Color Notes')
		expect(wrapper.text()).toContain('Curves Only')
	})

	it('adds a Gaussian filter operation when clicking + Gaussian Filter', async () => {
		const wrapper = mount(SongPdfEnhanceModal, {
			props: {
				open: true,
				pageNum: 1,
				totalPages: 3,
				pdf: createMockPdf(),
				initialOperations: [],
			},
		})

		// Find the + Gaussian Filter button
		const addGaussianBtn = wrapper.findAll('button').find((b) => b.text().includes('Gaussian Filter') && b.text().includes('Add') === false)
		expect(addGaussianBtn).toBeDefined()
		await addGaussianBtn?.trigger('click')

		expect(wrapper.text()).toContain('Gaussian Filter')
		expect(wrapper.text()).toContain('Filter Kernel Size')
	})

	it('applies preset operations when clicking a preset chip', async () => {
		const wrapper = mount(SongPdfEnhanceModal, {
			props: {
				open: true,
				pageNum: 1,
				totalPages: 3,
				pdf: createMockPdf(),
				initialOperations: [],
			},
		})

		const presetBtn = wrapper.findAll('button').find((b) => b.text().includes('Color Notes'))
		expect(presetBtn).toBeDefined()
		await presetBtn?.trigger('click')

		// Color Notes preset includes Adaptive Thresholding and Gaussian
		expect(wrapper.text()).toContain('Adaptive Thresholding')
		expect(wrapper.text()).toContain('Gaussian Filter')
	})

	it('supports undo and redo of operation modifications', async () => {
		const wrapper = mount(SongPdfEnhanceModal, {
			props: {
				open: true,
				pageNum: 1,
				totalPages: 3,
				pdf: createMockPdf(),
				initialOperations: [],
			},
		})

		// Add Gaussian
		const addGaussianBtn = wrapper.findAll('button').find((b) => b.text().includes('Gaussian Filter') && !b.text().includes('Add'))
		await addGaussianBtn?.trigger('click')
		expect(wrapper.text()).toContain('Gaussian Filter')

		// Undo
		const undoBtn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Undo'))
		expect(undoBtn).toBeDefined()
		await undoBtn?.trigger('click')

		// Empty stack after undo
		expect(wrapper.text()).toContain('No operations stacked')

		// Redo
		const redoBtn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Redo'))
		expect(redoBtn).toBeDefined()
		await redoBtn?.trigger('click')

		expect(wrapper.text()).toContain('Gaussian Filter')
	})

	it('emits apply event with operations and selected scope', async () => {
		const wrapper = mount(SongPdfEnhanceModal, {
			props: {
				open: true,
				pageNum: 2,
				totalPages: 4,
				pdf: createMockPdf(),
			},
		})

		const applyBtn = wrapper.findAll('button').find((b) => b.text().includes('Apply Enhancements'))
		expect(applyBtn).toBeDefined()
		await applyBtn?.trigger('click')

		const emitted = wrapper.emitted('apply')
		expect(emitted).toBeDefined()
		expect(emitted?.[0][0]).toBeInstanceOf(Array)
		// Default scope is 'all'
		expect(emitted?.[0][1]).toBe('all')
	})

	it('emits change-page when navigating pages', async () => {
		const wrapper = mount(SongPdfEnhanceModal, {
			props: {
				open: true,
				pageNum: 2,
				totalPages: 5,
				pdf: createMockPdf(),
			},
		})

		const nextBtn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Next page'))
		await nextBtn?.trigger('click')
		expect(wrapper.emitted('change-page')?.[0]).toEqual([3])

		const prevBtn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Previous page'))
		await prevBtn?.trigger('click')
		expect(wrapper.emitted('change-page')?.[1]).toEqual([1])
	})

	it('supports zooming in, zooming out, wheel zoom, drag panning, and resetting zoom/pan', async () => {
		const wrapper = mount(SongPdfEnhanceModal, {
			props: {
				open: true,
				pageNum: 1,
				totalPages: 3,
				pdf: createMockPdf(),
			},
		})
		await flushPromises()

		const zoomLevelEl = wrapper.find('[data-test="enhance-zoom-level"]')
		const zoomInBtn = wrapper.find('[data-test="enhance-zoom-in"]')
		const zoomOutBtn = wrapper.find('[data-test="enhance-zoom-out"]')
		const zoomResetBtn = wrapper.find('[data-test="enhance-zoom-reset"]')
		const previewPane = wrapper.find('[data-test="enhance-preview-pane"]')
		const canvasWrapper = wrapper.find('[data-test="enhance-canvas-wrapper"]')

		expect(zoomLevelEl.text()).toBe('100%')
		expect(zoomResetBtn.attributes('disabled')).toBeDefined()

		// Zoom in twice (100% -> 125% -> 150%)
		await zoomInBtn.trigger('click')
		expect(zoomLevelEl.text()).toBe('125%')
		await zoomInBtn.trigger('click')
		expect(zoomLevelEl.text()).toBe('150%')
		expect(zoomResetBtn.attributes('disabled')).toBeUndefined()

		// Pan by dragging on the preview pane
		await previewPane.trigger('mousedown', { button: 0, clientX: 200, clientY: 200 })
		await previewPane.trigger('mousemove', { clientX: 260, clientY: 150 })
		await previewPane.trigger('mouseup')

		expect(canvasWrapper.attributes('style')).toContain('translate(60px, -50px)')

		// Wheel zoom in
		await previewPane.trigger('wheel', { deltaY: -100, clientX: 200, clientY: 200 })
		expect(Number.parseInt(zoomLevelEl.text(), 10)).toBeGreaterThan(150)

		// Zoom out
		await zoomOutBtn.trigger('click')
		expect(Number.parseInt(zoomLevelEl.text(), 10)).toBeLessThanOrEqual(150)

		// Reset zoom and pan
		await zoomResetBtn.trigger('click')
		expect(zoomLevelEl.text()).toBe('100%')
		expect(canvasWrapper.attributes('style')).toContain('translate(0px, 0px)')
	})

	it('renders canvas at native PDF scan DPI and fits landscape pages without overflowing the preview pane', async () => {
		const createLandscape300DpiPdf = () => ({
			getPage: vi.fn(() => Promise.resolve({
				getViewport: vi.fn(({ scale = 1 }: { scale?: number } = {}) => ({
					width: 841.89 * scale,
					height: 595.28 * scale,
				})),
				getOperatorList: vi.fn(() => Promise.resolve({
					fnArray: [10, 12, 85, 11],
					argsArray: [
						null,
						[841.89, 0, 0, 595.28, 0, 0],
						['img_landscape', 3508, 2480],
						null,
					],
				})),
				render: vi.fn(() => ({ promise: Promise.resolve() })),
			})),
		})

		const wrapper = mount(SongPdfEnhanceModal, {
			props: {
				open: true,
				pageNum: 1,
				totalPages: 1,
				pdf: createLandscape300DpiPdf(),
			},
		})
		await flushPromises()

		// Native buffer dimensions are preserved at full 300 DPI (3508 x 2480)
		const canvas = wrapper.find('canvas').element as HTMLCanvasElement
		expect(canvas.dataset.nativeWidth).toBe('3508')
		expect(canvas.dataset.nativeHeight).toBe('2480')
		expect(canvas.width).toBe(3508)
		expect(canvas.height).toBe(2480)

		// Display wrapper at 100% fit uses the expanded preview pane (~3x area: 960 x 679 px)
		const canvasWrapper = wrapper.find('[data-test="enhance-canvas-wrapper"]')
		const styleAttr = canvasWrapper.attributes('style') || ''
		expect(styleAttr).toContain('width: 960px')
		expect(styleAttr).toContain('height: 679px')

		// Operation stack remains rendered and accessible alongside the landscape preview
		expect(wrapper.text()).toContain('Operation Stack')
	})
})
