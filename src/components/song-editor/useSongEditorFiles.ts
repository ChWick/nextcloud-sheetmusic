import type { INode } from '@nextcloud/files'
import type { LibraryData, PendingAddition, PendingAttachment, PendingUpload, Song, SongFile } from '../../types/song'
import { FilePickerClosed, getFilePickerBuilder, showError, showInfo } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import { apiPost } from '../../api/client'
import { openFileExternally } from '../../utils/file'

export interface UseSongEditorFilesOptions {
	getSong: () => Song | null
	getLibraryId: () => number
	getTitle: () => string
	importedFile?: File | null
	importedNextcloudNode?: { path: string; name: string; action?: 'copy' | 'move' } | null
	onError: (msg: string) => void
	onSuccess: (msg: string) => void
}

function getServerFiles(song: Song | null): SongFile[] {
	return song?.files ? [...song.files] : []
}

function isStagedForDeletion(file: SongFile, pending: SongFile[]): boolean {
	return pending.some((candidate) => candidate.id === file.id)
}

function getInitialFiles(song: Song | null, pending: SongFile[] = []): SongFile[] {
	return getServerFiles(song).filter((file) => !isStagedForDeletion(file, pending))
}

export function useSongEditorFiles(options: UseSongEditorFilesOptions) {
	const currentFiles = ref<SongFile[]>(getInitialFiles(options.getSong()))
	const pendingDeleteFiles = ref<SongFile[]>([])
	const pendingDetachFiles = ref<SongFile[]>([])

	const isAttachDialogOpen = ref(false)
	const selectedNextcloudNode = ref<INode>()
	const attachAction = ref<'copy' | 'move'>('copy')

	const pendingAttachments = ref<PendingAttachment[]>([])
	const pendingUploads = ref<PendingUpload[]>([])
	let pendingAdditionKey = 0

	function removePendingAttachment(attachment: PendingAttachment) {
		pendingAttachments.value = pendingAttachments.value.filter((candidate) => candidate.key !== attachment.key)
	}

	function removePendingUpload(upload: PendingUpload) {
		pendingUploads.value = pendingUploads.value.filter((candidate) => candidate.key !== upload.key)
	}

	const pendingAdditions = computed<PendingAddition[]>(() => [
		...pendingAttachments.value.map((attachment): PendingAddition => ({
			key: attachment.key,
			name: attachment.name,
			hint: attachment.action === 'move'
				? t('sheetmusic', 'Move from Nextcloud')
				: t('sheetmusic', 'Copy from Nextcloud'),
			remove: () => removePendingAttachment(attachment),
		})),
		...pendingUploads.value.map((upload): PendingAddition => ({
			key: upload.key,
			name: upload.name,
			hint: t('sheetmusic', 'Upload from this computer'),
			remove: () => removePendingUpload(upload),
		})),
	])

	function stageAttachment(path: string, name: string, action: 'copy' | 'move') {
		pendingAttachments.value.push({ key: `attachment-${++pendingAdditionKey}`, path, name, action })
	}

	function stageUpload(file: File) {
		pendingUploads.value.push({ key: `upload-${++pendingAdditionKey}`, file, name: file.name })
	}

	if (options.importedFile) {
		stageUpload(options.importedFile)
	}
	if (options.importedNextcloudNode) {
		stageAttachment(
			options.importedNextcloudNode.path,
			options.importedNextcloudNode.name,
			options.importedNextcloudNode.action ?? 'copy',
		)
	}

	function syncFilesOnSongChange(newSong: Song | null | undefined, previousSong: Song | null | undefined) {
		const song = options.getSong()
		const sameSong = !!song && newSong?.id === previousSong?.id
		const pendingDel = sameSong
			? pendingDeleteFiles.value.map(
				(file) => newSong?.files?.find((candidate) => candidate.id === file.id) ?? file,
			)
			: []
		const pendingDet = sameSong
			? pendingDetachFiles.value.map(
				(file) => newSong?.files?.find((candidate) => candidate.id === file.id) ?? file,
			)
			: []

		if (!sameSong) {
			pendingAttachments.value = []
			pendingUploads.value = []
		}

		pendingDeleteFiles.value = pendingDel
		pendingDetachFiles.value = pendingDet
		currentFiles.value = getInitialFiles(song, [...pendingDel, ...pendingDet])
	}

	function updateFilePageOrder(updatedFile: SongFile) {
		currentFiles.value = currentFiles.value.map((f) => (f.id === updatedFile.id ? { ...f, pageOrder: updatedFile.pageOrder } : f))
	}

	function stageDeleteFile(file: SongFile) {
		currentFiles.value = currentFiles.value.filter((f) => f.id !== file.id)
		pendingDetachFiles.value = pendingDetachFiles.value.filter((f) => f.id !== file.id)
		if (!isStagedForDeletion(file, pendingDeleteFiles.value)) {
			pendingDeleteFiles.value.push(file)
		}
	}

	function stageDetachFile(file: SongFile) {
		currentFiles.value = currentFiles.value.filter((f) => f.id !== file.id)
		pendingDeleteFiles.value = pendingDeleteFiles.value.filter((f) => f.id !== file.id)
		if (!isStagedForDeletion(file, pendingDetachFiles.value)) {
			pendingDetachFiles.value.push(file)
		}
	}

	function openInExternalViewer(file: SongFile) {
		openFileExternally(file.path)
	}

	function restoreDeletedFile(file: SongFile) {
		pendingDeleteFiles.value = pendingDeleteFiles.value.filter((f) => f.id !== file.id)
		if (!currentFiles.value.some((f) => f.id === file.id)) {
			currentFiles.value.push(file)
		}
	}

	function restoreDetachedFile(file: SongFile) {
		pendingDetachFiles.value = pendingDetachFiles.value.filter((f) => f.id !== file.id)
		if (!currentFiles.value.some((f) => f.id === file.id)) {
			currentFiles.value.push(file)
		}
	}

	function notifyFileStaged() {
		const songTitle = options.getTitle()?.trim()
		const infoMsg = songTitle
			? t('sheetmusic', 'The file will be added to "{title}" when you save.', { title: songTitle }, { escape: false })
			: t('sheetmusic', 'The file is added to the song when you save.')
		options.onSuccess(infoMsg)
		showInfo(infoMsg)
	}

	function handleFileUpload(file: File) {
		options.onError('')
		stageUpload(file)
		notifyFileStaged()
	}

	async function openNextcloudPicker() {
		options.onError('')
		const picker = getFilePickerBuilder(t('sheetmusic', 'Select a file for this song'))
			.setMultiSelect(false)
			.setCanPick((node) => node.type === 'file')
			.setButtonFactory(([node]) => [{
				label: node
					? t('sheetmusic', 'Select {file}', { file: node.basename }, { escape: false })
					: t('sheetmusic', 'Select a file'),
				callback: () => {},
				disabled: !node || node.type !== 'file',
				variant: 'primary',
			}])
			.build()

		try {
			const [picked] = await picker.pickNodes()
			if (picked) {
				selectedNextcloudNode.value = picked
				attachAction.value = 'copy'
				isAttachDialogOpen.value = true
			}
		} catch (error) {
			if (!(error instanceof FilePickerClosed)) {
				const msg = t('sheetmusic', 'Could not select a file from Nextcloud.')
				options.onError(msg)
				showError(msg)
			}
		}
	}

	function stageAttachNextcloudFile() {
		const node = selectedNextcloudNode.value
		if (!node) {
			return
		}

		options.onError('')
		stageAttachment(node.path, node.basename, attachAction.value)
		isAttachDialogOpen.value = false
		selectedNextcloudNode.value = undefined
		notifyFileStaged()
	}

	function getPendingRemovalRequests(existingSong: Song | null): Array<() => Promise<unknown>> {
		if (!existingSong) {
			return []
		}
		const libraryId = options.getLibraryId()
		const requests: Array<() => Promise<unknown>> = []

		for (const file of pendingDeleteFiles.value.splice(0)) {
			if (file.id > 0) {
				requests.push(() => apiPost('/api/songs/{id}/file/{fileId}/delete', {
					libraryId,
				}, {
					pathParams: { id: existingSong.id, fileId: file.id },
				}))
			}
		}

		for (const file of pendingDetachFiles.value.splice(0)) {
			if (file.id > 0) {
				requests.push(() => apiPost('/api/songs/{id}/file/{fileId}/detach', {
					libraryId,
				}, {
					pathParams: { id: existingSong.id, fileId: file.id },
				}))
			}
		}

		return requests
	}

	function getPendingAdditionRequests(songId: number | undefined): Array<() => Promise<{ data: { ocs: { data: LibraryData } } }>> {
		const attachments = pendingAttachments.value.splice(0)
		const uploads = pendingUploads.value.splice(0)
		if (songId === undefined) {
			return []
		}

		const libraryId = options.getLibraryId()
		const requests: Array<() => Promise<{ data: { ocs: { data: LibraryData } } }>> = []

		for (const attachment of attachments) {
			requests.push(() => apiPost('/api/songs/{id}/file/attach', {
				libraryId,
				sourcePath: attachment.path,
				action: attachment.action,
			}, {
				pathParams: { id: songId },
			}))
		}

		for (const upload of uploads) {
			requests.push(() => {
				const formData = new FormData()
				formData.append('file', upload.file)
				return apiPost('/api/libraries/{libraryId}/songs/{id}/file/upload', formData as any, {
					pathParams: { libraryId, id: songId },
				})
			})
		}

		return requests
	}

	return {
		currentFiles,
		pendingDeleteFiles,
		pendingDetachFiles,
		pendingAdditions,
		isAttachDialogOpen,
		selectedNextcloudNode,
		attachAction,
		syncFilesOnSongChange,
		updateFilePageOrder,
		stageDeleteFile,
		stageDetachFile,
		openInExternalViewer,
		restoreDeletedFile,
		restoreDetachedFile,
		handleFileUpload,
		openNextcloudPicker,
		stageAttachNextcloudFile,
		getPendingRemovalRequests,
		getPendingAdditionRequests,
	}
}
