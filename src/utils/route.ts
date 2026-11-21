/**
 * Extracts the first non-empty string value from a Vue Router query parameter.
 */
export function firstQueryValue(raw: unknown): string | null {
	const value = Array.isArray(raw) ? raw[0] : raw
	return typeof value === 'string' && value !== '' ? value : null
}

/**
 * Returns true when a Vue Router query parameter is present and non-empty.
 */
export function hasQueryParam(raw: unknown): boolean {
	return firstQueryValue(raw) !== null
}

/**
 * Parses a Vue Router query parameter as a positive integer ID, returning null if missing or invalid.
 */
export function parsePositiveIntQueryParam(raw: unknown): number | null {
	const value = firstQueryValue(raw)
	if (value === null) return null
	const parsed = Number(value)
	return Number.isInteger(parsed) && parsed > 0 ? parsed : null
}
