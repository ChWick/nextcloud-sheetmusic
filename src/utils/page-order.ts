/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Result of validating a page order specification.
 */
export interface PageOrderValidationResult {
	valid: boolean
	error?: string
	pages: number[]
}

/**
 * Parses a MobileSheets page order string (e.g. "1-3, 5, 2-1") into an array of 1-based page numbers.
 *
 * @param pageOrderStr The raw page order string, e.g. "1-3, 5" or "1-2, 1-2".
 * @param defaultTotalPages Total number of pages to fallback to if string is empty or blank.
 * @returns Array of 1-based page indices.
 */
export function parsePageOrder(pageOrderStr: string | null | undefined, defaultTotalPages = 1): number[] {
	if (!pageOrderStr || !pageOrderStr.trim()) {
		return defaultTotalPages > 0 ? Array.from({ length: defaultTotalPages }, (_, i) => i + 1) : [1]
	}

	const tokens = pageOrderStr.split(',')
	const pages: number[] = []

	for (const rawToken of tokens) {
		const token = rawToken.trim()
		if (!token) continue

		if (token.includes('-')) {
			const parts = token.split('-').map((p) => p.trim())
			if (parts.length === 2 && /^\d+$/.test(parts[0]) && /^\d+$/.test(parts[1])) {
				const start = parseInt(parts[0], 10)
				const end = parseInt(parts[1], 10)
				if (start <= end) {
					for (let i = start; i <= end; i++) {
						pages.push(i)
					}
				} else {
					for (let i = start; i >= end; i--) {
						pages.push(i)
					}
				}
			}
		} else if (/^\d+$/.test(token)) {
			pages.push(parseInt(token, 10))
		}
	}

	return pages.length > 0 ? pages : (defaultTotalPages > 0 ? Array.from({ length: defaultTotalPages }, (_, i) => i + 1) : [1])
}

/**
 * Validates a page order string against syntax rules and maximum total pages.
 *
 * Rules:
 *  - Whitespace-separated or comma-separated tokens.
 *  - Each token is either a single integer > 0 (e.g. "3") or a range "start-end" (e.g. "1-4", "4-1").
 *  - If totalPages is specified (> 0), no page number may exceed totalPages.
 *  - Empty / whitespace string is valid and represents the default whole-file sequence ("1-N").
 *
 * @param pageOrderStr The input string from user.
 * @param totalPages The total number of pages in the source document (if known).
 */
export function validatePageOrder(pageOrderStr: string | null | undefined, totalPages = 0): PageOrderValidationResult {
	if (!pageOrderStr || !pageOrderStr.trim()) {
		const pages = totalPages > 0 ? Array.from({ length: totalPages }, (_, i) => i + 1) : [1]
		return { valid: true, pages }
	}

	const tokens = pageOrderStr.split(',')
	const pages: number[] = []

	for (let idx = 0; idx < tokens.length; idx++) {
		const token = tokens[idx].trim()
		if (!token) {
			return {
				valid: false,
				error: 'Empty item between commas in page order.',
				pages: [],
			}
		}

		if (token.includes('-')) {
			const parts = token.split('-').map((p) => p.trim())
			if (parts.length !== 2 || !/^\d+$/.test(parts[0]) || !/^\d+$/.test(parts[1])) {
				return {
					valid: false,
					error: `Invalid page range "${token}". Expected format: start-end (e.g. 1-4).`,
					pages: [],
				}
			}

			const start = parseInt(parts[0], 10)
			const end = parseInt(parts[1], 10)

			if (start < 1 || end < 1) {
				return {
					valid: false,
					error: `Page numbers must be 1 or greater (got "${token}").`,
					pages: [],
				}
			}

			if (totalPages > 0 && (start > totalPages || end > totalPages)) {
				return {
					valid: false,
					error: `Page range "${token}" exceeds total document pages (${totalPages}).`,
					pages: [],
				}
			}

			if (start <= end) {
				for (let i = start; i <= end; i++) {
					pages.push(i)
				}
			} else {
				for (let i = start; i >= end; i--) {
					pages.push(i)
				}
			}
		} else {
			if (!/^\d+$/.test(token)) {
				return {
					valid: false,
					error: `Invalid page entry "${token}". Expected a number or range like 1-3.`,
					pages: [],
				}
			}

			const page = parseInt(token, 10)
			if (page < 1) {
				return {
					valid: false,
					error: `Page numbers must be 1 or greater (got "${token}").`,
					pages: [],
				}
			}

			if (totalPages > 0 && page > totalPages) {
				return {
					valid: false,
					error: `Page "${page}" exceeds total document pages (${totalPages}).`,
					pages: [],
				}
			}

			pages.push(page)
		}
	}

	if (pages.length === 0) {
		return {
			valid: false,
			error: 'No valid pages specified in page order.',
			pages: [],
		}
	}

	return { valid: true, pages }
}

/**
 * Compacts a list of 1-based page numbers into a standard MobileSheets page order string.
 * Consecutive strictly increasing numbers (e.g. 1, 2, 3) are condensed into ranges ("1-3").
 *
 * @param pages List of 1-based page numbers.
 */
export function formatPageOrder(pages: number[]): string {
	if (!pages || pages.length === 0) {
		return ''
	}

	const parts: string[] = []
	let rangeStart: number | null = null
	let rangeEnd: number | null = null

	for (let i = 0; i < pages.length; i++) {
		const cur = pages[i]

		if (rangeStart === null) {
			rangeStart = cur
			rangeEnd = cur
		} else if (rangeEnd !== null && cur === rangeEnd + 1) {
			rangeEnd = cur
		} else {
			if (rangeStart === rangeEnd) {
				parts.push(`${rangeStart}`)
			} else if (rangeEnd === rangeStart + 1) {
				parts.push(`${rangeStart}, ${rangeEnd}`)
			} else {
				parts.push(`${rangeStart}-${rangeEnd}`)
			}
			rangeStart = cur
			rangeEnd = cur
		}
	}

	if (rangeStart !== null && rangeEnd !== null) {
		if (rangeStart === rangeEnd) {
			parts.push(`${rangeStart}`)
		} else if (rangeEnd === rangeStart + 1) {
			parts.push(`${rangeStart}, ${rangeEnd}`)
		} else {
			parts.push(`${rangeStart}-${rangeEnd}`)
		}
	}

	return parts.join(', ')
}

/**
 * Generates common page order presets.
 */
export function generatePresetOrder(preset: 'all' | 'reverse' | 'repeat', totalPages: number): string {
	const count = Math.max(1, totalPages)
	switch (preset) {
		case 'all':
			return count === 1 ? '1' : `1-${count}`
		case 'reverse':
			return count === 1 ? '1' : Array.from({ length: count }, (_, i) => count - i).join(', ')
		case 'repeat': {
			const all = count === 1 ? '1' : `1-${count}`
			return `${all}, ${all}`
		}
	}
}
