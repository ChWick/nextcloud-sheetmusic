import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { showError, showInfo } from '@nextcloud/dialogs'
import { usePdfPageEdits } from '../../../src/components/song-pdf/usePdfPageEdits'

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showInfo: vi.fn(),
}))

describe('usePdfPageEdits', () => {
	it('manages rotations, crops, deletions, and enhancements cleanly', () => {
		const totalPages = ref(3)
		const onEnhanceThumbnailUpdate = vi.fn()
		const edits = usePdfPageEdits({
			totalPages,
			songTitle: () => 'Test Sonata',
			onEnhanceThumbnailUpdate,
		})

		edits.resetEditsForPageCount(3)
		expect(edits.pageRotations.value).toEqual([0, 0, 0])
		expect(edits.hasAnyChange.value).toBe(false)

		// Rotations
		edits.rotatePage(0, 90)
		edits.rotateAll(-90)
		expect(edits.pageRotations.value).toEqual([0, 270, 270])
		expect(edits.hasAnyRotation.value).toBe(true)

		edits.openRotateModal(2)
		edits.handleApplyCustomRotation(15.5, 'current', true)
		expect(edits.pageRotations.value[1]).toBe(15.5)
		expect(edits.pageRotateCropToOriginal.value[2]).toBe(true)

		// Crops
		edits.openCropModal(1)
		edits.handleApplyCrop({ left: 0.1, top: 0.2, width: 0.7, height: 0.6 }, 'current')
		expect(edits.isPageCropped(1)).toBe(true)
		expect(edits.isPageCropped(2)).toBe(false)
		expect(edits.getCropIndicatorText(1)).toBe('20% 20% 20% 10%')
		expect(edits.hasAnyCrop.value).toBe(true)

		// Deletions (prevents deleting last remaining page)
		edits.toggleDeletePage(1)
		edits.toggleDeletePage(2)
		expect(edits.remainingPagesCount.value).toBe(1)
		edits.toggleDeletePage(3)
		expect(showError).toHaveBeenCalledTimes(1)
		expect(edits.isPageDeleted(3)).toBe(false)

		// Enhancements
		edits.openEnhanceModal(2)
		edits.handleApplyEnhancements([{ id: '1', type: 'contrast', enabled: true, params: { amount: 10 } } as any], 'all')
		expect(onEnhanceThumbnailUpdate).toHaveBeenCalledTimes(3)
		expect(edits.getEnhanceIndicatorText(1)).toBe('1 filter')
		expect(edits.hasAnyEnhance.value).toBe(true)

		// Resets
		edits.resetRotations()
		edits.resetAllCrops()
		edits.resetAllEnhancements()
		edits.restoreAllPages()
		expect(showInfo).toHaveBeenCalledTimes(3)
		expect(edits.hasAnyChange.value).toBe(false)
	})
})
