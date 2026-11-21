/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export type AnnotationType = 'drawing' | 'highlight' | 'text' | 'shape' | 'stamp' | 'link'

export type ShapeType =
	| 'line'
	| 'arrow'
	| 'crescendo'
	| 'decrescendo'
	| 'rect'
	| 'ellipse'
	| 'slur'
	| 'bracket'

export type LineStyle = 'solid' | 'dashed' | 'dotted'
export type TextAlign = 'left' | 'center' | 'right'
export type StampCategory =
	| 'dynamic'
	| 'accidental'
	| 'articulation'
	| 'ornament'
	| 'navigation'
	| 'rhythm'
	| 'fingering'
export type StampFontKind = 'music' | 'fingering' | 'text'

export interface NormalizedPoint {
	x: number
	y: number
	/** Optional effective pressure [0..1] for variable-width strokes */
	p?: number
}

/**
 * Visual styling for a canonical annotation.
 * Sizes (strokeWidth, fontSize) are expressed in SVG per-mille of page width
 * (where 1000 = full page width), ensuring isotropic, zoom-independent rendering.
 */
export interface AnnotationStyle {
	stroke?: string
	strokeWidth?: number
	opacity?: number
	blendMode?: 'normal' | 'multiply'
	color?: string
	fill?: string
	backgroundColor?: string
	borderColor?: string
	lineStyle?: LineStyle
	fontSize?: number
	fontFamily?: string
	fontWeight?: 'normal' | 'bold'
	fontStyle?: 'normal' | 'italic'
	textAlign?: TextAlign
}

/**
 * Type-specific payload for a canonical annotation.
 * All point coordinates are normalized [0..1] relative to page width and height.
 */
export interface AnnotationData {
	/** Normalized [0..1] points for drawing, highlight, or 2-point shapes */
	points?: NormalizedPoint[]
	/** Optional multi-stroke normalized [0..1] points */
	strokes?: NormalizedPoint[][]
	/** Quadratic Bezier midpoint smoothing for freehand strokes (default true) */
	smooth?: boolean
	/** Shape variant when type === 'shape' */
	shapeType?: ShapeType
	/** Hairpin opening half-height in SVG per-mille units */
	aperture?: number
	/** Slur signed perpendicular curvature in SVG per-mille units */
	curvature?: number
	/** Rectangular highlight flag */
	rect?: boolean
	/** Text content when type === 'text' */
	text?: string
	/** Canonical Unicode symbol when type === 'stamp' */
	symbol?: string
	/** Semantic stamp category */
	category?: StampCategory
	/** Font family kind for stamps */
	fontKind?: StampFontKind
	/** 0-indexed target page when type === 'link' */
	targetPage?: number
	/** Optional display label for links */
	label?: string
	/** Optional pre-computed SVG path fallback */
	path?: string
}

export interface SheetAnnotation {
	id?: number
	uuid: string
	songId: number
	fileId: number
	/** 0-indexed page number */
	pageNumber: number
	layerIndex?: number
	type: AnnotationType
	/** Normalized [0..1] bounding box */
	x: number
	y: number
	width: number
	height: number
	style: AnnotationStyle
	data: AnnotationData
	createdAt?: number
	updatedAt?: number
	deletedAt?: number | null
}
