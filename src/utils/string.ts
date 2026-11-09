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

/**
 * Converts a string into a clean URL- and path-safe slug:
 * - Lowercases and trims whitespace
 * - Replaces whitespace, underscores, and consecutive hyphens with a single hyphen
 * - Strips characters that are not unicode letters, numbers, or hyphens
 * - Trims leading and trailing hyphens
 */
export function slugify(text: string | null | undefined): string {
	if (!text) return ''
	return text
		.toLowerCase()
		.trim()
		.replace(/[^\p{L}\p{N}\s-]/gu, '')
		.replace(/[\s_-]+/g, '-')
		.replace(/^-+|-+$/g, '')
}

