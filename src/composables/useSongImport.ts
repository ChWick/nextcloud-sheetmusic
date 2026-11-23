import { ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { apiPost } from '../api/client'
import type { SongPrefill } from '../types/song'
import { pickSingleNextcloudNode } from '../utils/nextcloudFilePicker'

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
		try {
			const picked = await pickSingleNextcloudNode({
				title: t('sheetmusic', 'Select a file from Nextcloud'),
				canPick: (node) => node.type === 'file',
				selectLabel: (node) => t('sheetmusic', 'Import {file}', { file: node.basename }, { escape: false }),
				emptyLabel: t('sheetmusic', 'Import a file'),
			})
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
			console.error('Failed to import file from Nextcloud:', error)
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
