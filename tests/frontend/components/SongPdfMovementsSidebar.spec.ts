import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPdfMovementsSidebar from '../../../src/components/song-pdf/SongPdfMovementsSidebar.vue'
import type { MovementSegment } from '../../../src/components/song-pdf/usePdfMovements'

describe('SongPdfMovementsSidebar.vue', () => {
	const defaultMovements: MovementSegment[] = [
		{ id: 'mvt-1', name: 'I. Allegro', pageNum: 1, endPage: 4 },
		{ id: 'mvt-2', name: 'II. Adagio', pageNum: 5, endPage: 8 },
	]

	const defaultProps = {
		movements: defaultMovements,
		totalPages: 8,
		selectedPages: [],
		isExtractingOutline: false,
	}

	it('renders movement cards with names and page numbers', () => {
		const wrapper = mount(SongPdfMovementsSidebar, {
			props: defaultProps,
		})

		expect(wrapper.text()).toContain('Add Movement')
		expect(wrapper.text()).toContain('Import from PDF Outline')
		expect(wrapper.find('[data-test="movement-card-0"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="movement-card-1"]').exists()).toBe(true)
	})

	it('emits add-movement when Add Movement button is clicked', async () => {
		const wrapper = mount(SongPdfMovementsSidebar, {
			props: defaultProps,
		})

		const addBtn = wrapper.findAll('button').find((b) => b.text().includes('Add Movement'))
		expect(addBtn).toBeDefined()
		await addBtn?.trigger('click')

		expect(wrapper.emitted('add-movement')).toBeTruthy()
	})

	it('emits extract-outline when Import from PDF Outline is clicked', async () => {
		const wrapper = mount(SongPdfMovementsSidebar, {
			props: defaultProps,
		})

		const outlineBtn = wrapper.findAll('button').find((b) => b.text().includes('Import from PDF Outline'))
		expect(outlineBtn).toBeDefined()
		await outlineBtn?.trigger('click')

		expect(wrapper.emitted('extract-outline')).toBeTruthy()
	})

	it('emits remove-movement when delete button is clicked', async () => {
		const wrapper = mount(SongPdfMovementsSidebar, {
			props: defaultProps,
		})

		const deleteBtns = wrapper.findAll('button').filter((b) => b.attributes('title')?.includes('Delete movement'))
		expect(deleteBtns.length).toBe(2)
		await deleteBtns[0].trigger('click')

		expect(wrapper.emitted('remove-movement')?.[0]).toEqual(['mvt-1'])
	})

	it('emits assign-selected when range button is clicked', async () => {
		const wrapper = mount(SongPdfMovementsSidebar, {
			props: {
				...defaultProps,
				selectedPages: [3, 4, 5],
			},
		})

		const assignBtn = wrapper.findAll('button').find((b) => b.text().includes('Pages 3–5'))
		expect(assignBtn).toBeDefined()
		await assignBtn?.trigger('click')

		expect(wrapper.emitted('assign-selected')?.[0]).toEqual(['mvt-1'])
	})
})
