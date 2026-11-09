import { describe, expect, it } from 'vitest'
import { normalizeSearchString, slugify } from '../../../src/utils/string'

describe('string utils', () => {
	describe('normalizeSearchString', () => {
		it('returns empty string for null/undefined/empty', () => {
			expect(normalizeSearchString(null)).toBe('')
			expect(normalizeSearchString(undefined)).toBe('')
			expect(normalizeSearchString('')).toBe('')
		})

		it('normalizes diacritics and converts to lowercase', () => {
			expect(normalizeSearchString('Händel')).toBe('handel')
			expect(normalizeSearchString('Piérre')).toBe('pierre')
			expect(normalizeSearchString('Curaçao')).toBe('curacao')
		})

		it('trims whitespace', () => {
			expect(normalizeSearchString('  Chopin  ')).toBe('chopin')
		})
	})

	describe('slugify', () => {
		it('returns empty string for null/undefined/empty', () => {
			expect(slugify(null)).toBe('')
			expect(slugify(undefined)).toBe('')
			expect(slugify('')).toBe('')
		})

		it('converts titles with spaces to hyphens', () => {
			expect(slugify('Organ Repertoire')).toBe('organ-repertoire')
		})

		it('collapses multiple spaces, hyphens, and underscores', () => {
			expect(slugify('Organ   ___  Repertoire --- 2026')).toBe('organ-repertoire-2026')
		})

		it('strips leading and trailing hyphens', () => {
			expect(slugify('-Bach-Chorales-')).toBe('bach-chorales')
		})

		it('preserves unicode letters and numbers', () => {
			expect(slugify('Flöte & Orgel!')).toBe('flöte-orgel')
		})
	})
})
