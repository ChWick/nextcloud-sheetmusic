import { describe, expect, it } from 'vitest'
import {
	formatAuditDuration,
	getIntegrityIssueGroupTitle,
	groupIntegrityIssues,
	keyForIssue,
} from '../../../src/utils/integrity'
import type { IntegrityIssue } from '../../../src/types/song'

describe('utils/integrity', () => {
	it('generates distinct keys for issues', () => {
		const a: IntegrityIssue = {
			type: 'missing_file',
			songId: 1,
			fileId: 2,
			path: '/a.pdf',
			fixable: true,
		} as IntegrityIssue
		const b: IntegrityIssue = {
			type: 'missing_file',
			songId: 1,
			fileId: 3,
			path: '/b.pdf',
			fixable: true,
		} as IntegrityIssue

		expect(keyForIssue(a)).not.toBe(keyForIssue(b))
	})

	it('maps known issue types to titles and falls back to replacing underscores', () => {
		expect(getIntegrityIssueGroupTitle('missing_file')).toBe('Missing Files')
		expect(getIntegrityIssueGroupTitle('page_count_mismatch')).toBe('Page Count Mismatches')
		expect(getIntegrityIssueGroupTitle('custom_unknown_type')).toBe('custom unknown type')
	})

	it('groups issues by type and counts fixable items', () => {
		const issues: IntegrityIssue[] = [
			{ type: 'missing_file', songId: 1, fixable: true } as IntegrityIssue,
			{ type: 'orphan_file', path: '/x.pdf', fixable: false } as IntegrityIssue,
			{ type: 'missing_file', songId: 2, fixable: false } as IntegrityIssue,
		]

		const groups = groupIntegrityIssues(issues)
		expect(groups).toHaveLength(2)
		expect(groups[0]).toMatchObject({
			type: 'missing_file',
			title: 'Missing Files',
			fixableCount: 1,
		})
		expect(groups[0].issues).toHaveLength(2)
		expect(groups[1]).toMatchObject({
			type: 'orphan_file',
			title: 'Orphan Files',
			fixableCount: 0,
		})
	})

	it('formats audit durations in seconds and minutes', () => {
		expect(formatAuditDuration(5)).toBe('5s')
		expect(formatAuditDuration(65)).toBe('1m 05s')
		expect(formatAuditDuration(132)).toBe('2m 12s')
	})
})
