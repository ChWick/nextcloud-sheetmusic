import { computed, ref, type Ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showError, showInfo } from '@nextcloud/dialogs'
import type { PageCrop } from './crop-types'
import type { ScanOperation } from './scan-types'

export interface UsePdfPageEditsOptions {
	totalPages: Ref<number>
	songTitle: () => string
	onEnhanceThumbnailUpdate?: (pageNum: number) => void
}

export function usePdfPageEdits(options: UsePdfPageEditsOptions) {
	const { totalPages, songTitle, onEnhanceThumbnailUpdate } = options

	// pageRotations[pageIdx] = 0 | 90 | 180 | 270 (or arbitrary angle)
	const pageRotations = ref<number[]>([])
	const pageRotateCropToOriginal = ref<Record<number, boolean>>({})
	const pageCrops = ref<Record<number, PageCrop>>({})
	const pageEnhancements = ref<Record<number, ScanOperation[]>>({})
	const deletedPages = ref<Set<number>>(new Set())

	const isRotateModalOpen = ref(false)
	const rotateModalPage = ref(1)
	const isCropModalOpen = ref(false)
	const cropModalPage = ref(1)
	const isEnhanceModalOpen = ref(false)
	const enhanceModalPage = ref(1)
	const hasAnyJoin = ref(false)

	function resetEditsForPageCount(numPages: number) {
		pageRotations.value = new Array(numPages).fill(0)
		pageRotateCropToOriginal.value = {}
		pageCrops.value = {}
		pageEnhancements.value = {}
		deletedPages.value = new Set()
	}

	// Rotation helpers
	function rotatePage(pageIndex: number, deltaDeg: number) {
		const current = pageRotations.value[pageIndex] ?? 0
		const next = (current + deltaDeg) % 360
		pageRotations.value[pageIndex] = Math.round(((next + 360) % 360) * 10) / 10
	}

	function rotateAll(deltaDeg: number) {
		for (let i = 0; i < pageRotations.value.length; i++) {
			rotatePage(i, deltaDeg)
		}
	}

	function resetRotations() {
		for (let i = 0; i < pageRotations.value.length; i++) {
			pageRotations.value[i] = 0
		}
		pageRotateCropToOriginal.value = {}
		showInfo(t('sheetmusic', 'Reset all page rotations for "{title}".', { title: songTitle() }, { escape: false }))
	}

	function openRotateModal(pageNum: number) {
		rotateModalPage.value = pageNum
		isRotateModalOpen.value = true
	}

	function handleApplyCustomRotation(angle: number, scope: 'current' | 'all', cropToOriginalSize = false) {
		const normalized = Math.round((((angle % 360) + 360) % 360) * 10) / 10
		if (scope === 'all') {
			for (let i = 0; i < pageRotations.value.length; i++) {
				pageRotations.value[i] = normalized
				pageRotateCropToOriginal.value[i + 1] = cropToOriginalSize
			}
		} else {
			const idx = rotateModalPage.value - 1
			if (idx >= 0 && idx < pageRotations.value.length) {
				pageRotations.value[idx] = normalized
				pageRotateCropToOriginal.value[rotateModalPage.value] = cropToOriginalSize
			}
		}
	}

	const hasAnyRotation = computed(() => {
		return pageRotations.value.some((rot) => Math.abs(rot) > 0.001)
	})

	// Crop helpers
	function openCropModal(pageNum: number) {
		cropModalPage.value = pageNum
		isCropModalOpen.value = true
	}

	function handleApplyCrop(crop: PageCrop, scope: 'current' | 'all') {
		if (scope === 'all') {
			const updated: Record<number, PageCrop> = {}
			for (let p = 1; p <= totalPages.value; p++) {
				updated[p] = { ...crop }
			}
			pageCrops.value = updated
		} else {
			pageCrops.value = {
				...pageCrops.value,
				[cropModalPage.value]: { ...crop },
			}
		}
	}

	function resetAllCrops() {
		pageCrops.value = {}
		showInfo(t('sheetmusic', 'Reset all page crops for "{title}".', { title: songTitle() }, { escape: false }))
	}

	function isPageCropped(pageNum: number): boolean {
		const c = pageCrops.value[pageNum]
		if (!c) return false
		return (
			c.left > 0.005 ||
			c.top > 0.005 ||
			c.width < 0.995 ||
			c.height < 0.995
		)
	}

	const hasAnyCrop = computed(() => {
		return Object.values(pageCrops.value).some((c) => (
			c.left > 0.005 ||
			c.top > 0.005 ||
			c.width < 0.995 ||
			c.height < 0.995
		))
	})

	function getCropIndicatorText(pageNum: number): string {
		const c = pageCrops.value[pageNum]
		if (!c) return ''
		const top = Math.round(c.top * 100)
		const right = Math.round((1 - (c.left + c.width)) * 100)
		const bottom = Math.round((1 - (c.top + c.height)) * 100)
		const left = Math.round(c.left * 100)
		return `${top}% ${right}% ${bottom}% ${left}%`
	}

	// Page deletion helpers
	function isPageDeleted(pageNum: number): boolean {
		return deletedPages.value.has(pageNum)
	}

	function toggleDeletePage(pageNum: number) {
		const next = new Set(deletedPages.value)
		if (next.has(pageNum)) {
			next.delete(pageNum)
		} else {
			if (totalPages.value - next.size <= 1) {
				showError(t('sheetmusic', 'Cannot delete page. A PDF document must have at least one page.'))
				return
			}
			next.add(pageNum)
		}
		deletedPages.value = next
	}

	function restoreAllPages() {
		deletedPages.value = new Set()
	}

	const hasAnyDeletion = computed(() => deletedPages.value.size > 0)

	const remainingPagesCount = computed(() => Math.max(0, totalPages.value - deletedPages.value.size))

	// Enhance helpers
	function openEnhanceModal(pageNum: number) {
		enhanceModalPage.value = pageNum
		isEnhanceModalOpen.value = true
	}

	function handleApplyEnhancements(operations: ScanOperation[], scope: 'current' | 'all') {
		if (scope === 'all') {
			const updated: Record<number, ScanOperation[]> = {}
			for (let p = 1; p <= totalPages.value; p++) {
				updated[p] = operations.map((op) => ({ ...op }))
			}
			pageEnhancements.value = updated
			for (let p = 1; p <= totalPages.value; p++) {
				onEnhanceThumbnailUpdate?.(p)
			}
		} else {
			pageEnhancements.value = {
				...pageEnhancements.value,
				[enhanceModalPage.value]: operations.map((op) => ({ ...op })),
			}
			onEnhanceThumbnailUpdate?.(enhanceModalPage.value)
		}
	}

	function resetAllEnhancements() {
		pageEnhancements.value = {}
		for (let p = 1; p <= totalPages.value; p++) {
			onEnhanceThumbnailUpdate?.(p)
		}
		showInfo(t('sheetmusic', 'Reset all scan enhancements for "{title}".', { title: songTitle() }, { escape: false }))
	}

	const hasAnyEnhance = computed(() => {
		return Object.values(pageEnhancements.value).some((ops) =>
			Array.isArray(ops) && ops.some((op) => op.enabled),
		)
	})

	function getEnhanceIndicatorText(pageNum: number): string {
		const ops = pageEnhancements.value[pageNum]
		if (!ops || ops.length === 0) return ''
		const active = ops.filter((op) => op.enabled)
		if (active.length === 0) return ''
		return `${active.length} ${active.length === 1 ? 'filter' : 'filters'}`
	}

	const hasAnyChange = computed(() => {
		return hasAnyRotation.value || hasAnyCrop.value || hasAnyDeletion.value || hasAnyJoin.value || hasAnyEnhance.value
	})

	return {
		pageRotations,
		pageRotateCropToOriginal,
		pageCrops,
		pageEnhancements,
		deletedPages,
		isRotateModalOpen,
		rotateModalPage,
		isCropModalOpen,
		cropModalPage,
		isEnhanceModalOpen,
		enhanceModalPage,
		hasAnyJoin,
		hasAnyRotation,
		hasAnyCrop,
		hasAnyDeletion,
		remainingPagesCount,
		hasAnyEnhance,
		hasAnyChange,
		resetEditsForPageCount,
		rotatePage,
		rotateAll,
		resetRotations,
		openRotateModal,
		handleApplyCustomRotation,
		openCropModal,
		handleApplyCrop,
		resetAllCrops,
		isPageCropped,
		getCropIndicatorText,
		isPageDeleted,
		toggleDeletePage,
		restoreAllPages,
		openEnhanceModal,
		handleApplyEnhancements,
		resetAllEnhancements,
		getEnhanceIndicatorText,
	}
}
