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
		expect(wrapper.text()).toContain('Current Path:')
		expect(wrapper.text()).toContain('Noten/OldPath.pdf')
		expect(wrapper.text()).toContain('Expected Path:')
		expect(wrapper.text()).toContain('Noten/NewPath.pdf')
		expect(wrapper.text()).not.toContain('Merge Songs')
		expect(wrapper.text()).not.toContain('Keep as Two')
	})

	it('renders explanation when expected path cannot be determined for invalid naming rule', () => {
		const issue: IntegrityIssue = {
			type: 'invalid_naming_rule',
			severity: 'warning',
			message: 'File path does not match standard naming rule.',
			songId: 60,
			songTitle: 'Nachspiel',
			fileId: 207,
			path: 'Noten/OldPath.pdf',
			suggestedPath: null,
			fixable: false,
		}

		const wrapper = mount(FilesIntegrityIssueItem, {
			props: { issue },
		})

		expect(wrapper.text()).toContain('Current Path:')
		expect(wrapper.text()).toContain('Noten/OldPath.pdf')
		expect(wrapper.text()).toContain('Expected Path:')
		expect(wrapper.text()).toContain('Cannot determine expected path: Song needs a Composer, Artist, or Collection.')
	})

	it('renders merge and keep as two buttons for identical_song issue', () => {
		const issue: IntegrityIssue = {
			type: 'identical_song',
			severity: 'info',
			message: 'Found 2 songs with identical metadata.',
			songId: 10,
			songTitle: 'Identical Song',
			fixable: true,
			details: {
				songIds: [10, 20],
				exactDuplicate: true,
			},
		}

		const wrapper = mount(FilesIntegrityIssueItem, {
			props: { issue },
		})

		expect(wrapper.text()).toContain('Found 2 songs with identical metadata.')
		expect(wrapper.text()).toContain('Merge Songs')
		expect(wrapper.text()).toContain('Keep as Two')
	})

	it('renders Auto-Fix Issue and Fix Orphan File when orphan file is fixable', async () => {
		const issue: IntegrityIssue = {
			type: 'orphan_file',
			severity: 'warning',
			message: 'File "Moonlight.pdf" exists in library storage but is not linked to any song.',
			songId: 42,
			songTitle: 'Moonlight Sonata',
			path: 'Moonlight.pdf',
			fixable: true,
		}

		const wrapper = mount(FilesIntegrityIssueItem, {
			props: { issue },
		})

		expect(wrapper.text()).toContain('Auto-Fix Issue')
		expect(wrapper.text()).toContain('Fix Orphan File')
		expect(wrapper.text()).toContain('Moonlight Sonata')

		const autoFixBtn = wrapper.findAll('button').find(b => b.text().includes('Auto-Fix Issue'))
		expect(autoFixBtn).toBeDefined()
		await autoFixBtn!.trigger('click')

		expect(wrapper.emitted('fixIssue')).toHaveLength(1)
		expect(wrapper.emitted('fixIssue')![0]).toEqual([issue])
	})

	it('does not render Auto-Fix Issue when orphan file is not fixable', () => {
		const issue: IntegrityIssue = {
			type: 'orphan_file',
			severity: 'warning',
			message: 'File "Unknown.pdf" exists in library storage but is not linked to any song.',
			path: 'Unknown.pdf',
			fixable: false,
		}

		const wrapper = mount(FilesIntegrityIssueItem, {
			props: { issue },
		})

		expect(wrapper.text()).not.toContain('Auto-Fix Issue')
		expect(wrapper.text()).toContain('Fix Orphan File')
	})
})
