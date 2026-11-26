import type { SheetAnnotation } from '../types/annotation'
import { VIEWBOX_WIDTH } from './annotation'
import { roundNorm } from './annotationTransform'

export type LinkMarkerStyle = 'box' | 'circle'

export interface LinkCircleGeometry {
	cx: number
	cy: number
	r: number
}

export function getLinkStyle(item: Pick<SheetAnnotation, 'data'>): LinkMarkerStyle {
	return item.data?.linkStyle === 'circle' ? 'circle' : 'box'
}

export function getLinkCenter(item: Pick<SheetAnnotation, 'x' | 'y' | 'width' | 'height'>): { x: number; y: number } {
	const w = item.width || 0.1
	const h = item.height || 0.06
	return {
		x: Math.min(1, Math.max(0, item.x + w / 2)),
		y: Math.min(1, Math.max(0, item.y + h / 2)),
	}
}

/**
 * Compute exact SVG viewBox circle geometry for a circle-style link annotation.
 */
export function getLinkCircleGeometry(item: Pick<SheetAnnotation, 'x' | 'y' | 'width' | 'height'>, vbHeight: number): LinkCircleGeometry {
	return {
		cx: (item.x + item.width / 2) * VIEWBOX_WIDTH,
		cy: (item.y + item.height / 2) * vbHeight,
		r: Math.max(14, Math.min(item.width * VIEWBOX_WIDTH, item.height * vbHeight) / 2),
	}
}

export const LINK_LABEL_PRESETS = [
	'D.C.',
	'D.S.',
	'To Coda',
	'Coda',
	'Fine',
	'Repeat',
] as const

export interface LinkTargetPickRequest {
	mode: 'create' | 'edit'
	/** 0-indexed source page when creating a new link */
	sourcePageIndex?: number
	x?: number
	y?: number
	width?: number
	height?: number
	label?: string
	linkStyle?: LinkMarkerStyle
	createReverse?: boolean
	existing?: SheetAnnotation
}

export interface LinkTargetPickResult {
	/** 0-indexed destination page */
	pageIndex: number
	/** Normalized [0..1] X coordinate on destination page */
	x: number
	/** Normalized [0..1] Y coordinate on destination page */
	y: number
}

export interface LinkJumpPayload {
	/** 0-indexed destination page */
	targetPage: number
	/** 0-indexed origin page */
	sourcePage: number
	/** Optional normalized [0..1] arrival point on target page */
	targetX?: number
	targetY?: number
	/** Optional link label */
	label?: string
}

export interface LinkArrivalBeacon {
	/** 1-indexed page number where the beacon should appear */
	pageNumber: number
	/** Normalized [0..1] X position */
	x: number
	/** Normalized [0..1] Y position */
	y: number
	/** Unique token so consecutive jumps re-trigger animation */
	token: number
}

export interface LinkJumpBackEntry {
	/** 1-indexed page to return to */
	returnPage: number
	/** Optional label of the link that triggered the jump */
	fromLabel?: string
}

export interface ResolvedLinkColors {
	stroke: string
	fill: string
	text: string
}

const DEFAULT_LINK_COLOR = '#0052cc'
const HEX6_RE = /^#([0-9a-f]{6})$/i

export function clampNormalizedCoord(value: unknown): number | undefined {
	if (typeof value !== 'number' || !Number.isFinite(value)) return undefined
	return Math.min(1, Math.max(0, value))
}

export function resolveLinkColors(rawColor?: string | null): ResolvedLinkColors {
	const candidate = typeof rawColor === 'string' && HEX6_RE.test(rawColor.trim())
		? rawColor.trim()
		: DEFAULT_LINK_COLOR

	const hex = candidate.slice(1)
	const r = Number.parseInt(hex.slice(0, 2), 16)
	const g = Number.parseInt(hex.slice(2, 4), 16)
	const b = Number.parseInt(hex.slice(4, 6), 16)

	return {
		stroke: `rgba(${r}, ${g}, ${b}, 0.72)`,
		fill: `rgba(${r}, ${g}, ${b}, 0.16)`,
		text: candidate,
	}
}

