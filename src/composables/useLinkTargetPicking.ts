import type { ComputedRef, Ref } from 'vue'
import { ref, watch } from 'vue'
import type { SheetAnnotation } from '../types/annotation'
import type { EditorToolStyle } from './useAnnotationEditor'
import type { SideBySideLinkResult } from '../components/song-viewer/SongLinkPickerModal.vue'
import { roundNorm } from '../utils/annotationTransform'
import {
	buildLinkDrafts,
	resolveLinkLabel,
	type LinkAnnotationDraft,
	type LinkTargetPickRequest,
	type LinkTargetPickResult,
} from '../utils/annotationLinks'

export interface UseLinkTargetPickingOptions {
	isEditing: Ref<boolean>
	currentPage: ComputedRef<number>
	selectedAnnotation: ComputedRef<SheetAnnotation | null>
	toolStyle: Ref<EditorToolStyle>
	onCreate: (draft: LinkAnnotationDraft) => void
	onUpdate: (before: SheetAnnotation, after: SheetAnnotation) => void
}

/**
 * Manages 2-step on-page link target picking and the side-by-side Link Point Picker modal.
 */
export function useLinkTargetPicking(opts: UseLinkTargetPickingOptions) {
	const linkTargetPick = ref<LinkTargetPickRequest | null>(null)
	const linkPickerRequest = ref<LinkTargetPickRequest | null>(null)

	function startPickTarget(req: LinkTargetPickRequest) {
		linkTargetPick.value = req
	}

	function openLinkPicker(req: LinkTargetPickRequest) {
		linkTargetPick.value = null
		linkPickerRequest.value = req
	}

	function startPickForSelected() {
		const sel = opts.selectedAnnotation.value
		if (!sel || sel.type !== 'link') return
		linkTargetPick.value = {
			mode: 'edit',
			existing: sel,
		}
	}

	function openPickerForSelected() {
		const sel = opts.selectedAnnotation.value
		if (!sel || sel.type !== 'link') return
		linkTargetPick.value = null
		linkPickerRequest.value = {
			mode: 'edit',
			sourcePageIndex: sel.pageNumber,
			x: sel.x + (sel.width || 0.1) / 2,
			y: sel.y + (sel.height || 0.06) / 2,
			width: sel.width || 0.1,
			height: sel.height || 0.06,
			label: sel.data?.label ?? '',
			linkStyle: sel.data?.linkStyle === 'circle' ? 'circle' : 'box',
			existing: sel,
		}
	}

	function cancelPickTarget() {
		linkTargetPick.value = null
	}

	function closeLinkPicker() {
		linkPickerRequest.value = null
	}

	function completePickTarget(res: LinkTargetPickResult) {
		const req = linkTargetPick.value
		if (!req) return
		linkTargetPick.value = null

		const targetX = roundNorm(res.x)
		const targetY = roundNorm(res.y)

		if (req.mode === 'edit' && req.existing) {
			opts.onUpdate(req.existing, {
				...req.existing,
				data: {
					...req.existing.data,
					targetPage: res.pageIndex,
					targetX,
					targetY,
				},
			})
			return
		}

		const drafts = buildLinkDrafts({
			sourcePageIndex: req.sourcePageIndex ?? Math.max(0, opts.currentPage.value - 1),
			x: req.x ?? 0.1,
			y: req.y ?? 0.1,
			width: req.width ?? 0.1,
			height: req.height ?? 0.06,
			targetPageIndex: res.pageIndex,
			targetX,
			targetY,
			label: req.label,
			linkStyle: req.linkStyle,
			color: opts.toolStyle.value.color,
			fontSize: opts.toolStyle.value.fontSize,
		}, Boolean(req.createReverse))

		for (const draft of drafts) {
			opts.onCreate(draft)
		}
	}

	function saveLinkPicker(result: SideBySideLinkResult) {
		const req = linkPickerRequest.value
		linkPickerRequest.value = null
		if (!req) return

		const w = roundNorm(req.width ?? (result.linkStyle === 'circle' ? 0.06 : 0.1))
		const h = roundNorm(req.height ?? 0.06)
		const boxX = roundNorm(Math.min(Math.max(0, result.sourceX - w / 2), Math.max(0, 1 - w)))
		const boxY = roundNorm(Math.min(Math.max(0, result.sourceY - h / 2), Math.max(0, 1 - h)))
		const targetX = roundNorm(result.targetX)
		const targetY = roundNorm(result.targetY)

		if (req.mode === 'edit' && req.existing) {
			opts.onUpdate(req.existing, {
				...req.existing,
				pageNumber: result.sourcePageIndex,
				x: boxX,
				y: boxY,
				data: {
					...req.existing.data,
					targetPage: result.targetPageIndex,
					targetX,
					targetY,
					label: resolveLinkLabel(result.label, result.targetPageIndex, result.linkStyle),
					linkStyle: result.linkStyle,
				},
			})
			return
		}

		const drafts = buildLinkDrafts({
			sourcePageIndex: result.sourcePageIndex,
			x: boxX,
			y: boxY,
			width: w,
			height: h,
			targetPageIndex: result.targetPageIndex,
			targetX,
			targetY,
			label: result.label,
			linkStyle: result.linkStyle,
			color: opts.toolStyle.value.color,
			fontSize: opts.toolStyle.value.fontSize,
		}, result.createReverse)

		for (const draft of drafts) {
			opts.onCreate(draft)
		}
	}

	watch(opts.isEditing, (editing) => {
		if (!editing) {
			linkTargetPick.value = null
			linkPickerRequest.value = null
		}
	})

	return {
		linkTargetPick,
		linkPickerRequest,
		startPickTarget,
		openLinkPicker,
		startPickForSelected,
		openPickerForSelected,
		cancelPickTarget,
		closeLinkPicker,
		completePickTarget,
		saveLinkPicker,
	}
}
