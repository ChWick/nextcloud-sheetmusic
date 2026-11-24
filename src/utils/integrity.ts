import { translate as t } from '@nextcloud/l10n'
import type { IntegrityIssue } from '../types/song'

export interface IntegrityIssueGroup {
	type: string
	title: string
	issues: IntegrityIssue[]
	fixableCount: number
}

/**
 * Returns a unique key string for an IntegrityIssue object.
 */
export function keyForIssue(issue: IntegrityIssue): string {
	const songIdsStr = issue.details?.songIds?.join('_') ?? ''
	const duplicateIdsStr = issue.details?.duplicateIds?.join('_') ?? ''
	return `${issue.type}-${issue.songId ?? 0}-${issue.fileId ?? 0}-${issue.path ?? ''}-${songIdsStr}-${issue.details?.entityType ?? ''}-${issue.details?.keepId ?? 0}-${duplicateIdsStr}`
}

/**
 * Returns a localized human-readable title for an integrity issue type.
 */
export function getIntegrityIssueGroupTitle(type: string): string {
	switch (type) {
	case 'invalid_naming_rule':
		return t('sheetmusic', 'Invalid Naming Rules')
	case 'duplicate_file_path':
		return t('sheetmusic', 'Duplicate File Paths')
	case 'duplicate_entity_assignment':
		return t('sheetmusic', 'Duplicate Song Assignments')
	case 'duplicate_entity':
		return t('sheetmusic', 'Duplicate Entities')
	case 'missing_file':
		return t('sheetmusic', 'Missing Files')
	case 'orphan_file':
		return t('sheetmusic', 'Orphan Files')
	case 'identical_song':
		return t('sheetmusic', 'Identical Songs (Duplicates)')
	case 'pdf_metadata_mismatch':
		return t('sheetmusic', 'PDF Metadata Mismatches')
	case 'pdf_metadata_unreadable':
		return t('sheetmusic', 'PDF Metadata Could Not Be Verified')
	case 'orphan_entity_assignment':
		return t('sheetmusic', 'Orphan Entity Assignments')
	case 'orphan_song_file':
		return t('sheetmusic', 'Orphan Song Files')
	case 'song_without_files':
		return t('sheetmusic', 'Songs Without Files')
	case 'duplicate_client_id':
		return t('sheetmusic', 'Duplicate Client IDs')
	case 'orphan_song_attribute':
		return t('sheetmusic', 'Orphan Song Attributes')
	case 'orphan_misc_data':
		return t('sheetmusic', 'Orphan Sync Data')
	case 'page_count_mismatch':
		return t('sheetmusic', 'Page Count Mismatches')
	default:
		return type.replace(/_/g, ' ')
	}
}

/**
 * Groups integrity issues by type with localized titles and fixable counts.
 */
export function groupIntegrityIssues(issues: IntegrityIssue[]): IntegrityIssueGroup[] {
	const groups: Record<string, IntegrityIssueGroup> = {}

	for (const issue of issues) {
		const type = issue.type
		if (!groups[type]) {
			groups[type] = {
				type,
				title: getIntegrityIssueGroupTitle(type),
				issues: [],
				fixableCount: 0,
			}
		}

		groups[type].issues.push(issue)
		if (issue.fixable) {
			groups[type].fixableCount++
		}
	}

	return Object.values(groups)
}

/**
 * Formats an elapsed duration in seconds as `Xs` or `Xm YYs`.
 */
export function formatAuditDuration(seconds: number): string {
	const mins = Math.floor(seconds / 60)
	const secs = seconds % 60
	if (mins > 0) {
		return `${mins}m ${secs < 10 ? '0' : ''}${secs}s`
	}
	return `${secs}s`
}
