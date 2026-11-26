/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ComputedRef, Ref } from 'vue'
import { getCurrentInstance, h, ref, render, watch } from 'vue'
import type { SheetAnnotation } from '../types/annotation'
import type { Bookmark } from '../types/song'
import type { LinkArrivalBeacon, LinkJumpPayload, LinkTargetPickRequest, LinkTargetPickResult } from '../utils/annotationLinks'
import type { MusicFontId } from '../utils/musicFont'
import { isCropped, type NormalizedCropBox } from '../utils/pdfCrop'
import type { EditorTool, EditorToolStyle } from './useAnnotationEditor'
import SongAnnotationOverlay from '../components/song-viewer/SongAnnotationOverlay.vue'

export interface UseAnnotationOverlayHostOptions {
	pdfContainer: Ref<HTMLElement | null>
	totalPages: Ref<number>
	annotations: ComputedRef<SheetAnnotation[]>
	showAnnotations: ComputedRef<boolean | undefined>
	editableAnnotations: ComputedRef<boolean>
	activeAnnotationTool: ComputedRef<EditorTool>
	annotationToolStyle: ComputedRef<EditorToolStyle | undefined>
	selectedAnnotationUuid: ComputedRef<string | null>
	selectedAnnotationUuids?: ComputedRef<string[]>
	musicFont?: ComputedRef<MusicFontId | undefined>
	bookmarks?: ComputedRef<Bookmark[] | undefined>
	linkBeacon?: Ref<LinkArrivalBeacon | null>
	pickingLinkTarget?: ComputedRef<boolean>
	onJumpToPage: (payload: LinkJumpPayload) => void
	onStartPickLinkTarget?: (req: LinkTargetPickRequest) => void
	onCompletePickLinkTarget?: (res: LinkTargetPickResult) => void
	onOpenLinkPicker?: (req: LinkTargetPickRequest) => void
	onSelect: (uuid: string | null) => void
	onSelectMany?: (uuids: string[]) => void
	onCreate: (draft: Omit<SheetAnnotation, 'songId' | 'fileId' | 'uuid'> & { uuid?: string }) => void
	onUpdate: (before: SheetAnnotation, after: SheetAnnotation) => void
	onBatchUpdate?: (pairs: Array<{ before: SheetAnnotation; after: SheetAnnotation }>) => void
	onDelete: (ann: SheetAnnotation) => void
}

/**
 * Dynamically mounts, refreshes, and unmounts `SongAnnotationOverlay` instances
 * onto PDF page placeholders inside `FilePreviewer`.
 */
