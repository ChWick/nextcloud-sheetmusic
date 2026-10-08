/**
 * Normalizes a string for search matching:
 * - Decomposes unicode diacritics / accents (NFD) and strips combining marks
 * - Converts to lower case
 * - Trims outer whitespace
 *
 * Example: 'Piérre' -> 'pierre', 'Händel' -> 'handel', 'Curaçao' -> 'curacao'
 */
export function normalizeSearchString(str: string | null | undefined): string {
	if (!str) return ''
	return str
		.normalize('NFD')
		.replace(/[\u0300-\u036f]/g, '')
		.toLowerCase()
		.trim()
}

