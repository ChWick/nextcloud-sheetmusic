/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SetlistFilesSection from '../../../src/components/setlists/SetlistFilesSection.vue'
import type { SetlistFile } from '../../../src/types/song'

vi.mock('@nextcloud/dialogs', () => ({
	FilePickerClosed: class FilePickerClosed extends Error {},
	getFilePickerBuilder: vi.fn(),
	showError: vi.fn(),
}))

function makeFile(overrides: Partial<SetlistFile> = {}): SetlistFile {
	return {
		id: 1,
		setlistId: 10,
		libraryId: 1,
		ocFileId: 100,
		path: 'setlists/notes.pdf',
		filename: 'notes.pdf',
		mimeType: 'application/pdf',
		fileSize: 2048,
		sortOrder: 0,
		createdAt: 100,
		updatedAt: 200,
		...overrides,
	}
}

describe('SetlistFilesSection.vue', () => {
	it('renders files with formatted size and emits preview and remove', async () => {
		const file = makeFile({ id: 3, filename: 'stageplot.pdf', fileSize: 2048 })
		const wrapper = mount(SetlistFilesSection, {
			props: {
				files: [file],
				canWrite: true,
			},
		})

		expect(wrapper.text()).toContain('Supplemental Files / Notes')
		expect(wrapper.text()).toContain('(1)')
		expect(wrapper.text()).toContain('stageplot.pdf')
		expect(wrapper.text()).toMatch(/2(\.0)?\s*KB/i)

		await wrapper.find('button[title*="stageplot.pdf"]').trigger('click')
		expect(wrapper.emitted('preview')?.[0]).toEqual([file])

		await wrapper.find('button[title="Remove"]').trigger('click')
		expect(wrapper.emitted('remove')?.[0]).toEqual([3])
	})

	it('hides upload and remove actions when canWrite is false', () => {
		const wrapper = mount(SetlistFilesSection, {
			props: {
				files: [makeFile()],
				canWrite: false,
			},
		})

		expect(wrapper.find('input[type="file"]').exists()).toBe(false)
		expect(wrapper.find('button[aria-label="Remove notes.pdf"]').exists()).toBe(false)
	})

	it('renders uploadError alert when present', () => {
		const wrapper = mount(SetlistFilesSection, {
			props: {
				files: [],
				uploadError: 'File too large',
			},
		})

		expect(wrapper.find('[role="alert"]').text()).toBe('File too large')
	})
})
