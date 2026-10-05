import type { SongFile } from '../../../src/types/song'

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongFileSection from '../../../src/components/song-editor/SongFileSection.vue'

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string) => text,
}))

vi.mock('../../../src/components/song-editor/SongFileListItem.vue', () => ({
	default: {
		template: '<li>{{ file.path }}</li>',
		props: ['file', 'disabled'],
	},
}))

vi.mock('../../../src/components/song-editor/SongPendingNotice.vue', () => ({
	default: { template: '<div />' },
}))

describe('SongFileSection.vue', () => {
	const props = {
		currentFiles: [] as SongFile[],
		pendingDeleteFiles: [] as SongFile[],
		pendingDetachFiles: [] as SongFile[],
		pendingAdditions: [],
		saveActionLabel: 'Save and Close',
		isSaving: false,
	}

	it('emits an upload and resets the file input so the same file can be picked again', async () => {
		const wrapper = mount(SongFileSection, { props })
		const input = wrapper.get('input[type="file"]')
		const file = new File(['pdf'], 'score.pdf', { type: 'application/pdf' })

		Object.defineProperty(input.element, 'files', {
			configurable: true,
			value: [file],
		})
		await input.trigger('change')

		expect(wrapper.emitted('uploadFile')).toEqual([[file]])
		expect((input.element as HTMLInputElement).value).toBe('')
	})

	it('emits the Nextcloud picker action and disables file actions while saving', async () => {
		const wrapper = mount(SongFileSection, { props })
		const chooseButton = wrapper.get('button[aria-label="Choose file from Nextcloud"]')

		await chooseButton.trigger('click')
		expect(wrapper.emitted('chooseNextcloudFile')).toHaveLength(1)

		await wrapper.setProps({ isSaving: true })
		expect(chooseButton.attributes('disabled')).toBeDefined()
	})
})
