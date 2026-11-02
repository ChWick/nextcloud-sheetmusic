import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPdfBookmarksSidebar from '../../../src/components/song-pdf/SongPdfBookmarksSidebar.vue'
import type { BookmarkSegment } from '../../../src/components/song-pdf/usePdfBookmarks'

describe('SongPdfBookmarksSidebar.vue', () => {
	const defaultBookmarks: BookmarkSegment[] = [
		{ id: 'bm-1', name: 'I. Allegro', pageNum: 1, endPage: 4 },
		{ id: 'bm-2', name: 'II. Adagio', pageNum: 5, endPage: 8 },
	]

	const defaultProps = {
		bookmarks: defaultBookmarks,
		totalPages: 8,
		selectedPages: [],
		isExtractingOutline: false,
	}

	it('renders bookmark cards with names and page numbers', () => {
		const wrapper = mount(SongPdfBookmarksSidebar, {
			props: defaultProps,
		})

		expect(wrapper.text()).toContain('Add Bookmark')
		expect(wrapper.text()).toContain('Import from PDF Outline')
		expect(wrapper.find('[data-test="bookmark-card-0"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="bookmark-card-1"]').exists()).toBe(true)
	})

	it('emits add-bookmark when Add Bookmark button is clicked', async () => {
		const wrapper = mount(SongPdfBookmarksSidebar, {
			props: defaultProps,
		})

		const addBtn = wrapper.findAll('button').find((b) => b.text().includes('Add Bookmark'))
		expect(addBtn).toBeDefined()
		await addBtn?.trigger('click')

		expect(wrapper.emitted('add-bookmark')).toBeTruthy()
	})

	it('emits extract-outline when Import from PDF Outline is clicked', async () => {
		const wrapper = mount(SongPdfBookmarksSidebar, {
			props: defaultProps,
		})

		const outlineBtn = wrapper.findAll('button').find((b) => b.text().includes('Import from PDF Outline'))
		expect(outlineBtn).toBeDefined()
		await outlineBtn?.trigger('click')

		expect(wrapper.emitted('extract-outline')).toBeTruthy()
	})

	it('emits remove-bookmark when delete button is clicked', async () => {
		const wrapper = mount(SongPdfBookmarksSidebar, {
			props: defaultProps,
		})

		const deleteBtns = wrapper.findAll('button').filter((b) => b.attributes('title')?.includes('Delete bookmark'))
		expect(deleteBtns.length).toBe(2)
		await deleteBtns[0].trigger('click')

		expect(wrapper.emitted('remove-bookmark')?.[0]).toEqual(['bm-1'])
	})

	it('emits assign-selected when range button is clicked', async () => {
		const wrapper = mount(SongPdfBookmarksSidebar, {
			props: {
				...defaultProps,
				selectedPages: [3, 4, 5],
			},
		})

		const assignBtn = wrapper.findAll('button').find((b) => b.text().includes('Pages 3–5'))
		expect(assignBtn).toBeDefined()
		await assignBtn?.trigger('click')

		expect(wrapper.emitted('assign-selected')?.[0]).toEqual(['bm-1'])
	})

	it('emits add-bookmark-from-selection when Add from Selection button is clicked', async () => {
		const wrapper = mount(SongPdfBookmarksSidebar, {
			props: {
				...defaultProps,
				selectedPages: [2, 3, 4],
			},
		})

		const addSelectionBtn = wrapper.findAll('button').find((b) => b.text().includes('Add from Selection (2–4)'))
		expect(addSelectionBtn).toBeDefined()
		await addSelectionBtn?.trigger('click')

		expect(wrapper.emitted('add-bookmark-from-selection')).toBeDefined()
	})

	it('shows PDF Table of Contents library note when autoPdfMetadata is enabled', () => {
		const wrapper = mount(SongPdfBookmarksSidebar, {
			props: {
				...defaultProps,
				autoPdfMetadata: true,
			},
		})

		expect(wrapper.text()).toContain('Table of Contents will also be stored in the PDF')
	})
})
