import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPdfJoinModal from '../../../src/components/song-pdf/SongPdfJoinModal.vue'

vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: {
		name: 'NcDialog',
		props: ['open', 'name'],
		template: '<div v-if="open" role="dialog" class="nc-dialog"><slot /></div>',
	},
}))

describe('SongPdfJoinModal.vue', () => {
	const mockPdf = {
		getPage: vi.fn().mockResolvedValue({
			getViewport: vi.fn().mockReturnValue({ width: 595.28, height: 841.89 }),
			render: vi.fn().mockReturnValue({ promise: Promise.resolve() }),
		}),
	}

	it('renders dialog with page selectors and default vertical layout', () => {
		const wrapper = mount(SongPdfJoinModal, {
			props: {
				open: true,
				pdf: mockPdf,
				totalPages: 5,
				initialPageA: 1,
				initialPageB: 2,
				pageCrops: {},
				pageRotations: [0, 0, 0, 0, 0],
			},
		})

		expect(wrapper.text()).toContain('Select Pages to Join:')
		expect(wrapper.text()).toContain('First Page (Top / Left):')
		expect(wrapper.text()).toContain('Second Page (Bottom / Right):')
		expect(wrapper.text()).toContain('Vertical (Stacked Top / Bottom)')
		expect(wrapper.text()).toContain('Horizontal (Side by Side)')
	})

	it('displays success fit banner when two cropped pages fit on a single page', () => {
		const wrapper = mount(SongPdfJoinModal, {
			props: {
				open: true,
				pdf: mockPdf,
				totalPages: 5,
				initialPageA: 1,
				initialPageB: 2,
				// Page 1 and Page 2 cropped to 40% height each
				pageCrops: {
					1: { left: 0, top: 0, width: 1, height: 0.4 },
					2: { left: 0, top: 0.6, width: 1, height: 0.4 },
				},
				pageRotations: [0, 0, 0, 0, 0],
			},
		})

		expect(wrapper.text()).toContain('Pages fit on a single page!')
	})

	it('displays warning fit banner when two full pages exceed standard page height', () => {
		const wrapper = mount(SongPdfJoinModal, {
			props: {
				open: true,
				pdf: mockPdf,
				totalPages: 5,
				initialPageA: 1,
				initialPageB: 2,
				pageCrops: {}, // full pages
				pageRotations: [0, 0, 0, 0, 0],
			},
		})

		expect(wrapper.text()).toContain('Content exceeds standard page size')
	})

	it('emits join event with selected configuration when Join Pages button is clicked', async () => {
		const wrapper = mount(SongPdfJoinModal, {
			props: {
				open: true,
				pdf: mockPdf,
				totalPages: 5,
				initialPageA: 1,
				initialPageB: 2,
				pageCrops: {
					1: { left: 0, top: 0, width: 1, height: 0.45 },
					2: { left: 0, top: 0.5, width: 1, height: 0.45 },
				},
				pageRotations: [0, 0, 0, 0, 0],
			},
		})

		const joinBtn = wrapper.findAll('button').find((b) => b.text().includes('Join Pages'))
		expect(joinBtn).toBeDefined()
		await joinBtn?.trigger('click')

		expect(wrapper.emitted('join')).toBeTruthy()
		const eventPayload = wrapper.emitted('join')?.[0]?.[0] as any
		expect(eventPayload).toMatchObject({
			pageA: 1,
			pageB: 2,
			layout: 'vertical',
			fitToStandardPage: true,
			scaleToFit: true,
			scaleA: 1.0,
			scaleB: 1.0,
		})
	})

	it('supports aligning widths between pages of different width and emits adjusted scales', async () => {
		const wrapper = mount(SongPdfJoinModal, {
			props: {
				open: true,
				pdf: mockPdf,
				totalPages: 5,
				initialPageA: 1,
				initialPageB: 2,
				// Page 1 is 80% wide, Page 2 is 40% wide
				pageCrops: {
					1: { left: 0, top: 0, width: 0.8, height: 0.4 },
					2: { left: 0, top: 0.5, width: 0.4, height: 0.4 },
				},
				pageRotations: [0, 0, 0, 0, 0],
			},
		})

		// Before alignment, diff status is displayed
		expect(wrapper.find('[data-test="diff-status"]').exists()).toBe(true)

		// Click Align Widths button
		const alignBtn = wrapper.find('[data-test="align-sizes-btn"]')
		expect(alignBtn.exists()).toBe(true)
		await alignBtn.trigger('click')

		// Now widths should be aligned
		expect(wrapper.find('[data-test="aligned-status"]').exists()).toBe(true)

		// Click Join Pages
		const joinBtn = wrapper.findAll('button').find((b) => b.text().includes('Join Pages'))
		await joinBtn?.trigger('click')

		const eventPayload = wrapper.emitted('join')?.[0]?.[0] as any
		expect(eventPayload.scaleA).toBe(1.0)
		// Scale B should be scaled up by 2.0 (0.8 / 0.4)
		expect(eventPayload.scaleB).toBeCloseTo(2.0, 1)
	})

	it('supports manually adjusting page scales and resetting to 100%', async () => {
		const wrapper = mount(SongPdfJoinModal, {
			props: {
				open: true,
				pdf: mockPdf,
				totalPages: 5,
				initialPageA: 1,
				initialPageB: 2,
				pageCrops: {},
				pageRotations: [0, 0, 0, 0, 0],
			},
		})

		// Adjust scale input for page A
		const inputA = wrapper.find('[data-test="scale-a-input"]')
		expect(inputA.exists()).toBe(true)
		await inputA.setValue(120)
		await inputA.trigger('input')

		// Click Reset 100% button
		const resetBtn = wrapper.find('[data-test="reset-sizes-btn"]')
		expect(resetBtn.exists()).toBe(true)
		await resetBtn.trigger('click')

		// Click Join Pages
		const joinBtn = wrapper.findAll('button').find((b) => b.text().includes('Join Pages'))
		await joinBtn?.trigger('click')

		const eventPayload = wrapper.emitted('join')?.[0]?.[0] as any
		expect(eventPayload.scaleA).toBe(1.0)
		expect(eventPayload.scaleB).toBe(1.0)
	})
})

