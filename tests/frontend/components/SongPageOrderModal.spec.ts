/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi, beforeEach } from 'vitest'
import { config, mount } from '@vue/test-utils'
import SongPageOrderModal from '../../../src/components/song-pdf/SongPageOrderModal.vue'
import type { SongFile } from '../../../src/types/song'

// Avoid teleporting NcDialog to body so wrapper.find queries work without mocks
config.global.stubs = { teleport: true }

vi.mock('../../../src/api/client', () => ({
	apiPost: vi.fn(),
}))

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))

const mockGetHighResPage = vi.fn(() => Promise.resolve('mock-high-res'))

vi.mock('../../../src/components/song-pdf/usePageOrderThumbnails', () => ({
	usePageOrderThumbnails: vi.fn(() => ({
		thumbnails: {
			1: 'thumb-data-1',
			2: 'thumb-data-2',
			3: 'thumb-data-3',
			4: 'thumb-data-4',
		},
		highResCache: {
			1: 'mock-high-res-1',
		},
		isLoading: false,
		isLoadingHighRes: false,
		getHighResPage: mockGetHighResPage,
		loadThumbnails: vi.fn(),
	})),
}))

describe('SongPageOrderModal.vue', () => {
	const sampleFile: SongFile = {
		id: 10,
		path: '/remote.php/dav/files/admin/Noten/Score.pdf',
		pageCount: 4,
		pageOrder: '1-4',
	}

	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('does not render content when open is false', () => {
		const wrapper = mount(SongPageOrderModal, {
			props: {
				open: false,
				file: sampleFile,
				songId: 1,
				libraryId: 2,
			},
		})
		expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
	})

	it('renders dialog with file name, page count and initial page order', () => {
		const wrapper = mount(SongPageOrderModal, {
			props: {
				open: true,
				file: sampleFile,
				songId: 1,
				libraryId: 2,
			},
		})
		expect(wrapper.text()).toContain('Score.pdf')
		expect(wrapper.text()).toContain('4 pages')
		const input = wrapper.find('input')
		expect(input.exists()).toBe(true)
		expect(input.element.value).toBe('1-4')
	})

	it('renders repeated pages in resulting preview when page order is set to 1, 2, 1', () => {
		const wrapper = mount(SongPageOrderModal, {
			props: {
				open: true,
				file: {
					...sampleFile,
					pageOrder: '1, 2, 1',
				},
				songId: 1,
				libraryId: 2,
			},
		})

		const seqCards = wrapper.findAll('[data-test^="seq-card-"]')
		expect(seqCards.length).toBe(3)
		expect(wrapper.find('[data-test="seq-card-0-1"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="seq-card-1-2"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="seq-card-2-1"]').exists()).toBe(true)
	})

	it('updates input and preview when a preset button is clicked', async () => {
		const wrapper = mount(SongPageOrderModal, {
			props: {
				open: true,
				file: sampleFile,
				songId: 1,
				libraryId: 2,
			},
		})
		const buttons = wrapper.findAll('button')
		const reverseBtn = buttons.find((b) => b.text().includes('Reverse Order'))
		expect(reverseBtn).toBeDefined()
		await reverseBtn!.trigger('click')

		const input = wrapper.find('input')
		expect(input.element.value).toBe('4, 3, 2, 1')
	})

	it('appends page number when switching to source pages and clicking Add to Order', async () => {
		const wrapper = mount(SongPageOrderModal, {
			props: {
				open: true,
				file: {
					...sampleFile,
					pageOrder: '1-2',
				},
				songId: 1,
				libraryId: 2,
			},
		})

		// Switch to all source pages tab
		const sourceTab = wrapper.find('[data-test="mode-source-btn"]')
		await sourceTab.trigger('click')

		const addPage3Btn = wrapper.find('button[aria-label="Add page 3 to order"]')
		expect(addPage3Btn.exists()).toBe(true)
		await addPage3Btn.trigger('click')

		const input = wrapper.find('input')
		expect(input.element.value).toBe('1-3')
	})

	it('requests high resolution render when opening page zoom', async () => {
		const wrapper = mount(SongPageOrderModal, {
			props: {
				open: true,
				file: sampleFile,
				songId: 1,
				libraryId: 2,
			},
		})

		const zoomBtn = wrapper.find('[data-test="card-zoom-btn"]')
		expect(zoomBtn.exists()).toBe(true)
		await zoomBtn.trigger('click')

		expect(mockGetHighResPage).toHaveBeenCalledWith(1)
	})

	it('removes item from sequence when clicking remove button on card or chip', async () => {
		const wrapper = mount(SongPageOrderModal, {
			props: {
				open: true,
				file: {
					...sampleFile,
					pageOrder: '1, 2, 3',
				},
				songId: 1,
				libraryId: 2,
			},
		})
		const cardRemoveBtns = wrapper.findAll('[data-test="card-remove-btn"]')
		expect(cardRemoveBtns.length).toBe(3)
		// Remove position 2 (page 2) via preview card remove button
		await cardRemoveBtns[1].trigger('click')

		const input = wrapper.find('input')
		expect(input.element.value).toBe('1, 3')
	})

	it('inserts page into sequence when dropping source page onto grid', async () => {
		const wrapper = mount(SongPageOrderModal, {
			props: {
				open: true,
				file: {
					...sampleFile,
					pageOrder: '1-2',
				},
				songId: 1,
				libraryId: 2,
			},
		})

		const grid = wrapper.findComponent({ name: 'SongPageOrderGrid' })
		expect(grid.exists()).toBe(true)

		// Simulate insert-at event (e.g. Page 4 dropped at index 1 between 1 and 2)
		grid.vm.$emit('insert-at', 4, 1)
		await wrapper.vm.$nextTick()

		const input = wrapper.find('input')
		expect(input.element.value).toBe('1, 4, 2')
	})

	it('reorders sequence when reorder-sequence event is emitted', async () => {
		const wrapper = mount(SongPageOrderModal, {
			props: {
				open: true,
				file: {
					...sampleFile,
					pageOrder: '1, 2, 3',
				},
				songId: 1,
				libraryId: 2,
			},
		})

		const grid = wrapper.findComponent({ name: 'SongPageOrderGrid' })
		expect(grid.exists()).toBe(true)

		// Move item from index 2 (Page 3) to index 0
		grid.vm.$emit('reorder-sequence', 2, 0)
		await wrapper.vm.$nextTick()

		const input = wrapper.find('input')
		expect(input.element.value).toBe('3, 1, 2')
	})

	it('adjusts preview size with zoom controls', async () => {
		const wrapper = mount(SongPageOrderModal, {
			props: {
				open: true,
				file: sampleFile,
				songId: 1,
				libraryId: 2,
			},
		})

		const zoomInBtn = wrapper.find('button[aria-label="Larger preview"]')
		expect(zoomInBtn.exists()).toBe(true)
		await zoomInBtn.trigger('click')
		expect(wrapper.text()).toContain('280px')

		const zoomOutBtn = wrapper.find('button[aria-label="Smaller preview"]')
		await zoomOutBtn.trigger('click')
		expect(wrapper.text()).toContain('240px')
	})

	it('calls apiPost and emits saved event upon saving valid page order', async () => {
		const { apiPost } = await import('../../../src/api/client')
		vi.mocked(apiPost).mockResolvedValue({
			data: {
				ocs: {
					data: { songs: [] },
				},
			},
		} as any)

		const wrapper = mount(SongPageOrderModal, {
			props: {
				open: true,
				file: sampleFile,
				songId: 42,
				libraryId: 99,
			},
		})

		const saveBtn = wrapper.findAll('button').find((b) => b.text().includes('Save Page Order'))
		expect(saveBtn).toBeDefined()
		await saveBtn!.trigger('click')

		expect(apiPost).toHaveBeenCalledWith(
			'/api/songs/{id}/file/{fileId}/page-order',
			{
				libraryId: 99,
				pageOrder: '1-4',
			},
			{
				pathParams: {
					id: 42,
					fileId: 10,
				},
			},
		)
		expect(wrapper.emitted('saved')).toBeTruthy()
		expect(wrapper.emitted('update:open')?.[0]).toEqual([false])
	})
})
