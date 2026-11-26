/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import type { EditorTool, EditorToolStyle } from '../../../src/composables/useAnnotationEditor'
import type { SheetAnnotation } from '../../../src/types/annotation'
import { useAnnotationInlineEditors } from '../../../src/composables/useAnnotationInlineEditors'

const defaultStyle: EditorToolStyle = {
	color: '#1e88e5',
	highlightColor: '#ffeb3b',
	strokeWidth: 2.5,
	highlightWidth: 18,
	opacity: 0.9,
	highlightOpacity: 0.4,
	lineStyle: 'solid',
	fontSize: 24,
	fontFamily: 'sans-serif',
	textAlign: 'center',
	fill: 'transparent',
	shapeHeight: 18,
	stampSymbol: '𝆑',
	stampSize: 40,
}

function setup(tool: EditorTool = 'select') {
	const onCreate = vi.fn()
	const onUpdate = vi.fn()
	const onDelete = vi.fn()
	const api = useAnnotationInlineEditors({
		pageIndex: computed(() => 1),
		pageNumber: computed(() => 2),
		totalPages: computed(() => 5),
		editable: computed(() => true),
		activeTool: computed(() => tool),
		toolStyle: computed(() => defaultStyle),
		onCreate,
		onUpdate,
		onDelete,
	})
	return { api, onCreate, onUpdate, onDelete }
}

describe('useAnnotationInlineEditors', () => {
	it('creates a text annotation with current toolStyle and ignores empty text', () => {
		const { api, onCreate } = setup('text')
		api.openCreateText({ x: 0.2, y: 0.3 }, { x: 0.2, y: 0.3 }, false)
		expect(api.pendingText.value).toMatchObject({ mode: 'create', x: 0.2, y: 0.3, width: 0.25, height: 0.06 })

		api.pendingText.value!.text = '   '
		api.commitPendingText()
		expect(onCreate).not.toHaveBeenCalled()

		api.openCreateText({ x: 0.2, y: 0.3 }, { x: 0.5, y: 0.4 }, true)
		api.pendingText.value!.text = '  Dolce  '
		api.commitPendingText()
		expect(onCreate).toHaveBeenCalledWith(expect.objectContaining({
			pageNumber: 1,
			type: 'text',
			style: expect.objectContaining({ color: '#1e88e5', fontSize: 24, textAlign: 'center' }),
			data: { text: 'Dolce' },
		}))
	})

	it('edits or deletes an existing text annotation on double-click', () => {
		const { api, onUpdate, onDelete } = setup('select')
		const existing: SheetAnnotation = {
			uuid: 't1',
			songId: 1,
			fileId: 2,
			pageNumber: 1,
			type: 'text',
			x: 0.1,
			y: 0.2,
			width: 0.2,
			height: 0.05,
			style: { color: '#000', fontSize: 20 },
			data: { text: 'Allegro' },
		}

		api.onAnnotationDblClick(existing)
		expect(api.pendingText.value?.mode).toBe('edit')
		api.pendingText.value!.text = 'Presto'
		api.commitPendingText()
		expect(onUpdate).toHaveBeenCalledWith(existing, expect.objectContaining({ data: { text: 'Presto' } }))

		api.onAnnotationDblClick(existing)
		api.pendingText.value!.text = '  '
		api.commitPendingText()
		expect(onDelete).toHaveBeenCalledWith(existing)
	})

	it('creates and edits jump links with fontSize inheritance and fallback label', () => {
		const { api, onCreate, onUpdate } = setup('select')
		api.openCreateLink({ x: 0.1, y: 0.2 }, { x: 0.1, y: 0.2 }, false)
		expect(api.pendingLink.value?.targetPageOneIndexed).toBe(3)
		api.commitPendingLink()
		expect(onCreate).toHaveBeenCalledWith(expect.objectContaining({
			type: 'link',
			style: { color: '#1e88e5', opacity: 1, fontSize: 24 },
			data: { targetPage: 2, label: '→ p. 3' },
		}))

		const existingLink: SheetAnnotation = {
			uuid: 'l1',
			songId: 1,
			fileId: 2,
			pageNumber: 1,
			type: 'link',
			x: 0.1,
			y: 0.2,
			width: 0.1,
			height: 0.05,
			style: { opacity: 1, fontSize: 20 },
			data: { targetPage: 0, label: 'D.C.' },
		}
		api.onAnnotationDblClick(existingLink)
		api.pendingLink.value!.targetPageOneIndexed = 5
		api.pendingLink.value!.label = 'Coda'
		api.commitPendingLink()
		expect(onUpdate).toHaveBeenCalledWith(existingLink, expect.objectContaining({
			data: { targetPage: 4, label: 'Coda' },
		}))
	})
})
