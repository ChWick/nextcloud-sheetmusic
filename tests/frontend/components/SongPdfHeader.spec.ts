import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPdfHeader from '../../../src/components/song-pdf/SongPdfHeader.vue'
import type { Song, SongFile } from '../../../src/types/song'

describe('SongPdfHeader.vue', () => {
	const mockSong: Song = {
		id: 1,
		title: 'Autumn Leaves',
	}

	const mockFile: SongFile = {
		id: 10,
		path: '/sheetmusic/autumn-leaves.pdf',
	}

	const defaultProps = {
		song: mockSong,
		targetFile: mockFile,
		totalPages: 4,
		remainingPagesCount: 4,
		hasAnyDeletion: false,
		activeMode: 'rotate' as const,
		isSaving: false,
		isLoading: false,
		hasAnyChange: false,
		splitSegmentsCount: 1,
	}

	it('renders title, file name, and total pages', () => {
		const wrapper = mount(SongPdfHeader, {
			props: defaultProps,
		})

		expect(wrapper.text()).toContain('Autumn Leaves')
		expect(wrapper.text()).toContain('autumn-leaves.pdf')
		expect(wrapper.text()).toContain('4 pages')
	})

	it('emits cancel when Cancel button is clicked', async () => {
		const wrapper = mount(SongPdfHeader, {
			props: defaultProps,
		})

		const cancelBtn = wrapper.findAll('button').find((b) => b.text().includes('Cancel'))
		expect(cancelBtn).toBeDefined()
		await cancelBtn?.trigger('click')

		expect(wrapper.emitted('cancel')).toBeTruthy()
	})

	it('emits update:activeMode when mode tabs are clicked', async () => {
		const wrapper = mount(SongPdfHeader, {
			props: defaultProps,
		})

		const cropTab = wrapper.findAll('button').find((b) => b.text().includes('Crop Pages'))
		await cropTab?.trigger('click')
		expect(wrapper.emitted('update:activeMode')?.[0]).toEqual(['crop'])

		const enhanceTab = wrapper.findAll('button').find((b) => b.text().includes('Enhance Scans'))
		await enhanceTab?.trigger('click')
		expect(wrapper.emitted('update:activeMode')?.[1]).toEqual(['enhance'])

		const splitTab = wrapper.findAll('button').find((b) => b.text().includes('Split PDF'))
		await splitTab?.trigger('click')
		expect(wrapper.emitted('update:activeMode')?.[2]).toEqual(['split'])
	})

	it('shows correct button label and handles save in rotate mode', async () => {
		const wrapper = mount(SongPdfHeader, {
			props: {
				...defaultProps,
				hasAnyChange: true,
				hasAnyRotation: true,
			},
		})

		const saveBtn = wrapper.findAll('button').find((b) => b.text().includes('Save Rotations'))
		expect(saveBtn).toBeDefined()
		expect(saveBtn?.attributes('disabled')).toBeUndefined()

		await saveBtn?.trigger('click')
		expect(wrapper.emitted('save')).toBeTruthy()
	})

	it('shows Save Enhanced PDF and disables when no changes in enhance mode', async () => {
		const wrapper = mount(SongPdfHeader, {
			props: {
				...defaultProps,
				activeMode: 'enhance',
				hasAnyChange: false,
			},
		})

		const saveBtn = wrapper.findAll('button').find((b) => b.text().includes('Save Enhanced PDF'))
		expect(saveBtn).toBeDefined()
		expect(saveBtn?.attributes('disabled')).toBeDefined()
	})

	it('shows Split & Create Songs in split mode and disables when 0 segments', () => {
		const wrapper = mount(SongPdfHeader, {
			props: {
				...defaultProps,
				activeMode: 'split',
				splitSegmentsCount: 0,
			},
		})

		const saveBtn = wrapper.findAll('button').find((b) => b.text().includes('Split & Create Songs'))
		expect(saveBtn).toBeDefined()
		expect(saveBtn?.attributes('disabled')).toBeDefined()
	})

	it('renders accessible navigation tabs and title tooltip for responsive layouts', () => {
		const wrapper = mount(SongPdfHeader, {
			props: defaultProps,
		})

		const nav = wrapper.find('nav')
		expect(nav.exists()).toBe(true)
		expect(nav.attributes('aria-label')).toBe('PDF edit modes')

		const titleSpan = wrapper.find('span[title="Autumn Leaves"]')
		expect(titleSpan.exists()).toBe(true)
	})
})

