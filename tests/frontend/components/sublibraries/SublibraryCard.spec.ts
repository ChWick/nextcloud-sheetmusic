import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import SublibraryCard from '../../../../src/components/sublibraries/SublibraryCard.vue'
import type { Sublibrary } from '../../../../src/types/library'
import type { Song } from '../../../../src/types/song'

vi.mock('@nextcloud/l10n', () => ({
	translate: vi.fn((_app: string, text: string, vars?: any) => {
		if (vars) {
			let result = text
			for (const [k, v] of Object.entries(vars)) {
				result = result.replace(`{${k}}`, String(v))
			}
			return result
		}
		return text
	}),
}))

describe('SublibraryCard.vue', () => {
	const mockSub: Sublibrary = {
		id: 10,
		libraryId: 1,
		name: 'Organ Repertoire',
		slug: 'organ',
		filters: { instrument: ['Orgel'] },
	}

	const mockSongs = [
		{
			id: 1,
			title: 'Toccata',
			instruments: [{ id: 1, name: 'Orgel' }],
		},
		{
			id: 2,
			title: 'Sonata',
			instruments: [{ id: 2, name: 'Klavier' }],
		},
	] as unknown as Song[]

	it('renders card title, slug, WebDAV path, and matching count', () => {
		const wrapper = mount(SublibraryCard, {
			props: {
				sub: mockSub,
				libraryFolderPath: '/SheetMusic',
				songs: mockSongs,
				isCopied: false,
			},
		})

		expect(wrapper.text()).toContain('Organ Repertoire')
		expect(wrapper.text()).toContain('organ')
		expect(wrapper.text()).toContain('/SheetMusic/_SubLibraries_/organ')
		expect(wrapper.text()).toContain('instrument: Orgel')
		expect(wrapper.text()).toContain('1 matching score(s)')
	})

	it('emits edit event when edit button is clicked', async () => {
		const wrapper = mount(SublibraryCard, {
			props: {
				sub: mockSub,
				libraryFolderPath: '/SheetMusic',
				songs: mockSongs,
				isCopied: false,
			},
		})

		const editBtn = wrapper.findAll('button').find(b => b.attributes('aria-label') === 'Edit sub-library' || b.attributes('title') === 'Edit sub-library')
		expect(editBtn).toBeDefined()
		await editBtn!.trigger('click')

		expect(wrapper.emitted('edit')?.[0]).toEqual([mockSub])
	})

	it('emits delete event when delete button is clicked', async () => {
		const wrapper = mount(SublibraryCard, {
			props: {
				sub: mockSub,
				libraryFolderPath: '/SheetMusic',
				songs: mockSongs,
				isCopied: false,
			},
		})

		const deleteBtn = wrapper.findAll('button').find(b => b.attributes('aria-label') === 'Delete sub-library' || b.attributes('title') === 'Delete sub-library')
		expect(deleteBtn).toBeDefined()
		await deleteBtn!.trigger('click')

		expect(wrapper.emitted('delete')?.[0]).toEqual([mockSub])
	})

	it('emits copyPath event when copy button is clicked', async () => {
		const wrapper = mount(SublibraryCard, {
			props: {
				sub: mockSub,
				libraryFolderPath: '/SheetMusic',
				songs: mockSongs,
				isCopied: false,
			},
		})

		const copyBtn = wrapper.find('button[class*="copyLink"]')
		expect(copyBtn.exists()).toBe(true)
		await copyBtn.trigger('click')

		expect(wrapper.emitted('copyPath')?.[0]).toEqual(['organ'])
	})

	it('emits viewSongs event when view songs button is clicked', async () => {
		const wrapper = mount(SublibraryCard, {
			props: {
				sub: mockSub,
				libraryFolderPath: '/SheetMusic',
				songs: mockSongs,
				isCopied: false,
			},
		})

		const viewBtn = wrapper.findAll('button').find(b => b.text().includes('View songs'))
		expect(viewBtn).toBeDefined()
		await viewBtn!.trigger('click')

		expect(wrapper.emitted('viewSongs')?.[0]).toEqual([mockSub])
	})
})
