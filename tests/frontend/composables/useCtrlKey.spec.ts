import { beforeEach, describe, expect, it } from 'vitest'
import { useCtrlKey } from '../../../src/composables/useCtrlKey'

describe('useCtrlKey composable', () => {
	let ctrlKeyComposable: ReturnType<typeof useCtrlKey>

	beforeEach(() => {
		ctrlKeyComposable = useCtrlKey()
	})

	it('initializes with isCtrlPressed as false', () => {
		expect(ctrlKeyComposable.isCtrlPressed.value).toBe(false)
	})

	it('detects Control key down and up', () => {
		const { isCtrlPressed, handleKeyDown, handleKeyUp } = ctrlKeyComposable

		handleKeyDown(new KeyboardEvent('keydown', { key: 'Control' }))
		expect(isCtrlPressed.value).toBe(true)

		handleKeyUp(new KeyboardEvent('keyup', { key: 'Control' }))
		expect(isCtrlPressed.value).toBe(false)
	})

	it('detects Meta (Command on macOS) key down and up', () => {
		const { isCtrlPressed, handleKeyDown, handleKeyUp } = ctrlKeyComposable

		handleKeyDown(new KeyboardEvent('keydown', { key: 'Meta' }))
		expect(isCtrlPressed.value).toBe(true)

		handleKeyUp(new KeyboardEvent('keyup', { key: 'Meta' }))
		expect(isCtrlPressed.value).toBe(false)
	})

	it('detects ctrlKey modifier flag on arbitrary key down and up', () => {
		const { isCtrlPressed, handleKeyDown, handleKeyUp } = ctrlKeyComposable

		handleKeyDown(new KeyboardEvent('keydown', { key: 'a', ctrlKey: true }))
		expect(isCtrlPressed.value).toBe(true)

		handleKeyUp(new KeyboardEvent('keyup', { key: 'a', ctrlKey: false }))
		expect(isCtrlPressed.value).toBe(false)
	})

	it('detects pointerdown with ctrlKey or metaKey modifier', () => {
		const { isCtrlPressed, handlePointerDown } = ctrlKeyComposable

		// Pointerdown with ctrlKey pressed
		handlePointerDown(new MouseEvent('pointerdown', { ctrlKey: true }))
		expect(isCtrlPressed.value).toBe(true)

		// Pointerdown without ctrlKey pressed
		handlePointerDown(new MouseEvent('pointerdown', { ctrlKey: false, metaKey: false }))
		expect(isCtrlPressed.value).toBe(false)

		// Pointerdown with metaKey pressed
		handlePointerDown(new MouseEvent('pointerdown', { metaKey: true }))
		expect(isCtrlPressed.value).toBe(true)
	})

	it('resets isCtrlPressed on window blur', () => {
		const { isCtrlPressed, handleKeyDown, handleWindowBlur } = ctrlKeyComposable

		handleKeyDown(new KeyboardEvent('keydown', { key: 'Control' }))
		expect(isCtrlPressed.value).toBe(true)

		handleWindowBlur()
		expect(isCtrlPressed.value).toBe(false)
	})
})
