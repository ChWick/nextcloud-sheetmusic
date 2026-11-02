/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { ref } from 'vue'

export type DraggedSourceType =
	| { type: 'source-page'; pageNum: number }
	| { type: 'sequence-card'; fromIndex: number; pageNum: number }

export interface DropCallbacks {
	onInsert: (pageNum: number, targetIndex: number) => void
	onReorder: (fromIndex: number, toIndex: number) => void
}

export function usePageOrderDragDrop() {
	const draggedItem = ref<DraggedSourceType | null>(null)
	const dropTargetIndex = ref<number | null>(null)
	const dropIndicatorSide = ref<'left' | 'right' | null>(null)
	const isOverEndZone = ref(false)

	function handleSourceDragStart(event: DragEvent, pageNum: number) {
		const payload: DraggedSourceType = { type: 'source-page', pageNum }
		draggedItem.value = payload

		if (event.dataTransfer) {
			event.dataTransfer.effectAllowed = 'copy'
			event.dataTransfer.setData('application/json', JSON.stringify(payload))
			event.dataTransfer.setData('text/plain', `source:${pageNum}`)
		}
	}

	function handleSequenceDragStart(event: DragEvent, fromIndex: number, pageNum: number) {
		const payload: DraggedSourceType = { type: 'sequence-card', fromIndex, pageNum }
		draggedItem.value = payload

		if (event.dataTransfer) {
			event.dataTransfer.effectAllowed = 'move'
			event.dataTransfer.setData('application/json', JSON.stringify(payload))
			event.dataTransfer.setData('text/plain', `sequence:${fromIndex}`)
		}
	}

	function handleCardDragOver(event: DragEvent, index: number) {
		event.preventDefault()
		if (event.dataTransfer) {
			event.dataTransfer.dropEffect = draggedItem.value?.type === 'source-page' ? 'copy' : 'move'
		}

		isOverEndZone.value = false

		const target = event.currentTarget as HTMLElement | null
		if (!target) {
			dropTargetIndex.value = index
			dropIndicatorSide.value = 'left'
			return
		}

		const rect = target.getBoundingClientRect()
		let side: 'left' | 'right' = 'left'
		if (rect.width > 0) {
			const midX = rect.left + rect.width / 2
			side = event.clientX < midX ? 'left' : 'right'
		} else {
			// In test environments (happy-dom/jsdom) where layout returns width 0
			side = event.clientX < 100 ? 'left' : 'right'
		}

		dropTargetIndex.value = index
		dropIndicatorSide.value = side
	}

	function handleCardDragLeave(event: DragEvent, index: number) {
		// Only reset if leaving the currently active card target
		if (dropTargetIndex.value === index) {
			const related = event.relatedTarget as Node | null
			const current = event.currentTarget as Node | null
			if (!current || !related || !current.contains(related)) {
				dropTargetIndex.value = null
				dropIndicatorSide.value = null
			}
		}
	}

	function handleCardDrop(event: DragEvent, targetIndex: number, callbacks: DropCallbacks) {
		event.preventDefault()

		const item = resolveDraggedItem(event)
		if (!item) {
			resetDragState()
			return
		}

		const side = dropIndicatorSide.value ?? 'left'
		const desiredPos = side === 'left' ? targetIndex : targetIndex + 1

		if (item.type === 'source-page') {
			callbacks.onInsert(item.pageNum, desiredPos)
		} else if (item.type === 'sequence-card') {
			const from = item.fromIndex
			// Adjust destination index for removal of fromIndex
			const finalIndex = from < desiredPos ? desiredPos - 1 : desiredPos
			if (from !== finalIndex) {
				callbacks.onReorder(from, finalIndex)
			}
		}

		resetDragState()
	}

	function handleEndZoneDragOver(event: DragEvent) {
		event.preventDefault()
		if (event.dataTransfer) {
			event.dataTransfer.dropEffect = draggedItem.value?.type === 'source-page' ? 'copy' : 'move'
		}
		dropTargetIndex.value = null
		dropIndicatorSide.value = null
		isOverEndZone.value = true
	}

	function handleEndZoneDragLeave() {
		isOverEndZone.value = false
	}

	function handleEndZoneDrop(event: DragEvent, currentLength: number, callbacks: DropCallbacks) {
		event.preventDefault()

		const item = resolveDraggedItem(event)
		if (!item) {
			resetDragState()
			return
		}

		if (item.type === 'source-page') {
			callbacks.onInsert(item.pageNum, currentLength)
		} else if (item.type === 'sequence-card') {
			if (item.fromIndex !== currentLength - 1) {
				callbacks.onReorder(item.fromIndex, currentLength - 1)
			}
		}

		resetDragState()
	}

	function handleDragEnd() {
		resetDragState()
	}

	function resetDragState() {
		draggedItem.value = null
		dropTargetIndex.value = null
		dropIndicatorSide.value = null
		isOverEndZone.value = false
	}

	function resolveDraggedItem(event: DragEvent): DraggedSourceType | null {
		if (draggedItem.value) {
			return draggedItem.value
		}

		// Fallback to reading dataTransfer if ref was reset
		if (event.dataTransfer) {
			try {
				const json = event.dataTransfer.getData('application/json')
				if (json) {
					return JSON.parse(json) as DraggedSourceType
				}
			} catch {
				// Ignore JSON parse errors
			}
		}

		return null
	}

	return {
		draggedItem,
		dropTargetIndex,
		dropIndicatorSide,
		isOverEndZone,
		handleSourceDragStart,
		handleSequenceDragStart,
		handleCardDragOver,
		handleCardDragLeave,
		handleCardDrop,
		handleEndZoneDragOver,
		handleEndZoneDragLeave,
		handleEndZoneDrop,
		handleDragEnd,
		resetDragState,
	}
}