export function useAnnotationOverlayHost(opts: UseAnnotationOverlayHostOptions) {
	const appContext = getCurrentInstance()?.appContext ?? null
	const showAnnotationsState = ref(opts.showAnnotations.value ?? true)

	function toggleAnnotations() {
		showAnnotationsState.value = !showAnnotationsState.value
		refreshAnnotationOverlays()
	}

	function unmountAnnotationOverlay(placeholder: HTMLElement) {
		const mountEl = placeholder.querySelector<HTMLElement>('.sheetmusic-annotation-mount')
		if (mountEl) {
			render(null, mountEl)
		}
	}

	function unmountAnnotationOverlays() {
		if (!opts.pdfContainer.value) return
		const mounts = opts.pdfContainer.value.querySelectorAll<HTMLElement>('.sheetmusic-annotation-mount')
		mounts.forEach((el) => {
			render(null, el)
		})
	}

	function mountAnnotationOverlay(
		placeholder: HTMLElement,
		pageNum: number,
		pageWidth: number,
		pageHeight: number,
		cropBox?: NormalizedCropBox,
	) {
		let mountEl = placeholder.querySelector<HTMLElement>('.sheetmusic-annotation-mount')
		if (!mountEl) {
			mountEl = document.createElement('div')
			mountEl.className = 'sheetmusic-annotation-mount'
			mountEl.style.position = 'absolute'
			mountEl.style.pointerEvents = 'none'
			placeholder.appendChild(mountEl)
		}

		if (cropBox && isCropped(cropBox)) {
			const cropW = Math.max(0.05, cropBox.right - cropBox.left)
			const cropH = Math.max(0.05, cropBox.bottom - cropBox.top)
			placeholder.style.overflow = 'hidden'
			mountEl.style.inset = ''
			mountEl.style.width = `${100 / cropW}%`
			mountEl.style.height = `${100 / cropH}%`
			mountEl.style.left = `${-(cropBox.left / cropW) * 100}%`
			mountEl.style.top = `${-(cropBox.top / cropH) * 100}%`
		} else {
			placeholder.style.overflow = ''
			mountEl.style.width = ''
			mountEl.style.height = ''
			mountEl.style.left = ''
			mountEl.style.top = ''
			mountEl.style.inset = '0'
		}

		const vnode = h(SongAnnotationOverlay, {
			annotations: opts.annotations.value,
			pageNumber: pageNum,
			visible: showAnnotationsState.value || opts.editableAnnotations.value,
			pageWidth,
			pageHeight,
			totalPages: opts.totalPages.value,
			editable: opts.editableAnnotations.value,
			activeTool: opts.activeAnnotationTool.value,
			toolStyle: opts.annotationToolStyle.value,
			selectedUuid: opts.selectedAnnotationUuid.value,
			selectedUuids: opts.selectedAnnotationUuids?.value ?? [],
			musicFont: opts.musicFont?.value ?? 'bravura',
			bookmarks: opts.bookmarks?.value ?? [],
			linkBeacon: opts.linkBeacon?.value ?? null,
			pickingLinkTarget: opts.pickingLinkTarget?.value ?? false,
			onJumpToPage: (payload: LinkJumpPayload) => opts.onJumpToPage(payload),
			onStartPickLinkTarget: opts.onStartPickLinkTarget,
			onCompletePickLinkTarget: opts.onCompletePickLinkTarget,
			onOpenLinkPicker: opts.onOpenLinkPicker,
			onSelect: opts.onSelect,
			onSelectMany: opts.onSelectMany,
			onCreateAnnotation: opts.onCreate,
			onUpdateAnnotation: opts.onUpdate,
			onBatchUpdateAnnotations: opts.onBatchUpdate,
			onDeleteAnnotation: opts.onDelete,
		})
		if (appContext) {
			vnode.appContext = appContext
		}
		render(vnode, mountEl)
	}

	function refreshAnnotationOverlays() {
		if (!opts.pdfContainer.value) return
		const placeholders = opts.pdfContainer.value.querySelectorAll<HTMLElement>('[data-page-num]')
		placeholders.forEach((el) => {
			const pageNum = Number(el.dataset.pageNum)
			const canvas = el.querySelector('canvas')
			if (pageNum && canvas) {
				const pw = Number(el.dataset.pageWidth) || canvas.width || 595
				const ph = Number(el.dataset.pageHeight) || canvas.height || 842
				const cropBox: NormalizedCropBox | undefined = el.dataset.cropLeft !== undefined
					? {
						left: Number(el.dataset.cropLeft) || 0,
						top: Number(el.dataset.cropTop) || 0,
						right: Number(el.dataset.cropRight) || 1,
						bottom: Number(el.dataset.cropBottom) || 1,
					}
					: undefined
				mountAnnotationOverlay(el, pageNum, pw, ph, cropBox)
			}
		})
	}

	watch(
		() => [
			opts.annotations.value,
			showAnnotationsState.value,
			opts.editableAnnotations.value,
			opts.activeAnnotationTool.value,
			opts.annotationToolStyle.value,
			opts.selectedAnnotationUuid.value,
			opts.selectedAnnotationUuids?.value,
			opts.musicFont?.value,
			opts.bookmarks?.value,
			opts.linkBeacon?.value,
			opts.pickingLinkTarget?.value,
		],
		() => {
			refreshAnnotationOverlays()
		},
		{ deep: true },
	)

	watch(
		() => opts.showAnnotations.value,
		(val) => {
			showAnnotationsState.value = val ?? true
			refreshAnnotationOverlays()
		},
	)

	return {
		showAnnotationsState,
		toggleAnnotations,
		mountAnnotationOverlay,
		unmountAnnotationOverlay,
		unmountAnnotationOverlays,
		refreshAnnotationOverlays,
	}
}
