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

vi.mock('../../../src/components/song-pdf/usePageOrderThumbnails', () => ({
	usePageOrderThumbnails: vi.fn(() => ({
		thumbnails: {
			1: 'thumb-data-1',
			2: 'thumb-data-2',
			3: 'thumb-data-3',
			4: 'thumb-data-4',
		},
		isLoading: false,
		loadAllThumbnails: vi.fn(),
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

	it('appends page number when clicking Add to Order on page card', async () => {
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
		const addPage3Btn = wrapper.find('button[aria-label="Add page 3 to order"]')
		expect(addPage3Btn.exists()).toBe(true)
		await addPage3Btn.trigger('click')

		const input = wrapper.find('input')
		expect(input.element.value).toBe('1-3')
	})

	it('removes item from sequence when clicking remove button on chip', async () => {
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
		const removeBtns = wrapper.findAll('button[aria-label^="Remove position"]')
		expect(removeBtns.length).toBe(3)
		// Remove position 2 (page 2)
		await removeBtns[1].trigger('click')

		const input = wrapper.find('input')
		expect(input.element.value).toBe('1, 3')
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
