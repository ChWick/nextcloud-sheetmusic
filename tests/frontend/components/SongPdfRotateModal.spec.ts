import { config, flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongPdfRotateModal from '../../../src/components/song-pdf/SongPdfRotateModal.vue'

// Avoid teleporting NcDialog to body so wrapper.find queries work without mocks
config.global.stubs = { teleport: true }

describe('SongPdfRotateModal.vue', () => {
	const mockPage = {
		getViewport: vi.fn(({ scale }: any = { scale: 1 }) => ({ width: 400 * (scale ?? 1), height: 600 * (scale ?? 1) })),
		render: vi.fn(() => ({ promise: Promise.resolve() })),
	}
	const mockPdf = {
		numPages: 5,
		getPage: vi.fn(() => Promise.resolve(mockPage)),
	}

	it('renders dialog and controls when open', async () => {
		const wrapper = mount(SongPdfRotateModal, {
			props: {
				open: true,
				pageNum: 1,
				totalPages: 5,
				pdf: mockPdf,
				initialAngle: 2.5,
				allPagesCount: 5,
			},
		})

		await flushPromises()

		expect(wrapper.find('input[type="number"]').exists()).toBe(true)
		const numInput = wrapper.find('input[type="number"]').element as HTMLInputElement
		expect(Number(numInput.value)).toBe(2.5)

		// Page indicator
		expect(wrapper.text()).toContain('Page 1 of 5')

		// Alignment grid button
		expect(wrapper.text()).toContain('Alignment Grid')
	})

	it('updates angle when fine-tuning buttons are clicked', async () => {
		const wrapper = mount(SongPdfRotateModal, {
			props: {
				open: true,
				pageNum: 1,
				totalPages: 5,
				pdf: mockPdf,
				initialAngle: 0,
				allPagesCount: 5,
			},
		})

		await flushPromises()

		// Click +1° button
		const plus1Btn = wrapper.findAll('button').find((b) => b.text().includes('+1°'))
		expect(plus1Btn).toBeDefined()
		await plus1Btn?.trigger('click')

		const numInput = wrapper.find('input[type="number"]').element as HTMLInputElement
		expect(Number(numInput.value)).toBe(1)

		// Click +0.5° button
		const plusHalfBtn = wrapper.findAll('button').find((b) => b.text().includes('+0.5°'))
		await plusHalfBtn?.trigger('click')
		expect(Number(numInput.value)).toBe(1.5)

		// Click Reset 0° button
		const resetBtn = wrapper.findAll('button').find((b) => b.text() === '0°')
		await resetBtn?.trigger('click')
		expect(Number(numInput.value)).toBe(0)
	})

	it('toggles alignment grid overlay visibility', async () => {
		const wrapper = mount(SongPdfRotateModal, {
			props: {
				open: true,
				pageNum: 1,
				totalPages: 5,
				pdf: mockPdf,
				initialAngle: 0,
				allPagesCount: 5,
			},
		})

		await flushPromises()

		// Grid is shown by default
		expect(wrapper.find('[data-test="alignment-grid"]').exists()).toBe(true)

		// Click Alignment Grid button to toggle off
		const gridBtn = wrapper.findAll('button').find((b) => b.text().includes('Alignment Grid'))
		await gridBtn?.trigger('click')

		expect(wrapper.find('[data-test="alignment-grid"]').exists()).toBe(false)
	})

	it('emits apply with chosen angle and scope', async () => {
		const wrapper = mount(SongPdfRotateModal, {
			props: {
				open: true,
				pageNum: 2,
				totalPages: 5,
				pdf: mockPdf,
				initialAngle: 0,
				allPagesCount: 5,
			},
		})

		await flushPromises()

		// Set angle to 3.2°
		const numInput = wrapper.find('input[type="number"]')
		await numInput.setValue(3.2)

		// Select all pages radio
		const allRadio = wrapper.find('input[type="radio"][value="all"]')
		await allRadio.setValue(true)

		// Click Apply Rotation
		const applyBtn = wrapper.findAll('button').find((b) => b.text().includes('Apply Rotation'))
		await applyBtn?.trigger('click')

		expect(wrapper.emitted('apply')).toHaveLength(1)
		expect(wrapper.emitted('apply')![0]).toEqual([3.2, 'all', false])
		expect(wrapper.emitted('update:open')).toHaveLength(1)
		expect(wrapper.emitted('update:open')![0]).toEqual([false])
	})

	it('supports toggling cropToOriginalSize checkbox and emits true on apply', async () => {
		const wrapper = mount(SongPdfRotateModal, {
			props: {
				open: true,
				pageNum: 1,
				totalPages: 5,
				pdf: mockPdf,
				initialAngle: 1.5,
				initialCropToOriginalSize: false,
				allPagesCount: 5,
			},
		})

		await flushPromises()

		const checkbox = wrapper.find('input[data-test="crop-to-original-checkbox"]')
		expect(checkbox.exists()).toBe(true)
		await checkbox.setValue(true)

		const pageFrame = wrapper.find('canvas').element.closest('div')?.parentElement
		expect(pageFrame).toBeDefined()
		// Page frame style width and height must match the canvas width and height
		expect(pageFrame?.style.width).toBe(`${wrapper.find('canvas').element.width}px`)
		expect(pageFrame?.style.height).toBe(`${wrapper.find('canvas').element.height}px`)

		const applyBtn = wrapper.findAll('button').find((b) => b.text().includes('Apply Rotation'))
		await applyBtn?.trigger('click')

		expect(wrapper.emitted('apply')![0]).toEqual([1.5, 'current', true])
	})

	it('emits change-page when navigating between pages', async () => {
		const wrapper = mount(SongPdfRotateModal, {
			props: {
				open: true,
				pageNum: 2,
				totalPages: 5,
				pdf: mockPdf,
				initialAngle: 0,
				allPagesCount: 5,
			},
		})

		await flushPromises()

		// Click Next Page button
		const nextBtn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Next page'))
		await nextBtn?.trigger('click')
		expect(wrapper.emitted('change-page')![0]).toEqual([3])

		// Click Prev Page button
		const prevBtn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Previous page'))
		await prevBtn?.trigger('click')
		expect(wrapper.emitted('change-page')![1]).toEqual([1])
	})

	it('scales canvas backing pixels for HiDPI while keeping CSS dimensions at 1x', async () => {
		const originalDpr = Object.getOwnPropertyDescriptor(window, 'devicePixelRatio')
		Object.defineProperty(window, 'devicePixelRatio', { value: 2, configurable: true, writable: true })

		try {
			const wrapper = mount(SongPdfRotateModal, {
				props: {
					open: true,
					pageNum: 1,
					totalPages: 5,
					pdf: mockPdf,
					initialAngle: 0,
					allPagesCount: 5,
				},
			})

			await flushPromises()

			const canvas = wrapper.find('canvas').element as HTMLCanvasElement
			// 400x600 scaled to fit 480x380 -> scale = 380/600 -> css 253x380, backing 506x760
			expect(canvas.style.width).toBe('253px')
			expect(canvas.style.height).toBe('380px')
			expect(canvas.width).toBe(506)
			expect(canvas.height).toBe(760)
		} finally {
			if (originalDpr) {
				Object.defineProperty(window, 'devicePixelRatio', originalDpr)
			} else {
				Object.defineProperty(window, 'devicePixelRatio', { value: 1, configurable: true, writable: true })
			}
		}
	})
})
