import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongPdfRotateModal from '../../../src/components/song-pdf/SongPdfRotateModal.vue'

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string, vars?: any) => {
		if (!vars) return text
		let result = text
		for (const [k, v] of Object.entries(vars)) {
			result = result.replace(`{${k}}`, String(v))
		}
		return result
	},
}))

vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: {
		name: 'NcDialog',
		props: ['open', 'name', 'size', 'canClose'],
		template: '<div v-if="open" class="mock-dialog"><slot /></div>',
	},
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		props: ['variant', 'size', 'disabled', 'title'],
		emits: ['click'],
		template: '<button :disabled="disabled" :title="title" @click="$emit(\'click\', $event)"><slot name="icon" /><slot /></button>',
	},
}))

vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({
	default: {
		name: 'NcLoadingIcon',
		template: '<div class="mock-loading" />',
	},
}))

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
})
