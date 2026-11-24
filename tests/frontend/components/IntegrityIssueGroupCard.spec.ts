import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import IntegrityIssueGroupCard from '../../../src/components/files/IntegrityIssueGroupCard.vue'
import type { IntegrityIssueGroup } from '../../../src/utils/integrity'
import type { IntegrityIssue } from '../../../src/types/song'

vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		props: ['variant', 'size', 'disabled'],
		emits: ['click'],
		template: '<button type="button" :disabled="disabled" @click="$emit(\'click\', $event)"><slot /><slot name="icon" /></button>',
	},
}))

vi.mock('../../../src/components/files/FilesIntegrityIssueItem.vue', () => ({
	default: {
		name: 'FilesIntegrityIssueItem',
		props: ['issue', 'isFixing'],
		emits: ['openSong', 'fixIssue', 'mergeSongs', 'fixOrphanFile', 'deleteMissingFile'],
		template: '<li class="issue-item-stub" :data-fixing="String(isFixing)">{{ issue.type }}</li>',
	},
}))

describe('IntegrityIssueGroupCard.vue', () => {
	const issueA: IntegrityIssue = {
		type: 'missing_file',
		songId: 1,
		fileId: 10,
		path: '/a.pdf',
		fixable: true,
	} as IntegrityIssue

	const issueB: IntegrityIssue = {
		type: 'missing_file',
		songId: 2,
		fileId: 11,
		path: '/b.pdf',
		fixable: false,
	} as IntegrityIssue

	const group: IntegrityIssueGroup = {
		type: 'missing_file',
		title: 'Missing Files',
		issues: [issueA, issueB],
		fixableCount: 1,
	}

	it('renders group header, count badge, fix-group button, and expanded issues', () => {
		const wrapper = mount(IntegrityIssueGroupCard, {
			props: {
				group,
				collapsed: false,
				isBatchFixing: false,
				fixingKey: null,
				mergingKey: null,
			},
		})

		expect(wrapper.text()).toContain('Missing Files')
		expect(wrapper.text()).toContain('2')
		expect(wrapper.text()).toContain('Fix All in Group (1)')
		expect(wrapper.findAll('.issue-item-stub')).toHaveLength(2)
	})

	it('hides issue list when collapsed and emits toggle on header click', async () => {
		const wrapper = mount(IntegrityIssueGroupCard, {
			props: {
				group,
				collapsed: true,
				isBatchFixing: false,
				fixingKey: null,
				mergingKey: null,
			},
		})

		expect(wrapper.findAll('.issue-item-stub')).toHaveLength(0)

		const toggleBtn = wrapper.find('button[aria-label="Expand group"]')
		await toggleBtn.trigger('click')
		expect(wrapper.emitted('toggle')).toEqual([['missing_file']])
	})

	it('emits fixGroup and hides button when fixableCount is 0', async () => {
		const wrapper = mount(IntegrityIssueGroupCard, {
			props: {
				group,
				collapsed: false,
				isBatchFixing: false,
				fixingKey: null,
				mergingKey: null,
			},
		})

		const fixBtn = wrapper.findAll('button').find((b) => b.text().includes('Fix All in Group'))
		expect(fixBtn).toBeDefined()
		await fixBtn!.trigger('click')
		expect(wrapper.emitted('fixGroup')).toEqual([['missing_file']])

		await wrapper.setProps({
			group: { ...group, fixableCount: 0 },
		})
		expect(wrapper.text()).not.toContain('Fix All in Group')
	})
})
