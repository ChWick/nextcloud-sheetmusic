import { describe, expect, it, vi } from 'vitest'
import { config, mount } from '@vue/test-utils'
import SongPdfEnhanceModal from '../../../src/components/song-pdf/SongPdfEnhanceModal.vue'

// Avoid teleporting NcDialog to body so wrapper.find queries work without mocks
config.global.stubs = { teleport: true }

describe('SongPdfEnhanceModal.vue', () => {
	const createMockPdf = () => ({
		getPage: vi.fn(() => Promise.resolve({
			getViewport: vi.fn(() => ({ width: 400, height: 600 })),
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
})

