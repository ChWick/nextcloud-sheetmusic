import type { SheetAnnotation } from '../types/annotation'

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
