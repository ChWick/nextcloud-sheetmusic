/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ComputedRef } from 'vue'
import type { NormalizedPoint, SheetAnnotation } from '../types/annotation'
import type { EditorTool, EditorToolStyle } from './useAnnotationEditor'
import type { PendingLinkEdit, PendingTextEdit } from '../components/song-viewer/SongAnnotationInlinePopovers.vue'
import { ref } from 'vue'
import { roundNorm } from '../utils/annotationTransform'

export interface UseAnnotationInlineEditorsOptions {
	pageIndex: ComputedRef<number>
	pageNumber: ComputedRef<number>
	totalPages: ComputedRef<number>
	editable: ComputedRef<boolean>
	activeTool: ComputedRef<EditorTool>
	toolStyle: ComputedRef<EditorToolStyle>
	onCreate: (draft: Omit<SheetAnnotation, 'songId' | 'fileId' | 'uuid'>) => void
	onUpdate: (before: SheetAnnotation, after: SheetAnnotation) => void
	onDelete: (annotation: SheetAnnotation) => void
}

/**
 * Manages inline text and jump-link creation/edit popover state for SongAnnotationOverlay.
 */
export function useAnnotationInlineEditors(opts: UseAnnotationInlineEditorsOptions) {
	const pendingText = ref<PendingTextEdit | null>(null)
	const pendingLink = ref<PendingLinkEdit | null>(null)

	function openCreateText(start: NormalizedPoint, end: NormalizedPoint, moved: boolean) {
		pendingText.value = {
			mode: 'create',
			x: Math.min(0.85, Math.min(start.x, end.x)),
			y: Math.min(0.92, Math.min(start.y, end.y)),
			width: moved ? Math.max(0.15, Math.abs(end.x - start.x)) : 0.25,
			height: moved ? Math.max(0.04, Math.abs(end.y - start.y)) : 0.06,
			text: '',
		}
	}

	function openCreateLink(start: NormalizedPoint, end: NormalizedPoint, moved: boolean) {
		pendingLink.value = {
			x: Math.min(0.9, Math.min(start.x, end.x)),
			y: Math.min(0.94, Math.min(start.y, end.y)),
			width: moved ? Math.max(0.05, Math.abs(end.x - start.x)) : 0.1,
			height: moved ? Math.max(0.04, Math.abs(end.y - start.y)) : 0.06,
			targetPageOneIndexed: Math.min(opts.totalPages.value, opts.pageNumber.value + 1),
			label: '',
		}
	}

	function onAnnotationDblClick(ann: SheetAnnotation) {
		if (!opts.editable.value || opts.activeTool.value === 'eraser') return
		if (ann.type === 'text') {
			pendingText.value = {
				mode: 'edit',
				x: ann.x,
				y: ann.y,
				width: ann.width || 0.25,
				height: ann.height || 0.06,
				text: ann.data?.text ?? '',
				existing: ann,
			}
		} else if (ann.type === 'link') {
			pendingLink.value = {
				mode: 'edit',
				x: ann.x,
				y: ann.y,
				width: ann.width || 0.1,
				height: ann.height || 0.06,
				targetPageOneIndexed: (ann.data?.targetPage ?? 0) + 1,
				targetX: ann.data?.targetX,
				targetY: ann.data?.targetY,
				label: ann.data?.label ?? '',
				existing: ann,
			}
		}
	}

	function commitPendingText() {
		const pending = pendingText.value
		if (!pending) return
		pendingText.value = null
		const trimmed = pending.text.trim()
		if (!trimmed) {
			if (pending.mode === 'edit' && pending.existing) {
				opts.onDelete(pending.existing)
			}
			return
		}

		if (pending.mode === 'edit' && pending.existing) {
			opts.onUpdate(pending.existing, {
				...pending.existing,
				data: { ...pending.existing.data, text: trimmed },
			})
			return
		}

		const s = opts.toolStyle.value
		opts.onCreate({
			pageNumber: opts.pageIndex.value,
			layerIndex: 0,
			type: 'text',
			x: roundNorm(pending.x),
			y: roundNorm(pending.y),
			width: roundNorm(pending.width),
			height: roundNorm(pending.height),
			style: {
				color: s.color,
				fontSize: s.fontSize,
				fontFamily: s.fontFamily,
				textAlign: s.textAlign,
				opacity: s.opacity,
			},
			data: { text: trimmed },
		})
	}

	function cancelPendingText() {
		pendingText.value = null
	}

	function commitPendingLink() {
		const pending = pendingLink.value
		if (!pending) return
		pendingLink.value = null
		const targetZeroIndexed = Math.max(0, Math.min(opts.totalPages.value - 1, pending.targetPageOneIndexed - 1))
		const label = pending.label.trim() || `→ p. ${targetZeroIndexed + 1}`
		const targetCoords = pending.targetX !== undefined && pending.targetY !== undefined
			? { targetX: roundNorm(pending.targetX), targetY: roundNorm(pending.targetY) }
			: {}

		if (pending.mode === 'edit' && pending.existing) {
			opts.onUpdate(pending.existing, {
				...pending.existing,
				data: { ...pending.existing.data, targetPage: targetZeroIndexed, label, ...targetCoords },
			})
			return
		}

		opts.onCreate({
			pageNumber: opts.pageIndex.value,
			layerIndex: 0,
			type: 'link',
			x: roundNorm(pending.x),
			y: roundNorm(pending.y),
			width: roundNorm(pending.width),
			height: roundNorm(pending.height),
			style: {
				color: opts.toolStyle.value.color || '#0052cc',
				opacity: 1,
				fontSize: opts.toolStyle.value.fontSize,
			},
			data: { targetPage: targetZeroIndexed, label, ...targetCoords },
		})
	}

	function cancelPendingLink() {
		pendingLink.value = null
	}

	return {
		pendingText,
		pendingLink,
		openCreateText,
		openCreateLink,
		onAnnotationDblClick,
		commitPendingText,
		cancelPendingText,
		commitPendingLink,
		cancelPendingLink,
	}
}
