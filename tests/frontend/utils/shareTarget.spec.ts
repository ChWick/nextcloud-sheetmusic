import { describe, expect, it } from 'vitest'
import {
	clearShareTargetParam,
	defaultTitleFromFilename,
	hasShareTargetParam,
	isSupportedSharedFile,
} from '../../../src/utils/shareTarget'

describe('shareTarget utils', () => {
	describe('isSupportedSharedFile', () => {
		it('accepts PDF and DOCX files by extension or MIME type', () => {
			expect(isSupportedSharedFile(new File(['a'], 'Score.pdf', { type: 'application/pdf' }))).toBe(true)
			expect(isSupportedSharedFile(new File(['a'], 'Notes.DOCX', { type: '' }))).toBe(true)
			expect(isSupportedSharedFile(new File(['a'], 'document', {
				type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			}))).toBe(true)
		})

		it('rejects unsupported file types', () => {
			expect(isSupportedSharedFile(new File(['a'], 'photo.png', { type: 'image/png' }))).toBe(false)
			expect(isSupportedSharedFile(new File(['a'], 'notes.txt', { type: 'text/plain' }))).toBe(false)
		})
	})

	describe('defaultTitleFromFilename', () => {
		it('strips pdf/docx extension and trims whitespace', () => {
			expect(defaultTitleFromFilename('  Moonlight Sonata.pdf ')).toBe('Moonlight Sonata')
			expect(defaultTitleFromFilename('Choir Notes.DOCX')).toBe('Choir Notes')
			expect(defaultTitleFromFilename('NoExtension')).toBe('NoExtension')
		})
	})

	describe('hasShareTargetParam and clearShareTargetParam', () => {
		it('detects share-target=1 in query string', () => {
			expect(hasShareTargetParam('?share-target=1')).toBe(true)
			expect(hasShareTargetParam('?foo=bar&share-target=1')).toBe(true)
			expect(hasShareTargetParam('?share-target=0')).toBe(false)
			expect(hasShareTargetParam('')).toBe(false)
		})

		it('removes share-target from window.location without losing hash', () => {
			window.history.replaceState({}, '', '/index.php/apps/sheetmusic/?share-target=1#/library/1/songs')
			expect(hasShareTargetParam()).toBe(true)

			clearShareTargetParam()
			expect(hasShareTargetParam()).toBe(false)
			expect(window.location.hash).toBe('#/library/1/songs')
		})
	})
})
