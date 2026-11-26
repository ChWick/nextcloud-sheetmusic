import type { SheetAnnotation } from '../types/annotation'

export type LinkMarkerStyle = 'box' | 'circle'

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
	const linkStyle: LinkMarkerStyle = forward.data?.linkStyle === 'circle' ? 'circle' : 'box'
	const sourceCenterX = Math.min(1, Math.max(0, forward.x + w / 2))
	const sourceCenterY = Math.min(1, Math.max(0, forward.y + h / 2))

	return {
		pageNumber: targetPage,
		layerIndex: forward.layerIndex ?? 0,
		type: 'link',
		x: Math.round(revX * 10000) / 10000,
		y: Math.round(revY * 10000) / 10000,
		width: w,
		height: h,
		style: { ...forward.style },
		data: {
			targetPage: forward.pageNumber,
			targetX: Math.round(sourceCenterX * 10000) / 10000,
			targetY: Math.round(sourceCenterY * 10000) / 10000,
			label: linkStyle === 'circle' ? '' : `← p. ${forward.pageNumber + 1}`,
			linkStyle,
		},
	}
}
