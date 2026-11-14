import { ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import { apiPost } from '../api/client'
import type { SongPrefill } from '../types/song'

export interface UseSongImportOptions {
	onSongReady?: () => void
}

export function useSongImport(options: UseSongImportOptions = {}) {
	const importInput = ref<HTMLInputElement | null>(null)
	const importPrefill = ref<SongPrefill | null>(null)
	const importedFile = ref<File | null>(null)
	const importedNextcloudNode = ref<{ path: string; name: string } | null>(null)

	function clearImportState() {
		importPrefill.value = null
		importedFile.value = null
		importedNextcloudNode.value = null
	}

	function startImport() {
		importInput.value?.click()
	}

	async function startImportNextcloud() {
		const picker = getFilePickerBuilder(t('sheetmusic', 'Select a file from Nextcloud'))
			.setMultiSelect(false)
			.setCanPick((node) => node.type === 'file')
			.setButtonFactory(([node]) => [{
				label: node
					? t('sheetmusic', 'Import {file}', { file: node.basename }, { escape: false })
					: t('sheetmusic', 'Import a file'),
				callback: () => {},
				disabled: !node || node.type !== 'file',
				variant: 'primary',
			}])
			.build()

		try {
			const [picked] = await picker.pickNodes()
			if (!picked) return

			let prefill: SongPrefill | null = null
			try {
				const response = await apiPost('/api/import-metadata', { path: picked.path })
				prefill = response.data?.ocs?.data ?? null
			} catch {
				prefill = null
			}

			importPrefill.value = prefill
			importedFile.value = null
			importedNextcloudNode.value = { path: picked.path, name: picked.basename }
			options.onSongReady?.()
		} catch (error) {
			if (!(error instanceof FilePickerClosed)) {
				console.error('Failed to import file from Nextcloud:', error)
			}
		}
	}

	async function handleImportFile(event: Event) {
		const input = event.target as HTMLInputElement
		const file = input.files?.[0]
		input.value = ''
		if (!file) return

		let prefill: SongPrefill | null = null
		try {
			const form = new FormData()
			form.append('file', file)
			const response = await apiPost('/api/import-metadata', form as any)
			prefill = response.data?.ocs?.data ?? null
		} catch {
			prefill = null
		}

		importPrefill.value = prefill
		importedFile.value = file
		importedNextcloudNode.value = null
		options.onSongReady?.()
	}

	return {
		importInput,
		importPrefill,
		importedFile,
		importedNextcloudNode,
		clearImportState,
		startImport,
		startImportNextcloud,
		handleImportFile,
	}
}
