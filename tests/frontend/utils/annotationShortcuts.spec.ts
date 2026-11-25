/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import {
	handleAnnotationEditorKeydown,
	type AnnotationShortcutActions,
} from '../../../src/utils/annotationShortcuts'

function createActions(hasSel = false): AnnotationShortcutActions {
	return {
		hasSelection: () => hasSel,
		undo: vi.fn(),
		redo: vi.fn(),
		deleteSelected: vi.fn(),
		clearSelection: vi.fn(),
		setTool: vi.fn(),
		nudgeSelected: vi.fn(),
		adjustSizeOrThickness: vi.fn(),
		selectStampSymbol: vi.fn(),
	}
}

function keyEvent(key: string, opts: KeyboardEventInit = {}, target?: EventTarget): KeyboardEvent {
	const ev = new KeyboardEvent('keydown', { key, cancelable: true, ...opts })
	if (target) {
		Object.defineProperty(ev, 'target', { value: target })
	}
	return ev
}

describe('handleAnnotationEditorKeydown', () => {
	it('ignores events originating inside form inputs', () => {
		const actions = createActions(true)
		const input = document.createElement('input')
		handleAnnotationEditorKeydown(keyEvent('Delete', {}, input), actions)
		handleAnnotationEditorKeydown(keyEvent('p', {}, input), actions)
		expect(actions.deleteSelected).not.toHaveBeenCalled()
		expect(actions.setTool).not.toHaveBeenCalled()
	})

	it('dispatches undo and redo with Ctrl/Meta modifiers', () => {
		const actions = createActions()
		handleAnnotationEditorKeydown(keyEvent('z', { ctrlKey: true }), actions)
		handleAnnotationEditorKeydown(keyEvent('z', { ctrlKey: true, shiftKey: true }), actions)
		handleAnnotationEditorKeydown(keyEvent('y', { metaKey: true }), actions)

		expect(actions.undo).toHaveBeenCalledTimes(1)
		expect(actions.redo).toHaveBeenCalledTimes(2)
	})

	it('deletes, clears selection, and nudges selected annotations', () => {
		const actions = createActions(true)
		handleAnnotationEditorKeydown(keyEvent('Backspace'), actions)
		expect(actions.deleteSelected).toHaveBeenCalledTimes(1)

		handleAnnotationEditorKeydown(keyEvent('Escape'), actions)
		expect(actions.clearSelection).toHaveBeenCalledTimes(1)

		handleAnnotationEditorKeydown(keyEvent('ArrowRight'), actions)
		handleAnnotationEditorKeydown(keyEvent('ArrowUp', { shiftKey: true }), actions)
		expect(actions.nudgeSelected).toHaveBeenNthCalledWith(1, 0.003, 0)
		expect(actions.nudgeSelected).toHaveBeenNthCalledWith(2, 0, -0.015)
	})

	it('switches to select tool on Escape when nothing is selected', () => {
		const actions = createActions(false)
		handleAnnotationEditorKeydown(keyEvent('Escape'), actions)
		expect(actions.setTool).toHaveBeenCalledWith('select')
	})

	it('adjusts size/thickness with +/- keys', () => {
		const actions = createActions()
		handleAnnotationEditorKeydown(keyEvent('+'), actions)
		handleAnnotationEditorKeydown(keyEvent('-'), actions)
		expect(actions.adjustSizeOrThickness).toHaveBeenNthCalledWith(1, 1)
		expect(actions.adjustSizeOrThickness).toHaveBeenNthCalledWith(2, -1)
	})

	it('maps tool keys and SMuFL stamp shortcuts accurately', () => {
		const actions = createActions()
		handleAnnotationEditorKeydown(keyEvent('w'), actions)
		handleAnnotationEditorKeydown(keyEvent('h'), actions)
		expect(actions.setTool).toHaveBeenNthCalledWith(1, 'handwriting')
		expect(actions.setTool).toHaveBeenNthCalledWith(2, 'highlight')

		handleAnnotationEditorKeydown(keyEvent('0'), actions)
		handleAnnotationEditorKeydown(keyEvent('5'), actions)
		handleAnnotationEditorKeydown(keyEvent('#'), actions)
		handleAnnotationEditorKeydown(keyEvent('P', { shiftKey: true }), actions)
		handleAnnotationEditorKeydown(keyEvent('f'), actions)
		handleAnnotationEditorKeydown(keyEvent('b'), actions)
		handleAnnotationEditorKeydown(keyEvent('n'), actions)

		expect(actions.selectStampSymbol).toHaveBeenNthCalledWith(1, '\uED10')
		expect(actions.selectStampSymbol).toHaveBeenNthCalledWith(2, '\uED15')
		expect(actions.selectStampSymbol).toHaveBeenNthCalledWith(3, '\uE262')
		expect(actions.selectStampSymbol).toHaveBeenNthCalledWith(4, '\uE520')
		expect(actions.selectStampSymbol).toHaveBeenNthCalledWith(5, '\uE522')
		expect(actions.selectStampSymbol).toHaveBeenNthCalledWith(6, '\uE260')
		expect(actions.selectStampSymbol).toHaveBeenNthCalledWith(7, '\uE261')
	})
})
