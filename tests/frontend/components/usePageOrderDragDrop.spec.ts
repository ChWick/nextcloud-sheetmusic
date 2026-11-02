/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { usePageOrderDragDrop } from '../../../src/components/song-pdf/usePageOrderDragDrop'

function createMockDragEvent(options: {
	clientX?: number
	targetRect?: { left: number; width: number }
	jsonData?: any
} = {}): DragEvent {
	const dataStore: Record<string, string> = {}
	if (options.jsonData) {
		dataStore['application/json'] = JSON.stringify(options.jsonData)
	}

	const dataTransfer = {
		effectAllowed: '',
		dropEffect: '',
		setData: vi.fn((type: string, val: string) => {
			dataStore[type] = val
		}),
		getData: vi.fn((type: string) => dataStore[type] || ''),
	}

	const event = {
		clientX: options.clientX ?? 100,
		preventDefault: vi.fn(),
		dataTransfer,
		currentTarget: {
			getBoundingClientRect: () => ({
				left: options.targetRect?.left ?? 0,
				width: options.targetRect?.width ?? 200,
			}),
			contains: () => false,
		},
		relatedTarget: null,
	} as unknown as DragEvent

	return event
}

describe('usePageOrderDragDrop.ts', () => {
	it('initializes with idle drag state', () => {
		const { draggedItem, dropTargetIndex, dropIndicatorSide, isOverEndZone } = usePageOrderDragDrop()
		expect(draggedItem.value).toBeNull()
		expect(dropTargetIndex.value).toBeNull()
		expect(dropIndicatorSide.value).toBeNull()
		expect(isOverEndZone.value).toBe(false)
	})

	it('records source-page drag start with copy effect', () => {
		const { draggedItem, handleSourceDragStart } = usePageOrderDragDrop()
		const event = createMockDragEvent()

		handleSourceDragStart(event, 3)

		expect(draggedItem.value).toEqual({ type: 'source-page', pageNum: 3 })
		expect(event.dataTransfer?.effectAllowed).toBe('copy')
		expect(event.dataTransfer?.setData).toHaveBeenCalledWith(
			'application/json',
			JSON.stringify({ type: 'source-page', pageNum: 3 }),
		)
	})

	it('records sequence-card drag start with move effect', () => {
		const { draggedItem, handleSequenceDragStart } = usePageOrderDragDrop()
		const event = createMockDragEvent()

		handleSequenceDragStart(event, 2, 4)

		expect(draggedItem.value).toEqual({ type: 'sequence-card', fromIndex: 2, pageNum: 4 })
		expect(event.dataTransfer?.effectAllowed).toBe('move')
		expect(event.dataTransfer?.setData).toHaveBeenCalledWith(
			'application/json',
			JSON.stringify({ type: 'sequence-card', fromIndex: 2, pageNum: 4 }),
		)
	})

	it('detects left and right side drop indicators on card dragover', () => {
		const { dropTargetIndex, dropIndicatorSide, handleCardDragOver } = usePageOrderDragDrop()

		// Card spanning x = 100 to 300 (midpoint = 200)
		const leftEvent = createMockDragEvent({
			clientX: 150,
			targetRect: { left: 100, width: 200 },
		})
		handleCardDragOver(leftEvent, 1)
		expect(dropTargetIndex.value).toBe(1)
		expect(dropIndicatorSide.value).toBe('left')

		const rightEvent = createMockDragEvent({
			clientX: 250,
			targetRect: { left: 100, width: 200 },
		})
		handleCardDragOver(rightEvent, 1)
		expect(dropTargetIndex.value).toBe(1)
		expect(dropIndicatorSide.value).toBe('right')
	})

	it('inserts source page at target index when dropped on card left side', () => {
		const { handleSourceDragStart, handleCardDragOver, handleCardDrop } = usePageOrderDragDrop()
		const onInsert = vi.fn()
		const onReorder = vi.fn()

		const dragStartEvent = createMockDragEvent()
		handleSourceDragStart(dragStartEvent, 5)

		const dragOverEvent = createMockDragEvent({
			clientX: 50,
			targetRect: { left: 0, width: 200 },
		})
		handleCardDragOver(dragOverEvent, 2)

		const dropEvent = createMockDragEvent()
		handleCardDrop(dropEvent, 2, { onInsert, onReorder })

		expect(onInsert).toHaveBeenCalledWith(5, 2)
		expect(onReorder).not.toHaveBeenCalled()
	})

	it('inserts source page after target index when dropped on card right side', () => {
		const { handleSourceDragStart, handleCardDragOver, handleCardDrop } = usePageOrderDragDrop()
		const onInsert = vi.fn()
		const onReorder = vi.fn()

		const dragStartEvent = createMockDragEvent()
		handleSourceDragStart(dragStartEvent, 5)

		const dragOverEvent = createMockDragEvent({
			clientX: 180,
			targetRect: { left: 0, width: 200 },
		})
		handleCardDragOver(dragOverEvent, 2)

		const dropEvent = createMockDragEvent()
		handleCardDrop(dropEvent, 2, { onInsert, onReorder })

		expect(onInsert).toHaveBeenCalledWith(5, 3)
		expect(onReorder).not.toHaveBeenCalled()
	})

	it('reorders sequence item correctly when dragged forward', () => {
		const { handleSequenceDragStart, handleCardDragOver, handleCardDrop } = usePageOrderDragDrop()
		const onInsert = vi.fn()
		const onReorder = vi.fn()

		// Drag index 0
		const dragStartEvent = createMockDragEvent()
		handleSequenceDragStart(dragStartEvent, 0, 1)

		// Hover over index 2 right side (desired pos 3, adjusting for removal of 0 -> final 2)
		const dragOverEvent = createMockDragEvent({
			clientX: 180,
			targetRect: { left: 0, width: 200 },
		})
		handleCardDragOver(dragOverEvent, 2)

		const dropEvent = createMockDragEvent()
		handleCardDrop(dropEvent, 2, { onInsert, onReorder })

		expect(onReorder).toHaveBeenCalledWith(0, 2)
		expect(onInsert).not.toHaveBeenCalled()
	})

	it('reorders sequence item correctly when dragged backward', () => {
		const { handleSequenceDragStart, handleCardDragOver, handleCardDrop } = usePageOrderDragDrop()
		const onInsert = vi.fn()
		const onReorder = vi.fn()

		// Drag index 3
		const dragStartEvent = createMockDragEvent()
		handleSequenceDragStart(dragStartEvent, 3, 4)

		// Hover over index 1 left side (desired pos 1)
		const dragOverEvent = createMockDragEvent({
			clientX: 20,
			targetRect: { left: 0, width: 200 },
		})
		handleCardDragOver(dragOverEvent, 1)

		const dropEvent = createMockDragEvent()
		handleCardDrop(dropEvent, 1, { onInsert, onReorder })

		expect(onReorder).toHaveBeenCalledWith(3, 1)
		expect(onInsert).not.toHaveBeenCalled()
	})

	it('handles end zone drop by appending source page or moving sequence card to end', () => {
		const composable1 = usePageOrderDragDrop()
		const onInsert = vi.fn()
		const onReorder = vi.fn()

		// Source page to end zone
		composable1.handleSourceDragStart(createMockDragEvent(), 2)
		composable1.handleEndZoneDragOver(createMockDragEvent())
		expect(composable1.isOverEndZone.value).toBe(true)
		composable1.handleEndZoneDrop(createMockDragEvent(), 4, { onInsert, onReorder })
		expect(onInsert).toHaveBeenCalledWith(2, 4)

		// Sequence card to end zone
		const composable2 = usePageOrderDragDrop()
		composable2.handleSequenceDragStart(createMockDragEvent(), 1, 3)
		composable2.handleEndZoneDrop(createMockDragEvent(), 4, { onInsert, onReorder })
		expect(onReorder).toHaveBeenCalledWith(1, 3)
	})

	it('resets drag state on handleDragEnd', () => {
		const { draggedItem, dropTargetIndex, handleSourceDragStart, handleDragEnd } = usePageOrderDragDrop()
		handleSourceDragStart(createMockDragEvent(), 1)
		expect(draggedItem.value).not.toBeNull()

		handleDragEnd()
		expect(draggedItem.value).toBeNull()
		expect(dropTargetIndex.value).toBeNull()
	})
})
