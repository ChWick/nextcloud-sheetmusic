import { describe, expect, it } from 'vitest'
import { firstQueryValue, hasQueryParam, parsePositiveIntQueryParam } from '../../../src/utils/route'

describe('route query utils', () => {
	it('extracts the first non-empty string value', () => {
		expect(firstQueryValue('42')).toBe('42')
		expect(firstQueryValue(['12', '34'])).toBe('12')
		expect(firstQueryValue('')).toBeNull()
		expect(firstQueryValue([''])).toBeNull()
		expect(firstQueryValue(null)).toBeNull()
		expect(firstQueryValue(undefined)).toBeNull()
	})

	it('checks query parameter presence', () => {
		expect(hasQueryParam('abc')).toBe(true)
		expect(hasQueryParam(['abc'])).toBe(true)
		expect(hasQueryParam('')).toBe(false)
		expect(hasQueryParam(undefined)).toBe(false)
	})

	it('parses positive integer query params', () => {
		expect(parsePositiveIntQueryParam('42')).toBe(42)
		expect(parsePositiveIntQueryParam(['7', '8'])).toBe(7)
		expect(parsePositiveIntQueryParam('0')).toBeNull()
		expect(parsePositiveIntQueryParam('-5')).toBeNull()
		expect(parsePositiveIntQueryParam('3.14')).toBeNull()
		expect(parsePositiveIntQueryParam('abc')).toBeNull()
		expect(parsePositiveIntQueryParam(undefined)).toBeNull()
	})
})
