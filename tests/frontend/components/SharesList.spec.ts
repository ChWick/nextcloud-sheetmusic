import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SharesList from '../../../src/components/SharesList.vue'
import type { LibraryShare } from '../../../src/types/library'

vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		props: ['variant', 'size', 'disabled', 'title'],
		emits: ['click'],
		template: '<button type="button" :disabled="disabled" :title="title" @click="$emit(\'click\')"><slot /><slot name="icon" /></button>',
	},
}))

describe('SharesList.vue', () => {
	const userShare: LibraryShare = {
		id: 1,
		fullId: 'ocinternal:1',
		shareType: 0,
		shareWith: 'alice',
		shareWithDisplayName: 'Alice Musician',
		permission: 'write',
		canWrite: true,
	}

	const groupShare: LibraryShare = {
		id: 2,
		fullId: 'ocinternal:2',
		shareType: 1,
		shareWith: 'choir',
		permission: 'read',
		canWrite: false,
	}

	const linkShare: LibraryShare = {
		id: 3,
		fullId: 'ocinternal:3',
		shareType: 3,
		shareWith: '',
		permission: 'read',
		canWrite: false,
		publicUrl: 'https://cloud.test/s/abc',
		webdavUrl: 'https://cloud.test/public.php/dav/files/abc',
	}

	it('renders empty state when shares list is empty', () => {
		const wrapper = mount(SharesList, {
			props: {
				shares: [],
				emptyText: 'No shares yet.',
			},
		})

		expect(wrapper.text()).toContain('No shares yet.')
		expect(wrapper.find('ul').exists()).toBe(false)
	})

	it('renders user, group, and public link shares with appropriate badges', () => {
		const wrapper = mount(SharesList, {
			props: {
				shares: [userShare, groupShare, linkShare],
				emptyText: 'No shares yet.',
			},
		})

		expect(wrapper.findAll('li')).toHaveLength(3)
		expect(wrapper.text()).toContain('Alice Musician')
		expect(wrapper.text()).toContain('Read & Write')
		expect(wrapper.text()).toContain('choir')
		expect(wrapper.text()).toContain('Group')
		expect(wrapper.text()).toContain('Read-only')
		expect(wrapper.text()).toContain('Public Link (MobileSheets sync)')
		expect(wrapper.text()).toContain('Copy link')
	})

	it('emits copy with webdavUrl (falling back to publicUrl) and shows Copied state', async () => {
		const wrapper = mount(SharesList, {
			props: {
				shares: [linkShare],
				emptyText: 'No shares yet.',
				copiedToken: null,
			},
		})

		const copyBtn = wrapper.findAll('button').find((b) => b.text().includes('Copy link'))
		expect(copyBtn).toBeDefined()
		await copyBtn!.trigger('click')

		expect(wrapper.emitted('copy')).toEqual([
			['https://cloud.test/public.php/dav/files/abc', '3'],
		])

		await wrapper.setProps({ copiedToken: '3' })
		expect(wrapper.text()).toContain('Copied')
	})

	it('emits delete and disables delete button while deleting', async () => {
		const wrapper = mount(SharesList, {
			props: {
				shares: [userShare],
				emptyText: 'No shares yet.',
				deletingShareId: null,
			},
		})

		const deleteBtn = wrapper.find('button[aria-label="Delete share"]')
		expect(deleteBtn.attributes('disabled')).toBeUndefined()
		await deleteBtn.trigger('click')

		expect(wrapper.emitted('delete')).toEqual([[userShare]])

		await wrapper.setProps({ deletingShareId: 1 })
		expect(wrapper.find('button[aria-label="Delete share"]').attributes('disabled')).toBeDefined()
	})
})
