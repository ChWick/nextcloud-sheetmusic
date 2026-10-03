import type { IntegrityIssue } from '../types/song'

/**
 * Returns a unique key string for an IntegrityIssue object.
 */
export function keyForIssue(issue: IntegrityIssue): string {
	const songIdsStr = issue.details?.songIds?.join('_') ?? ''
	return `${issue.type}-${issue.songId ?? 0}-${issue.fileId ?? 0}-${issue.path ?? ''}-${songIdsStr}`
}
