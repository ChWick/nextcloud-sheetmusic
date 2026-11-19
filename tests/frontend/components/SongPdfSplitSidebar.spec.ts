import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPdfSplitSidebar, { type SplitSegment } from '../../../src/components/song-pdf/SongPdfSplitSidebar.vue'

describe('SongPdfSplitSidebar.vue', () => {
	const defaultSegments: SplitSegment[] = [
		{ id: 'seg-1', title: 'Part 1', startPage: 1, endPage: 3 },
		{ id: 'seg-2', title: 'Part 2', startPage: 4, endPage: 6 },
	]

	const defaultProps = {
		segments: defaultSegments,
		originalSongAction: 'keep' as const,
		attachedFilesCount: 1,
		totalPages: 6,
		selectedPages: [],
	}

	it('renders segment cards with titles and page ranges', () => {
		const wrapper = mount(SongPdfSplitSidebar, {
			props: defaultProps,
		})

		expect(wrapper.text()).toContain('Split Segments')
		const inputs = wrapper.findAll('input')
		expect(inputs.length).toBeGreaterThanOrEqual(4)
	})

	it('emits add-segment when Add Part button is clicked', async () => {
		const wrapper = mount(SongPdfSplitSidebar, {
			props: defaultProps,
		})

		const addBtn = wrapper.findAll('button').find((b) => b.text().includes('Add Part'))
		expect(addBtn).toBeDefined()
		await addBtn?.trigger('click')

		expect(wrapper.emitted('add-segment')).toBeTruthy()
	})

	it('emits remove-segment when remove button is clicked', async () => {
		const wrapper = mount(SongPdfSplitSidebar, {
			props: defaultProps,
		})

		const removeButtons = wrapper.findAll('button').filter((b) => b.attributes('title')?.includes('Remove part'))
		expect(removeButtons.length).toBe(2)
		await removeButtons[1].trigger('click')

		expect(wrapper.emitted('remove-segment')?.[0]).toEqual([1])
	})

	it('shows Assign selected button when pages are selected and emits assign-selected', async () => {
		const wrapper = mount(SongPdfSplitSidebar, {
			props: {
				...defaultProps,
				selectedPages: [2, 3, 4],
			},
		})

		const assignBtn = wrapper.findAll('button').find((b) => b.text().includes('Assign selected (2–4)'))
		expect(assignBtn).toBeDefined()
		await assignBtn?.trigger('click')

		expect(wrapper.emitted('assign-selected')?.[0]).toEqual([defaultSegments[0]])
	})

	it('updates originalSongAction through OriginalSongActionSelector', async () => {
		const wrapper = mount(SongPdfSplitSidebar, {
			props: {
				...defaultProps,
				canRemovePages: false,
			},
		})

		const selector = wrapper.findComponent({ name: 'OriginalSongActionSelector' })
		expect(selector.exists()).toBe(true)
		expect(selector.props('canRemovePages')).toBe(false)
		selector.vm.$emit('update:modelValue', 'delete-file')

		expect(wrapper.emitted('update:originalSongAction')?.[0]).toEqual(['delete-file'])
	})

	it('renders a separate scrollable segment list pane for many splits and scrolls down when a new segment is added', async () => {
		const manySegments: SplitSegment[] = Array.from({ length: 20 }, (_, i) => ({
			id: `seg-${i + 1}`,
			title: `Part ${i + 1}`,
			startPage: i + 1,
			endPage: i + 1,
		}))

		const wrapper = mount(SongPdfSplitSidebar, {
			props: {
				...defaultProps,
				totalPages: 25,
				segments: manySegments,
			},
		})

		const listEl = wrapper.find('[data-test="split-segment-list"]')
		expect(listEl.exists()).toBe(true)
		expect(listEl.findAll('input[type="number"]').length).toBe(40)

		Object.defineProperty(listEl.element, 'scrollHeight', { value: 1800, configurable: true })
		listEl.element.scrollTop = 0

		await wrapper.setProps({
			segments: [
				...manySegments,
				{ id: 'seg-21', title: 'Part 21', startPage: 21, endPage: 21 },
			],
		})
		await wrapper.vm.$nextTick()

		expect(listEl.element.scrollTop).toBe(1800)
	})

	it('toggles aria-expanded on the mobile collapse button', async () => {
		const wrapper = mount(SongPdfSplitSidebar, {
			props: defaultProps,
		})

		const toggleBtn = wrapper.find('button[aria-expanded]')
		expect(toggleBtn.exists()).toBe(true)
		expect(toggleBtn.attributes('aria-expanded')).toBe('true')

		await toggleBtn.trigger('click')
		expect(toggleBtn.attributes('aria-expanded')).toBe('false')
	})
})

