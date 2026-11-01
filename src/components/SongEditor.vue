<script setup lang="ts">
import type { INode } from '@nextcloud/files'
import type { Artist, Book, Collection, Composer, CreatedSongData, CustomGroup, Genre, Instrument, Key, LibraryData, PendingAddition, PendingAttachment, PendingUpload, Signature, Song, SongFile, SongPrefill, SourceType, Year } from '../types/song'

import axios from '@nextcloud/axios'
import { FilePickerClosed, getFilePickerBuilder, showError, showInfo, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { apiPost } from '../api/client'
import { describeRequestFailure } from '../utils/api'
import { openFileExternally } from '../utils/file'
import { createEntityTag, matchSelected, prefillByName, resolveSongEntityIds } from './song-editor/entity-utils'
import { computed, ref, watch } from 'vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import ContentSaveOutlineIcon from 'vue-material-design-icons/ContentSaveOutline.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import History from 'vue-material-design-icons/History.vue'
import AttachNextcloudFileDialog from './song-editor/AttachNextcloudFileDialog.vue'
import SongFileSection from './song-editor/SongFileSection.vue'
import SongMetadataFields from './song-editor/SongMetadataFields.vue'
import DeleteSongDialog from './DeleteSongDialog.vue'
import SongHistoryModal from './SongHistoryModal.vue'

const props = withDefaults(
	defineProps<{
		/** The song to edit. Null opens the editor for a new song. */
		song?: Song | null
		/** The source metadata when creating a copy; its attached files are deliberately excluded. */
		duplicateSong?: Song | null
		libraryId: number
		composers: Composer[]
		books: Book[]
		collections: Collection[]
		artists: Artist[]
		genres?: Genre[]
		keys?: Key[]
		customGroups?: CustomGroup[]
		years?: Year[]
		signatures?: Signature[]
		sourceTypes?: SourceType[]
		instruments?: Instrument[]
		/** Metadata read from an imported PDF, prefilled into a new song. */
		prefill?: SongPrefill | null
		/** The imported PDF itself, staged as the new song's file. */
		importedFile?: File | null
		/** An imported Nextcloud PDF file, staged as the new song's file attachment. */
		importedNextcloudNode?: { path: string, name: string, action?: 'copy' | 'move' } | null
		/** Hide the in-form buttons when the view provides persistent header actions. */
		hideActions?: boolean
	}>(),
	{
		song: null,
		duplicateSong: null,
		genres: () => [],
		keys: () => [],
		customGroups: () => [],
		years: () => [],
		signatures: () => [],
		sourceTypes: () => [],
		instruments: () => [],
		prefill: null,
		importedFile: null,
		importedNextcloudNode: null,
		hideActions: false,
	},
)

const emit = defineEmits<{
	/** The song was saved; the editor closes. The id of a created song is passed along. */
	saved: [data: LibraryData, songId?: number]
	/** The song's data changed and the editor stays open, e.g. after saving without closing. */
	updated: [data: LibraryData]
	/** The song was deleted. */
	deleted: [data: LibraryData | undefined, songId: number]
	cancel: []
}>()

/** A new song has no id yet: it is created in the library when the editor is saved. */
const isNewSong = computed(() => !props.song)

/** Label of the primary button, also used by the notices about staged file changes. */
const saveActionLabel = computed(() => (isNewSong.value
	? t('sheetmusic', 'Create song')
	: t('sheetmusic', 'Save and Close')))

const sourceSong = computed(() => props.song ?? props.duplicateSong)

const createComposerTag = createEntityTag(props.composers, (composer) => composer.name, (name) => ({ name }))
const createBookTag = createEntityTag(props.books, (book) => book.title, (title) => ({ title }))
const createCollectionTag = createEntityTag(props.collections, (collection) => collection.name, (name) => ({ name }))
const createArtistTag = createEntityTag(props.artists, (artist) => artist.name, (name) => ({ name }))
const createGenreTag = createEntityTag(props.genres, (genre) => genre.name, (name) => ({ name }))
const createKeyTag = createEntityTag(props.keys, (key) => key.name, (name) => ({ name }))
const createCustomGroupTag = createEntityTag(props.customGroups, (group) => group.name, (name) => ({ name }))
const createYearTag = createEntityTag(props.years, (year) => year.name, (name) => ({ name }))
const createSignatureTag = createEntityTag(props.signatures, (signature) => signature.name, (name) => ({ name }))
const createSourceTypeTag = createEntityTag(props.sourceTypes, (sourceType) => sourceType.name, (name) => ({ name }))
const createInstrumentTag = createEntityTag(props.instruments, (instrument) => instrument.name, (name) => ({ name }))

const title = ref(sourceSong.value?.title ?? props.prefill?.title ?? '')
const selectedComposers = ref<Array<Composer | { name: string, id?: number }>>(
	sourceSong.value
		? matchSelected(props.composers, sourceSong.value.composers)
		: prefillByName(props.prefill?.composers, props.composers, (composer) => composer.name, createComposerTag),
)
const selectedBooks = ref<Array<Book | { title: string, id?: number }>>(
	sourceSong.value
		? matchSelected(props.books, sourceSong.value.books)
		: prefillByName(props.prefill?.books, props.books, (book) => book.title, createBookTag),
)
const selectedCollections = ref<Array<Collection | { name: string, id?: number }>>(
	sourceSong.value
		? matchSelected(props.collections, sourceSong.value.collections)
		: prefillByName(props.prefill?.collections, props.collections, (collection) => collection.name, createCollectionTag),
)
const selectedArtists = ref<Array<Artist | { name: string, id?: number }>>(
	sourceSong.value
		? matchSelected(props.artists, sourceSong.value.artists)
		: prefillByName(props.prefill?.artists, props.artists, (artist) => artist.name, createArtistTag),
)
const selectedGenres = ref<Array<Genre | { name: string, id?: number }>>(
	sourceSong.value
		? matchSelected(props.genres, sourceSong.value.genres)
		: [],
)
const selectedKeys = ref<Array<Key | { name: string, id?: number }>>(
	sourceSong.value
		? matchSelected(props.keys, sourceSong.value.keys)
		: [],
)
const selectedCustomGroups = ref<Array<CustomGroup | { name: string, id?: number }>>(
	sourceSong.value
		? matchSelected(props.customGroups, sourceSong.value.customGroups)
		: [],
)
const selectedYears = ref<Array<Year | { name: string, id?: number }>>(
	sourceSong.value
		? matchSelected(props.years, sourceSong.value.years)
		: [],
)
const selectedSignatures = ref<Array<Signature | { name: string, id?: number }>>(
	sourceSong.value
		? matchSelected(props.signatures, sourceSong.value.signatures)
		: [],
)
const selectedSourceTypes = ref<Array<SourceType | { name: string, id?: number }>>(
	sourceSong.value
		? matchSelected(props.sourceTypes, sourceSong.value.sourceTypes)
		: [],
)
const selectedInstruments = ref<Array<Instrument | { name: string, id?: number }>>(
	sourceSong.value
		? matchSelected(props.instruments, sourceSong.value.instruments)
		: [],
)

const opus = ref<string>(sourceSong.value?.opus ?? '')
const catalogNumber = ref<string>(sourceSong.value?.catalogNumber ?? '')
const songNumber = ref<string>(sourceSong.value?.songNumber ?? '')
const stars = ref<number>(sourceSong.value?.stars ?? 0)
const difficulty = ref<number>(sourceSong.value?.difficulty ?? 0)
const duration = ref<number>(sourceSong.value?.duration ?? 0)
const keywords = ref<string>(sourceSong.value?.keywords ?? '')
const custom = ref<string>(sourceSong.value?.custom ?? '')
const custom2 = ref<string>(sourceSong.value?.custom2 ?? '')
const tempo = ref<number>(sourceSong.value?.tempos?.[0]?.tempo ?? 0)
const notes = ref<Array<{ id?: number, note: string, title?: string }>>(
	(sourceSong.value?.notes ?? []).map((n) => ({ note: n.note, title: n.title ?? '' })),
)
const bookmarks = ref<Array<{ id?: number, name: string, pageNum: number, endPage?: number | null }>>(
	(sourceSong.value?.bookmarks ?? []).map((b) => ({ name: b.name, pageNum: b.pageNum, endPage: b.endPage ?? null })),
)
const audioFiles = ref<Array<{ id?: number, title?: string, file: string }>>(
	(sourceSong.value?.audioFiles ?? []).map((a) => ({ title: a.title, file: a.file })),
)

function getServerFiles(song: Song | null): SongFile[] {
	return song?.files ? [...song.files] : []
}

/**
 * The database id is what a deletion targets and it survives a rename, while the path of a
 * file changes as soon as the song's files are renumbered.
 */
function isStagedForDeletion(file: SongFile, pending: SongFile[]): boolean {
	return pending.some((candidate) => candidate.id === file.id)
}

/** Files of a song the editor shows, i.e. without the ones staged for deletion. */
function getInitialFiles(song: Song | null, pending: SongFile[] = []): SongFile[] {
	return getServerFiles(song).filter((file) => !isStagedForDeletion(file, pending))
}

const currentFiles = ref<SongFile[]>(getInitialFiles(props.song))
const pendingDeleteFiles = ref<SongFile[]>([])
const pendingDetachFiles = ref<SongFile[]>([])
const isHistoryOpen = ref(false)

function handleReverted(data: LibraryData) {
	emit('updated', data)
}

watch(
	sourceSong,
	(newSong, previousSong) => {
		title.value = newSong?.title ?? ''
		selectedComposers.value = matchSelected(props.composers, newSong?.composers)
		selectedBooks.value = matchSelected(props.books, newSong?.books)
		selectedCollections.value = matchSelected(props.collections, newSong?.collections)
		selectedArtists.value = matchSelected(props.artists, newSong?.artists)
		selectedGenres.value = matchSelected(props.genres, newSong?.genres)
		selectedKeys.value = matchSelected(props.keys, newSong?.keys)
		selectedCustomGroups.value = matchSelected(props.customGroups, newSong?.customGroups)
		selectedYears.value = matchSelected(props.years, newSong?.years)
		selectedSignatures.value = matchSelected(props.signatures, newSong?.signatures)
		selectedSourceTypes.value = matchSelected(props.sourceTypes, newSong?.sourceTypes)
		selectedInstruments.value = matchSelected(props.instruments, newSong?.instruments)
		opus.value = newSong?.opus ?? ''
		catalogNumber.value = newSong?.catalogNumber ?? ''
		songNumber.value = newSong?.songNumber ?? ''
		stars.value = newSong?.stars ?? 0
		difficulty.value = newSong?.difficulty ?? 0
		duration.value = newSong?.duration ?? 0
		keywords.value = newSong?.keywords ?? ''
		custom.value = newSong?.custom ?? ''
		custom2.value = newSong?.custom2 ?? ''
		tempo.value = newSong?.tempos?.[0]?.tempo ?? 0
		notes.value = (newSong?.notes ?? []).map((n) => ({
			...(props.song ? { id: n.id } : {}),
			note: n.note,
			title: n.title ?? '',
		}))
		bookmarks.value = (newSong?.bookmarks ?? []).map((b) => ({
			...(props.song ? { id: b.id } : {}),
			name: b.name,
			pageNum: b.pageNum,
			endPage: b.endPage ?? null,
		}))
		audioFiles.value = (newSong?.audioFiles ?? []).map((a) => ({
			...(props.song ? { id: a.id } : {}),
			title: a.title,
			file: a.file,
		}))

		// A refresh in place keeps the deletions and detachments staged in this session.
		const sameSong = !!props.song && newSong?.id === previousSong?.id
		const pendingDel = sameSong
			? pendingDeleteFiles.value.map(
				(file) => newSong.files?.find((candidate) => candidate.id === file.id) ?? file,
			)
			: []
		const pendingDet = sameSong
			? pendingDetachFiles.value.map(
				(file) => newSong.files?.find((candidate) => candidate.id === file.id) ?? file,
			)
			: []

		if (!sameSong) {
			pendingAttachments.value = []
			pendingUploads.value = []
		}

		pendingDeleteFiles.value = pendingDel
		pendingDetachFiles.value = pendingDet
		const allStaged = [...pendingDel, ...pendingDet]
		currentFiles.value = getInitialFiles(props.song, allStaged)
	},
)

const isSaving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const fileSection = ref<{ openUploadPicker: () => void }>()

// Nextcloud file attach dialog state
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

/**
 * Files that are added to the song when it is saved. Attaching and uploading only stage
 * here: moving the file in the library folder and the database update happen on save, so
 * cancelling leaves the library untouched.
 */
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

// An imported PDF is the new song's file right away; it is uploaded on save like
// any other staged addition.
if (props.importedFile) {
	stageUpload(props.importedFile)
}
if (props.importedNextcloudNode) {
	stageAttachment(props.importedNextcloudNode.path, props.importedNextcloudNode.name, props.importedNextcloudNode.action ?? 'copy')
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

/**
 * Open an attached file in an external viewer: the file's URL goes to a new browser
 * tab, where the PDF is shown by the browser's own viewer or handed to the system's
 * PDF application.
 */
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

/**
 * Choosing a file on the computer only stages it. The upload - including the move into
 * the library folder - runs when the song is saved, just like a staged deletion.
 */
function handleFileUpload(file: File) {
	errorMessage.value = ''
	stageUpload(file)
	const songTitle = title.value?.trim()
	const infoMsg = songTitle
		? t('sheetmusic', 'The file will be added to "{title}" when you save.', { title: songTitle })
		: t('sheetmusic', 'The file is added to the song when you save.')
	successMessage.value = infoMsg
	showInfo(infoMsg)
}

async function openNextcloudPicker() {
	errorMessage.value = ''
	const picker = getFilePickerBuilder(t('sheetmusic', 'Select a file for this song'))
		.setMultiSelect(false)
		.setCanPick((node) => node.type === 'file')
		.setButtonFactory(([node]) => [{
			label: node
				? t('sheetmusic', 'Select {file}', { file: node.basename })
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
			errorMessage.value = t('sheetmusic', 'Could not select a file from Nextcloud.')
			showError(errorMessage.value)
		}
	}
}

/**
 * Attaching a Nextcloud file only stages it. The copy or move inside the library folder
 * and the database entry are done when the song is saved, so cancelling changes nothing.
 */
function stageAttachNextcloudFile() {
	const node = selectedNextcloudNode.value
	if (!node) {
		return
	}

	errorMessage.value = ''
	stageAttachment(node.path, node.basename, attachAction.value)
	isAttachDialogOpen.value = false
	selectedNextcloudNode.value = undefined
	const songTitle = title.value?.trim()
	const infoMsg = songTitle
		? t('sheetmusic', 'The file will be added to "{title}" when you save.', { title: songTitle })
		: t('sheetmusic', 'The file is added to the song when you save.')
	successMessage.value = infoMsg
	showInfo(infoMsg)
}

function openUploadPicker() {
	fileSection.value?.openUploadPicker()
}

async function saveSong(closeAfter: boolean) {
	if (isSaving.value) {
		return
	}

	if (!title.value.trim()) {
		errorMessage.value = t('sheetmusic', 'Song title cannot be empty.')
		showError(errorMessage.value)
		return
	}

	errorMessage.value = ''
	isSaving.value = true

	// Snapshot: props.song is null while a new song is being created.
	const existingSong = props.song
	const detachedFileCount = existingSong ? pendingDetachFiles.value.length : 0

	try {
		// Process pending file deletions - a new song has none, it has no id yet.
		if (existingSong && pendingDeleteFiles.value.length > 0) {
			for (const file of pendingDeleteFiles.value) {
				if (file.id > 0) {
					await apiPost('/api/songs/{id}/file/{fileId}/delete', {
						libraryId: props.libraryId,
					}, {
						pathParams: { id: existingSong.id, fileId: file.id },
					})
				}
			}
			pendingDeleteFiles.value = []
		}

		// Process pending file detachments
		if (existingSong && pendingDetachFiles.value.length > 0) {
			for (const file of pendingDetachFiles.value) {
				if (file.id > 0) {
					await apiPost('/api/songs/{id}/file/{fileId}/detach', {
						libraryId: props.libraryId,
					}, {
						pathParams: { id: existingSong.id, fileId: file.id },
					})
				}
			}
			pendingDetachFiles.value = []
		}

		const entityIds = await resolveSongEntityIds([
			{ idField: 'composerIds', endpoint: '/api/composers', labelField: 'name', selected: selectedComposers.value, options: props.composers },
			{ idField: 'bookIds', endpoint: '/api/books', labelField: 'title', selected: selectedBooks.value, options: props.books },
			{ idField: 'collectionIds', endpoint: '/api/collections', labelField: 'name', selected: selectedCollections.value, options: props.collections },
			{ idField: 'artistIds', endpoint: '/api/artists', labelField: 'name', selected: selectedArtists.value, options: props.artists },
			{ idField: 'genreIds', endpoint: '/api/genres', labelField: 'name', selected: selectedGenres.value, options: props.genres },
			{ idField: 'keyIds', endpoint: '/api/keys', labelField: 'name', selected: selectedKeys.value, options: props.keys },
			{ idField: 'customGroupIds', endpoint: '/api/customgroups', labelField: 'name', selected: selectedCustomGroups.value, options: props.customGroups },
			{ idField: 'yearIds', endpoint: '/api/years', labelField: 'name', selected: selectedYears.value, options: props.years },
			{ idField: 'signatureIds', endpoint: '/api/signatures', labelField: 'name', selected: selectedSignatures.value, options: props.signatures },
			{ idField: 'sourceTypeIds', endpoint: '/api/sourcetypes', labelField: 'name', selected: selectedSourceTypes.value, options: props.sourceTypes },
			{ idField: 'instrumentIds', endpoint: '/api/instruments', labelField: 'name', selected: selectedInstruments.value, options: props.instruments },
		], async (endpoint, labelField, label) => {
			const response = await apiPost(endpoint, {
				libraryId: props.libraryId,
				[labelField]: label,
			})
			return response.data.ocs.data.id
		})

		// The song is created or updated before the staged file additions: those need the
		// song's id, and the naming rules use the composers, artists and collections the
		// user just saved.
		const payload = {
			libraryId: props.libraryId,
			title: title.value.trim(),
			...entityIds,
			opus: opus.value.trim(),
			catalogNumber: catalogNumber.value.trim(),
			songNumber: songNumber.value.trim(),
			stars: Number(stars.value) || 0,
			difficulty: Number(difficulty.value) || 0,
			duration: Number(duration.value) || 0,
			keywords: keywords.value.trim(),
			custom: custom.value.trim(),
			custom2: custom2.value.trim(),
			notes: notes.value.filter((n) => n.note.trim() !== '' || (n.title && n.title.trim() !== '')),
			tempos: Number(tempo.value) > 0 ? [Number(tempo.value)] : [],
			bookmarks: bookmarks.value.filter((b) => b.name.trim() !== ''),
			audioFiles: audioFiles.value.filter((a) => a.file.trim() !== ''),
		}
		const response = existingSong
			? await apiPost('/api/songs/{id}', payload, { pathParams: { id: existingSong.id } })
			: await apiPost('/api/songs', payload)

		let data = response.data.ocs.data as LibraryData
		// A new song only has an id once the server created it.
		const songId: number | undefined = existingSong?.id ?? (data as CreatedSongData).createdSongId

		// Staged file additions are applied to the song that now exists. Each addition
		// renumbers the song's files, so the data of the last one is what the parent gets.
		if (songId !== undefined) {
			for (const attachment of pendingAttachments.value) {
				const attachResponse = await apiPost('/api/songs/{id}/file/attach', {
					libraryId: props.libraryId,
					sourcePath: attachment.path,
					action: attachment.action,
				}, {
					pathParams: { id: songId },
				})
				data = attachResponse.data.ocs.data as LibraryData
			}

			for (const upload of pendingUploads.value) {
				const formData = new FormData()
				formData.append('file', upload.file)

				const uploadResponse = await apiPost('/api/libraries/{libraryId}/songs/{id}/file/upload', formData as any, {
					pathParams: { libraryId: props.libraryId, id: songId },
				})
				data = uploadResponse.data.ocs.data as LibraryData
			}
		}

		pendingAttachments.value = []
		pendingUploads.value = []

		const songTitle = title.value?.trim()
		if (detachedFileCount > 0) {
			successMessage.value = songTitle
				? t('sheetmusic', 'Song "{title}" updated successfully. {count} file(s) detached and kept in storage.', { title: songTitle, count: detachedFileCount })
				: t('sheetmusic', 'Song updated successfully. {count} file(s) detached and kept in storage.', { count: detachedFileCount })
			showSuccess(successMessage.value)
		} else {
			successMessage.value = isNewSong.value
				? songTitle
					? t('sheetmusic', 'Song "{title}" created successfully.', { title: songTitle })
					: t('sheetmusic', 'Song created successfully.')
				: songTitle
					? t('sheetmusic', 'Song "{title}" updated successfully.', { title: songTitle })
					: t('sheetmusic', 'Song updated successfully.')
			showSuccess(successMessage.value)
		}
		if (closeAfter) {
			emit('saved', data, songId)
		} else {
			// The editor stays open: the refreshed data flows back in and the editor
			// updates in place, so the file list shows the saved files and the form
			// holds what was just written to the song.
			emit('updated', data)
		}
	} catch (error) {
		const fallback = isNewSong.value
			? t('sheetmusic', 'Failed to create the song.')
			: t('sheetmusic', 'Failed to save song changes.')
		errorMessage.value = describeRequestFailure(error, fallback)
		showError(errorMessage.value)
	} finally {
		isSaving.value = false
	}
}

const isDeleteDialogOpen = ref(false)

function handleSongDeleted(data: LibraryData | undefined, songId: number) {
	emit('deleted', data, songId)
}

defineExpose({
	saveSong,
	promptDelete: () => { isDeleteDialogOpen.value = true },
	openUploadPicker,
	openNextcloudPicker,
})
</script>

<template>
	<div :class="$style.editor">
		<form :class="$style.form" @submit.prevent="saveSong(true)">
			<div :class="$style.header">
				<PencilIcon v-if="!isNewSong" :size="20" :class="$style.editIndicator" />
				<div :class="$style.titleInputWrapper">
					<NcTextField
						id="song-title-input"
						v-model="title"
						:placeholder="isNewSong ? t('sheetmusic', 'New Song') : t('sheetmusic', 'Enter song title')"
						:label="t('sheetmusic', 'Song Title')"
						required />
				</div>
				<NcButton
					v-if="!isNewSong && song"
					type="button"
					variant="tertiary"
					:title="t('sheetmusic', 'Song history & revisions')"
					:aria-label="t('sheetmusic', 'Song history & revisions')"
					@click="isHistoryOpen = true">
					<template #icon>
						<History :size="20" />
					</template>
				</NcButton>
			</div>

			<SongMetadataFields
				v-model:selected-composers="selectedComposers"
				v-model:selected-books="selectedBooks"
				v-model:selected-collections="selectedCollections"
				v-model:selected-artists="selectedArtists"
				v-model:selected-genres="selectedGenres"
				v-model:selected-keys="selectedKeys"
				v-model:selected-custom-groups="selectedCustomGroups"
				v-model:selected-years="selectedYears"
				v-model:selected-signatures="selectedSignatures"
				v-model:selected-source-types="selectedSourceTypes"
				v-model:selected-instruments="selectedInstruments"
				v-model:opus="opus"
				v-model:catalog-number="catalogNumber"
				v-model:song-number="songNumber"
				v-model:stars="stars"
				v-model:difficulty="difficulty"
				v-model:duration="duration"
				v-model:keywords="keywords"
				v-model:custom="custom"
				v-model:custom2="custom2"
				v-model:tempo="tempo"
				:composers="composers"
				:books="books"
				:collections="collections"
				:artists="artists"
				:genres="genres"
				:keys="keys"
				:custom-groups="customGroups"
				:years="years"
				:signatures="signatures"
				:source-types="sourceTypes"
				:instruments="instruments"
				:create-composer-tag="createComposerTag"
				:create-book-tag="createBookTag"
				:create-collection-tag="createCollectionTag"
				:create-artist-tag="createArtistTag"
				:create-genre-tag="createGenreTag"
				:create-key-tag="createKeyTag"
				:create-custom-group-tag="createCustomGroupTag"
				:create-year-tag="createYearTag"
				:create-signature-tag="createSignatureTag"
				:create-source-type-tag="createSourceTypeTag"
				:create-instrument-tag="createInstrumentTag" />

			<SongFileSection
				ref="fileSection"
				:current-files="currentFiles"
				:pending-delete-files="pendingDeleteFiles"
				:pending-detach-files="pendingDetachFiles"
				:pending-additions="pendingAdditions"
				:save-action-label="saveActionLabel"
				:is-saving="isSaving"
				@upload-file="handleFileUpload"
				@choose-nextcloud-file="openNextcloudPicker"
				@open-external="openInExternalViewer"
				@detach="stageDetachFile"
				@delete="stageDeleteFile"
				@restore-delete="restoreDeletedFile"
				@restore-detach="restoreDetachedFile"
				@remove-addition="(addition) => addition.remove()" />

			<p v-if="errorMessage" :class="$style.error" role="alert">{{ errorMessage }}</p>
			<p v-if="successMessage" :class="$style.success" role="status">{{ successMessage }}</p>

			<div v-if="!hideActions" :class="$style.actions">
				<NcButton
					type="button"
					variant="secondary"
					:disabled="isSaving"
					:title="t('sheetmusic', 'Cancel')"
					:aria-label="t('sheetmusic', 'Cancel')"
					@click="emit('cancel')">
					<template #icon>
						<CloseIcon :size="20" />
					</template>
				</NcButton>

				<NcButton
					v-if="!isNewSong"
					type="button"
					variant="secondary"
					:disabled="isSaving"
					:title="t('sheetmusic', 'Save without closing')"
					:aria-label="t('sheetmusic', 'Save without closing')"
					@click="saveSong(false)">
					<template #icon>
						<ContentSaveOutlineIcon :size="20" />
					</template>
				</NcButton>

				<NcButton
					type="button"
					variant="primary"
					:disabled="isSaving"
					:title="saveActionLabel"
					:aria-label="saveActionLabel"
					@click="saveSong(true)">
					<template #icon>
						<CheckIcon :size="20" />
					</template>
				</NcButton>
			</div>
		</form>

		<!-- Nextcloud File Choice Modal (Copy vs Move) -->
		<AttachNextcloudFileDialog
			v-model:open="isAttachDialogOpen"
			v-model:action="attachAction"
			:filename="selectedNextcloudNode?.basename"
			@confirm="stageAttachNextcloudFile" />

		<!-- Delete Song Confirmation Modal -->
		<DeleteSongDialog
			v-model:open="isDeleteDialogOpen"
			:song="song"
			:library-id="libraryId"
			:file-count="currentFiles.length"
			@deleted="handleSongDeleted" />

		<!-- Song History Modal -->
		<SongHistoryModal
			v-if="song && isHistoryOpen"
			v-model:open="isHistoryOpen"
			:song-id="song.id"
			:song-title="song.title"
			@reverted="handleReverted" />
	</div>
</template>

<style module>
.editor {
	display: flex;
	flex-direction: column;
	width: 100%;
	box-sizing: border-box;
	padding: 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	min-width: 0;
}

.header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 20px;
	padding-bottom: 10px;
	border-bottom: 1px solid var(--color-border);
}

.titleInputWrapper {
	flex: 1;
	min-width: 0;
}

.editIndicator {
	color: var(--color-text-maxcontrast);
	display: inline-flex;
	align-items: center;
	flex-shrink: 0;
}

.form {
	display: flex;
	flex-direction: column;
	gap: 16px;
	min-width: 0;
}

.actions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.error {
	color: var(--color-error);
	margin: 8px 0;
}

.success {
	color: var(--color-success);
	margin: 8px 0;
}

.dialogActions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	margin-top: 24px;
}
</style>
