/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export type AnnotationType = 'drawing' | 'highlight' | 'text' | 'shape' | 'stamp' | 'link'

export interface AnnotationStyle {
	stroke?: string
	strokeWidth?: number
	opacity?: number
	blendMode?: 'normal' | 'multiply' | string
	color?: string
	fill?: string
}

export interface AnnotationData {
	path?: string
	points?: Array<{ x: number; y: number }>
	text?: string
	fontSize?: number
	fontFamily?: string
	symbol?: string
	targetPage?: number
	targetX?: number
	targetY?: number
}

export interface SheetAnnotation {
	id?: number
	uuid: string
	songId: number
	fileId: number
	pageNumber: number // 0-indexed page number
	layerIndex?: number
	type: AnnotationType
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
