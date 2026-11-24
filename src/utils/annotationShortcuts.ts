/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { EditorTool } from '../composables/useAnnotationEditor'

const TOOL_SHORTCUTS: Record<string, EditorTool> = {
	v: 'select',
	p: 'drawing',
	h: 'highlight',
	s: 'line',
	t: 'text',
	m: 'stamp',
	w: 'handwriting',
	l: 'link',
	e: 'eraser',
}

const STAMP_SHORTCUTS: Record<string, string> = {
	f: '𝆑',
	b: '♭',
	n: '♮',
}

export interface AnnotationShortcutActions {
	hasSelection: () => boolean
	undo: () => void
	redo: () => void
	deleteSelected: () => void
	clearSelection: () => void
	setTool: (tool: EditorTool) => void
	nudgeSelected: (dx: number, dy: number) => void
	adjustSizeOrThickness: (deltaSign: 1 | -1) => void
	selectStampSymbol: (symbol: string) => void
}

function isEditableInputTarget(target: EventTarget | null): boolean {
	const el = target as HTMLElement | null
	return Boolean(
		el &&
		(el.tagName === 'INPUT' ||
			el.tagName === 'TEXTAREA' ||
			el.tagName === 'SELECT' ||
			el.isContentEditable),
	)
}

/**
 * Dispatches keyboard shortcuts while annotation edit mode is active.
 */
export function handleAnnotationEditorKeydown(
	e: KeyboardEvent,
	actions: AnnotationShortcutActions,
): void {
	if (isEditableInputTarget(e.target)) return

	// Undo / Redo
	if ((e.ctrlKey || e.metaKey) && !e.altKey) {
		const k = e.key.toLowerCase()
		if (k === 'z' && !e.shiftKey) {
			e.preventDefault()
			actions.undo()
		} else if ((k === 'z' && e.shiftKey) || k === 'y') {
			e.preventDefault()
			actions.redo()
		}
		return
	}

	if (e.altKey || e.ctrlKey || e.metaKey) return

	if (e.key === 'Delete' || e.key === 'Backspace') {
		if (actions.hasSelection()) {
			e.preventDefault()
			actions.deleteSelected()
		}
		return
	}

	if (e.key === 'Escape') {
		if (actions.hasSelection()) {
			actions.clearSelection()
		} else {
			actions.setTool('select')
		}
		return
	}

	if (
		actions.hasSelection() &&
		(e.key === 'ArrowUp' || e.key === 'ArrowDown' || e.key === 'ArrowLeft' || e.key === 'ArrowRight')
	) {
		e.preventDefault()
		const step = e.shiftKey ? 0.015 : 0.003
		const dx = e.key === 'ArrowLeft' ? -step : e.key === 'ArrowRight' ? step : 0
		const dy = e.key === 'ArrowUp' ? -step : e.key === 'ArrowDown' ? step : 0
		actions.nudgeSelected(dx, dy)
		return
	}

	if (e.key === '+' || e.key === '=') {
		e.preventDefault()
		actions.adjustSizeOrThickness(1)
		return
	}
	if (e.key === '-' || e.key === '_') {
		e.preventDefault()
		actions.adjustSizeOrThickness(-1)
		return
	}

	if (/^[0-5]$/.test(e.key) && !e.shiftKey) {
		e.preventDefault()
		actions.selectStampSymbol(e.key)
		return
	}

	if (e.key === '#' || (e.shiftKey && e.key === '3')) {
		e.preventDefault()
		actions.selectStampSymbol('♯')
		return
	}

	const lower = e.key.toLowerCase()
	if (e.shiftKey && lower === 'p') {
		e.preventDefault()
		actions.selectStampSymbol('𝆏')
		return
	}

	if (e.shiftKey) return

	if (TOOL_SHORTCUTS[lower]) {
		actions.setTool(TOOL_SHORTCUTS[lower])
	} else if (STAMP_SHORTCUTS[lower]) {
		actions.selectStampSymbol(STAMP_SHORTCUTS[lower])
	}
}
