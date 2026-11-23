/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Node } from '@nextcloud/files'

const {
	mockPickNodes,
	mockAllowDirectories,
	mockSetFilter,
	FakeFilePickerClosed,
} = vi.hoisted(() => ({
	mockPickNodes: vi.fn(),
	mockAllowDirectories: vi.fn(),
	mockSetFilter: vi.fn(),
	FakeFilePickerClosed: class FakeFilePickerClosed extends Error {},
}))

let capturedCanPick: ((node: Node) => boolean) | undefined
let capturedButtonFactory: ((nodes: Node[]) => Array<{ label: string; disabled: boolean }>) | undefined

vi.mock('@nextcloud/dialogs', () => ({
	FilePickerClosed: FakeFilePickerClosed,
	getFilePickerBuilder: vi.fn(() => {
		const builder = {
			setMultiSelect: vi.fn().mockReturnThis(),
			setCanPick: vi.fn((fn: (node: Node) => boolean) => {
				capturedCanPick = fn
				return builder
			}),
			setButtonFactory: vi.fn((fn: (nodes: Node[]) => Array<{ label: string; disabled: boolean }>) => {
				capturedButtonFactory = fn
				return builder
			}),
			allowDirectories: mockAllowDirectories.mockImplementation(() => builder),
			setFilter: mockSetFilter.mockImplementation(() => builder),
			build: vi.fn(() => ({ pickNodes: mockPickNodes })),
		}
		return builder
	}),
}))

import { pickSingleNextcloudNode } from '../../../src/utils/nextcloudFilePicker'

describe('pickSingleNextcloudNode', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		capturedCanPick = undefined
		capturedButtonFactory = undefined
	})

	it('builds picker, disables button for invalid nodes, and returns picked node', async () => {
		const fileNode = { type: 'file', basename: 'score.pdf', path: '/score.pdf' } as unknown as Node
		const folderNode = { type: 'folder', basename: 'Music', path: '/Music' } as unknown as Node
		mockPickNodes.mockResolvedValueOnce([fileNode])

		const picked = await pickSingleNextcloudNode({
			title: 'Pick file',
			canPick: (n) => n.type === 'file',
			selectLabel: (n) => `Select ${n.basename}`,
			emptyLabel: 'Select a file',
		})

		expect(picked).toBe(fileNode)
		expect(capturedCanPick?.(fileNode)).toBe(true)
		expect(capturedCanPick?.(folderNode)).toBe(false)
		expect(capturedButtonFactory?.([])[0]).toMatchObject({ label: 'Select a file', disabled: true })
		expect(capturedButtonFactory?.([folderNode])[0]).toMatchObject({ label: 'Select Music', disabled: true })
		expect(capturedButtonFactory?.([fileNode])[0]).toMatchObject({ label: 'Select score.pdf', disabled: false })
		expect(mockAllowDirectories).not.toHaveBeenCalled()
		expect(mockSetFilter).not.toHaveBeenCalled()
	})

	it('applies allowDirectories and filter when provided', async () => {
		const folderNode = { type: 'folder', basename: 'Scores', path: '/Scores' } as unknown as Node
		mockPickNodes.mockResolvedValueOnce([folderNode])
		const filter = (n: Node) => n.type === 'folder'

		const picked = await pickSingleNextcloudNode({
			title: 'Pick folder',
			canPick: filter,
			filter,
			allowDirectories: true,
			selectLabel: (n) => n.basename,
			emptyLabel: 'Select folder',
		})

		expect(picked).toBe(folderNode)
		expect(mockAllowDirectories).toHaveBeenCalledWith(true)
		expect(mockSetFilter).toHaveBeenCalledWith(filter)
	})

	it('returns null when user closes picker and rethrows unexpected errors', async () => {
		mockPickNodes.mockRejectedValueOnce(new FakeFilePickerClosed('closed'))
		const dismissed = await pickSingleNextcloudNode({
			title: 'Pick file',
			canPick: () => true,
			selectLabel: () => 'Select',
			emptyLabel: 'Select',
		})
		expect(dismissed).toBeNull()

		mockPickNodes.mockRejectedValueOnce(new Error('network down'))
		await expect(pickSingleNextcloudNode({
			title: 'Pick file',
			canPick: () => true,
			selectLabel: () => 'Select',
			emptyLabel: 'Select',
		})).rejects.toThrow('network down')
	})
})