export type LinkAnnotationDraft = Omit<SheetAnnotation, 'songId' | 'fileId' | 'uuid'>

export interface BuildLinkDraftInput {
	sourcePageIndex: number
	x: number
	y: number
	width: number
	height: number
	targetPageIndex: number
	targetX?: number
	targetY?: number
	label?: string
	linkStyle?: LinkMarkerStyle
	color?: string
	fontSize?: number
	layerIndex?: number
}

/**
 * Resolve a display label for a link annotation.
 * Box links default to "→ p. N" when empty; circle links remain unlabeled unless text is given.
 */
export function resolveLinkLabel(rawLabel: string | undefined, targetPageIndex: number, linkStyle: LinkMarkerStyle): string {
	const trimmed = rawLabel?.trim() ?? ''
	if (trimmed) return trimmed
	return linkStyle === 'circle' ? '' : `→ p. ${targetPageIndex + 1}`
}

/**
 * Build a normalized link annotation draft with consistent defaults for style, label, and coordinates.
 */
export function buildLinkAnnotationDraft(input: BuildLinkDraftInput): LinkAnnotationDraft {
	const linkStyle: LinkMarkerStyle = input.linkStyle === 'circle' ? 'circle' : 'box'
	const tx = clampNormalizedCoord(input.targetX)
	const ty = clampNormalizedCoord(input.targetY)

	return {
		pageNumber: Math.max(0, input.sourcePageIndex),
		layerIndex: input.layerIndex ?? 0,
		type: 'link',
		x: roundNorm(input.x),
		y: roundNorm(input.y),
		width: roundNorm(input.width),
		height: roundNorm(input.height),
		style: {
			color: input.color || DEFAULT_LINK_COLOR,
			opacity: 1,
			fontSize: input.fontSize,
		},
		data: {
			targetPage: Math.max(0, input.targetPageIndex),
			label: resolveLinkLabel(input.label, input.targetPageIndex, linkStyle),
			...(linkStyle === 'circle' ? { linkStyle } : {}),
			...(tx !== undefined && ty !== undefined ? { targetX: roundNorm(tx), targetY: roundNorm(ty) } : {}),
		},
	}
}

/**
 * Build a paired reverse link at the destination pointing back to the source link.
 * Returns null if source and target are on the same page.
 */
export function buildReverseLinkDraft(forward: LinkAnnotationDraft): LinkAnnotationDraft | null {
	const targetPage = forward.data?.targetPage
	if (typeof targetPage !== 'number' || targetPage === forward.pageNumber) {
		return null
	}
	const w = forward.width || 0.1
	const h = forward.height || 0.06
	const destCenterX = clampNormalizedCoord(forward.data?.targetX) ?? 0.5
	const destCenterY = clampNormalizedCoord(forward.data?.targetY) ?? 0.14
	const revX = Math.min(Math.max(0, destCenterX - w / 2), Math.max(0, 1 - w))
	const revY = Math.min(Math.max(0, destCenterY - h / 2), Math.max(0, 1 - h))
	const linkStyle = getLinkStyle(forward)
	const sourceCenter = getLinkCenter(forward)

	return {
		pageNumber: targetPage,
		layerIndex: forward.layerIndex ?? 0,
		type: 'link',
		x: roundNorm(revX),
		y: roundNorm(revY),
		width: w,
		height: h,
		style: { ...forward.style },
		data: {
			targetPage: forward.pageNumber,
			targetX: roundNorm(sourceCenter.x),
			targetY: roundNorm(sourceCenter.y),
			label: linkStyle === 'circle' ? '' : `← p. ${forward.pageNumber + 1}`,
			linkStyle,
		},
	}
}

/**
 * Build the forward link draft and, when requested and cross-page, its paired reverse link.
 */
export function buildLinkDrafts(input: BuildLinkDraftInput, createReverse: boolean): LinkAnnotationDraft[] {
	const forward = buildLinkAnnotationDraft(input)
	if (!createReverse) return [forward]
	const reverse = buildReverseLinkDraft(forward)
	return reverse ? [forward, reverse] : [forward]
}
