import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FilesIntegrityIssueItem from '../../../src/components/files/FilesIntegrityIssueItem.vue'
import type { IntegrityIssue } from '../../../src/types/song'

describe('FilesIntegrityIssueItem.vue', () => {
	it('renders Merge Songs and Keep as Two buttons when duplicate issue has multiple songIds', () => {
		const issue: IntegrityIssue = {
			type: 'duplicate_file_path',
			severity: 'error',
			message: 'Multiple database entries point to the same file path',
			songId: 60,
			songTitle: 'Nachspiel',
			fileId: 207,
			path: 'Noten/Nachspiel.pdf',
			fixable: false,
			details: {
				songIds: [60, 61],
				exactDuplicate: false,
			},
		}

		const wrapper = mount(FilesIntegrityIssueItem, {
			props: { issue },
		})

		expect(wrapper.text()).toContain('Merge Songs')
		expect(wrapper.text()).toContain('Keep as Two')
		expect(wrapper.text()).not.toContain('Auto-Fix Issue')
	})

	it('renders Auto-Fix Issue button when issue is fixable and not a multi-song duplicate', () => {
		const issue: IntegrityIssue = {
			type: 'invalid_naming_rule',
			severity: 'warning',
			message: 'File path does not match standard naming rule.',
			songId: 60,
			songTitle: 'Nachspiel',
			fileId: 207,
			path: 'Noten/OldPath.pdf',
			suggestedPath: 'Noten/NewPath.pdf',
			fixable: true,
		}

		const wrapper = mount(FilesIntegrityIssueItem, {
			props: { issue },
		})

		expect(wrapper.text()).toContain('Auto-Fix Issue')
		expect(wrapper.text()).not.toContain('Merge Songs')
		expect(wrapper.text()).not.toContain('Keep as Two')
	})
})
