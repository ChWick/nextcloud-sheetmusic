/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import type { Node } from '@nextcloud/files'

export interface PickSingleNodeOptions {
	title: string
	canPick: (node: Node) => boolean
	filter?: (node: Node) => boolean
	allowDirectories?: boolean
	selectLabel: (node: Node) => string
	emptyLabel: string
}

/**
 * Opens Nextcloud's file picker for selecting a single node.
 * Returns the picked node, or `null` if the user dismissed the picker.
 * Re-throws unexpected errors so callers can display an error message.
 */
export async function pickSingleNextcloudNode(options: PickSingleNodeOptions): Promise<Node | null> {
	let builder = getFilePickerBuilder(options.title)
		.setMultiSelect(false)
		.setCanPick(options.canPick)
		.setButtonFactory(([node]) => [{
			label: node ? options.selectLabel(node) : options.emptyLabel,
			callback: () => {},
			disabled: !node || !options.canPick(node),
			variant: 'primary',
		}])

	if (options.allowDirectories) {
		builder = builder.allowDirectories(true)
	}
	if (options.filter) {
		builder = builder.setFilter(options.filter)
	}

	const picker = builder.build()

	try {
		const [picked] = await picker.pickNodes()
		return picked ?? null
	} catch (error) {
		if (error instanceof FilePickerClosed) {
			return null
		}
		throw error
	}
}
