/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export type PageOrderFilterMode = 'stuck' | 'all-1-1' | 'custom' | 'all'

export interface PageOrderAuditItem {
	id: number
	songId: number
	songTitle: string
	fileId: number
	fileName: string
	path: string
	pageOrder: string
	pageCount: number
	actualPageCount: number
	suggestedPageOrder: string
	isStuckOneToOne: boolean
	isCustom: boolean
}

export interface PageOrderAuditResult {
	totalFiles: number
	stuckCount: number
	customCount: number
	normalCount: number
	items: PageOrderAuditItem[]
}

export interface ToolDefinition {
	id: string
	name: string
	description: string
	icon?: any
}
