<script setup lang="ts">
import type { INode } from '@nextcloud/files'
import type { Artist, Book, Collection, Composer, CreatedSongData, CustomGroup, Genre, Key, LibraryData, PendingAddition, PendingAttachment, PendingUpload, Signature, Song, SongFile, SongPrefill, SourceType, Year } from '../types/song'

import axios from '@nextcloud/axios'
import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { apiPost } from '../api/client'
import { describeRequestFailure } from '../utils/api'
import { openFileExternally } from '../utils/file'
import { computed, ref, watch } from 'vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import ContentSaveOutlineIcon from 'vue-material-design-icons/ContentSaveOutline.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import UploadIcon from 'vue-material-design-icons/Upload.vue'
import AttachNextcloudFileDialog from './song-editor/AttachNextcloudFileDialog.vue'
import SongFileListItem from './song-editor/SongFileListItem.vue'
import SongPendingNotice from './song-editor/SongPendingNotice.vue'

const props = withDefaults(
	defineProps<{
		/** The song to edit. Null opens the editor for a new song. */
		song?: Song | null
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
		/** Metadata read from an imported PDF, prefilled into a new song. */
		prefill?: SongPrefill | null
		/** The imported PDF itself, staged as the new song's file. */
		importedFile?: File | null
		/** An imported Nextcloud PDF file, staged as the new song's file attachment. */
		importedNextcloudNode?: { path: string, name: string } | null
	}>(),
	{
		song: null,
		genres: () => [],
		keys: () => [],
		customGroups: () => [],
		years: () => [],
		signatures: () => [],
		sourceTypes: () => [],
		prefill: null,
		importedFile: null,
		importedNextcloudNode: null,
	},
)

const emit = defineEmits<{
	/** The song was saved; the editor closes. The id of a created song is passed along. */
	saved: [data: LibraryData, songId?: number]
	/** The song's data changed and the editor stays open, e.g. after saving without closing. */
	updated: [data: LibraryData]
	cancel: []
}>()

/** A new song has no id yet: it is created in the library when the editor is saved. */
const isNewSong = computed(() => !props.song)

/** Label of the primary button, also used by the notices about staged file changes. */
const saveActionLabel = computed(() => (isNewSong.value
	? t('mobilesheetsmanager', 'Create song')
	: t('mobilesheetsmanager', 'Save and Close')))

function matchSelected<T extends { id: number }>(options: T[], selected?: { id: number }[]): T[] {
	const ids = new Set((selected ?? []).map((item) => item.id))
	return options.filter((option) => ids.has(option.id))
}

/**
 * Turn the names read from a PDF into the selected entities: an existing one when its
 * name matches, a new tag otherwise - which the editor creates when the song is saved.
 */
function prefillByName<T extends { id: number }, TTag>(
	names: string[] | undefined,
	options: T[],
	nameOf: (item: T) => string,
	makeTag: (name: string) => TTag,
): Array<T | TTag> {
	return (names ?? []).map((name) =>
		options.find((option) => nameOf(option).toLowerCase() === name.toLowerCase()) ?? makeTag(name),
	)
}

const title = ref(props.song?.title ?? props.prefill?.title ?? '')
const selectedComposers = ref<Array<Composer | { name: string, id?: number }>>(
	props.song
		? matchSelected(props.composers, props.song.composers)
		: prefillByName(props.prefill?.composers, props.composers, (composer) => composer.name, createComposerTag),
)
const selectedBooks = ref<Array<Book | { title: string, id?: number }>>(
	props.song
		? matchSelected(props.books, props.song.books)
		: prefillByName(props.prefill?.books, props.books, (book) => book.title, createBookTag),
)
const selectedCollections = ref<Array<Collection | { name: string, id?: number }>>(
	props.song
		? matchSelected(props.collections, props.song.collections)
		: prefillByName(props.prefill?.collections, props.collections, (collection) => collection.name, createCollectionTag),
)
const selectedArtists = ref<Array<Artist | { name: string, id?: number }>>(
	props.song
		? matchSelected(props.artists, props.song.artists)
		: prefillByName(props.prefill?.artists, props.artists, (artist) => artist.name, createArtistTag),
)
const selectedGenres = ref<Array<Genre | { name: string, id?: number }>>(
	props.song
		? matchSelected(props.genres, props.song.genres)
		: [],
)
const selectedKeys = ref<Array<Key | { name: string, id?: number }>>(
	props.song
		? matchSelected(props.keys, props.song.keys)
		: [],
)
const selectedCustomGroups = ref<Array<CustomGroup | { name: string, id?: number }>>(
	props.song
		? matchSelected(props.customGroups, props.song.customGroups)
		: [],
)
const selectedYears = ref<Array<Year | { name: string, id?: number }>>(
	props.song
		? matchSelected(props.years, props.song.years)
		: [],
)
const selectedSignatures = ref<Array<Signature | { name: string, id?: number }>>(
	props.song
		? matchSelected(props.signatures, props.song.signatures)
		: [],
)
const selectedSourceTypes = ref<Array<SourceType | { name: string, id?: number }>>(
	props.song
		? matchSelected(props.sourceTypes, props.song.sourceTypes)
		: [],
)

const stars = ref<number>(props.song?.stars ?? 0)
const difficulty = ref<number>(props.song?.difficulty ?? 0)
const duration = ref<number>(props.song?.duration ?? 0)
const keywords = ref<string>(props.song?.keywords ?? '')
const custom = ref<string>(props.song?.custom ?? '')
const custom2 = ref<string>(props.song?.custom2 ?? '')
const tempo = ref<number>(props.song?.tempos?.[0]?.tempo ?? 0)
const notes = ref<Array<{ id?: number, note: string, title?: string }>>(
	(props.song?.notes ?? []).map((n) => ({ id: n.id, note: n.note, title: n.title ?? '' })),
)
const bookmarks = ref<Array<{ id?: number, name: string, pageNum: number }>>(
	(props.song?.bookmarks ?? []).map((b) => ({ id: b.id, name: b.name, pageNum: b.pageNum })),
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

watch(
	() => props.song,
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
		stars.value = newSong?.stars ?? 0
		difficulty.value = newSong?.difficulty ?? 0
		duration.value = newSong?.duration ?? 0
		keywords.value = newSong?.keywords ?? ''
		custom.value = newSong?.custom ?? ''
		custom2.value = newSong?.custom2 ?? ''
		tempo.value = newSong?.tempos?.[0]?.tempo ?? 0
		notes.value = (newSong?.notes ?? []).map((n) => ({ id: n.id, note: n.note, title: n.title ?? '' }))
		bookmarks.value = (newSong?.bookmarks ?? []).map((b) => ({ id: b.id, name: b.name, pageNum: b.pageNum }))

		// A refresh in place keeps the deletions staged in this session: the editor stays
		// open now, and the file list of the refreshed song is what the displayed paths
		// are taken from. Editing another song - or starting a new one - begins with a
		// clean slate, which also drops the additions staged for the song edited before.
		const sameSong = !!newSong && newSong.id === previousSong?.id
		const pending = sameSong
			? pendingDeleteFiles.value.map(
				(file) => newSong.files?.find((candidate) => candidate.id === file.id) ?? file,
			)
			: []

		if (!sameSong) {
			pendingAttachments.value = []
			pendingUploads.value = []
		}

		pendingDeleteFiles.value = pending
		currentFiles.value = getInitialFiles(newSong, pending)
	},
)

const isSaving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const fileInput = ref<HTMLInputElement>()

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
			? t('mobilesheetsmanager', 'Move from Nextcloud')
			: t('mobilesheetsmanager', 'Copy from Nextcloud'),
		remove: () => removePendingAttachment(attachment),
	})),
	...pendingUploads.value.map((upload): PendingAddition => ({
		key: upload.key,
		name: upload.name,
		hint: t('mobilesheetsmanager', 'Upload from this computer'),
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
	stageAttachment(props.importedNextcloudNode.path, props.importedNextcloudNode.name, 'copy')
}

function createComposerTag(newTag: string) {
	return { name: newTag }
}

function createBookTag(newTag: string) {
	return { title: newTag }
}

function createCollectionTag(newTag: string) {
	return { name: newTag }
}

function createArtistTag(newTag: string) {
	return { name: newTag }
}

function createGenreTag(newTag: string) {
	return { name: newTag }
}

function createKeyTag(newTag: string) {
	return { name: newTag }
}

function createCustomGroupTag(newTag: string) {
	return { name: newTag }
}

function createYearTag(newTag: string) {
	return { name: newTag }
}

function createSignatureTag(newTag: string) {
	return { name: newTag }
}

function createSourceTypeTag(newTag: string) {
	return { name: newTag }
}

function stageDeleteFile(file: SongFile) {
	currentFiles.value = currentFiles.value.filter((f) => f.id !== file.id)
	if (!isStagedForDeletion(file, pendingDeleteFiles.value)) {
		pendingDeleteFiles.value.push(file)
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

/**
 * Choosing a file on the computer only stages it. The upload - including the move into
 * the library folder - runs when the song is saved, just like a staged deletion.
 */
function handleFileUpload(event: Event) {
	const target = event.target as HTMLInputElement
	const file = target.files?.[0]
	if (fileInput.value) {
		// Reset right away, so the same file can be picked again after removing it.
		fileInput.value.value = ''
	}
	if (!file) {
		return
	}

	errorMessage.value = ''
	stageUpload(file)
	successMessage.value = t('mobilesheetsmanager', 'The file is added to the song when you save.')
}

async function openNextcloudPicker() {
	errorMessage.value = ''
	const picker = getFilePickerBuilder(t('mobilesheetsmanager', 'Select a file for this song'))
		.setMultiSelect(false)
		.setFilter((node) => node.type !== 'file')
		.setCanPick((node) => node.type === 'file')
		.setButtonFactory(([node]) => [{
			label: node
				? t('mobilesheetsmanager', 'Select {file}', { file: node.basename })
				: t('mobilesheetsmanager', 'Select a file'),
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
			errorMessage.value = t('mobilesheetsmanager', 'Could not select a file from Nextcloud.')
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
	successMessage.value = t('mobilesheetsmanager', 'The file is added to the song when you save.')
}

async function saveSong(closeAfter: boolean) {
	if (!title.value.trim()) {
		errorMessage.value = t('mobilesheetsmanager', 'Song title cannot be empty.')
		return
	}

	errorMessage.value = ''
	isSaving.value = true

	// Snapshot: props.song is null while a new song is being created.
	const existingSong = props.song

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

		// Resolve or create composers
		const composerIds: number[] = []
		for (const comp of selectedComposers.value) {
			if ('id' in comp && typeof comp.id === 'number') {
				composerIds.push(comp.id)
			} else {
				const createRes = await apiPost('/api/composers', {
					libraryId: props.libraryId,
					name: comp.name,
				})
				composerIds.push(createRes.data.ocs.data.id)
			}
		}

		// Resolve or create books
		const bookIds: number[] = []
		for (const bk of selectedBooks.value) {
			if ('id' in bk && typeof bk.id === 'number') {
				bookIds.push(bk.id)
			} else {
				const createRes = await apiPost('/api/books', {
					libraryId: props.libraryId,
					title: bk.title,
				})
				bookIds.push(createRes.data.ocs.data.id)
			}
		}

		// Resolve or create collections
		const collectionIds: number[] = []
		for (const col of selectedCollections.value) {
			if ('id' in col && typeof col.id === 'number') {
				collectionIds.push(col.id)
			} else {
				const createRes = await apiPost('/api/collections', {
					libraryId: props.libraryId,
					name: col.name,
				})
				collectionIds.push(createRes.data.ocs.data.id)
			}
		}

		// Resolve or create artists
		const artistIds: number[] = []
		for (const art of selectedArtists.value) {
			if ('id' in art && typeof art.id === 'number') {
				artistIds.push(art.id)
			} else {
				const createRes = await apiPost('/api/artists', {
					libraryId: props.libraryId,
					name: art.name,
				})
				artistIds.push(createRes.data.ocs.data.id)
			}
		}

		// Resolve or create genres
		const genreIds: number[] = []
		for (const gen of selectedGenres.value) {
			if ('id' in gen && typeof gen.id === 'number') {
				genreIds.push(gen.id)
			} else {
				const createRes = await apiPost('/api/genres', {
					libraryId: props.libraryId,
					name: gen.name,
				})
				genreIds.push(createRes.data.ocs.data.id)
			}
		}

		// Resolve or create keys
		const keyIds: number[] = []
		for (const k of selectedKeys.value) {
			if ('id' in k && typeof k.id === 'number') {
				keyIds.push(k.id)
			} else {
				const createRes = await apiPost('/api/keys', {
					libraryId: props.libraryId,
					name: k.name,
				})
				keyIds.push(createRes.data.ocs.data.id)
			}
		}

		// Resolve or create custom groups
		const customGroupIds: number[] = []
		for (const cg of selectedCustomGroups.value) {
			if ('id' in cg && typeof cg.id === 'number') {
				customGroupIds.push(cg.id)
			} else {
				const createRes = await apiPost('/api/customgroups', {
					libraryId: props.libraryId,
					name: cg.name,
				})
				customGroupIds.push(createRes.data.ocs.data.id)
			}
		}

		// Resolve or create years
		const yearIds: number[] = []
		for (const yr of selectedYears.value) {
			if ('id' in yr && typeof yr.id === 'number') {
				yearIds.push(yr.id)
			} else {
				const createRes = await apiPost('/api/years', {
					libraryId: props.libraryId,
					name: yr.name,
				})
				yearIds.push(createRes.data.ocs.data.id)
			}
		}

		// Resolve or create signatures
		const signatureIds: number[] = []
		for (const sig of selectedSignatures.value) {
			if ('id' in sig && typeof sig.id === 'number') {
				signatureIds.push(sig.id)
			} else {
				const createRes = await apiPost('/api/signatures', {
					libraryId: props.libraryId,
					name: sig.name,
				})
				signatureIds.push(createRes.data.ocs.data.id)
			}
		}

		// Resolve or create source types
		const sourceTypeIds: number[] = []
		for (const st of selectedSourceTypes.value) {
			if ('id' in st && typeof st.id === 'number') {
				sourceTypeIds.push(st.id)
			} else {
				const createRes = await apiPost('/api/sourcetypes', {
					libraryId: props.libraryId,
					name: st.name,
				})
				sourceTypeIds.push(createRes.data.ocs.data.id)
			}
		}

		// The song is created or updated before the staged file additions: those need the
		// song's id, and the naming rules use the composers, artists and collections the
		// user just saved.
		const payload = {
			libraryId: props.libraryId,
			title: title.value.trim(),
			composerIds,
			bookIds,
			collectionIds,
			artistIds,
			genreIds,
			keyIds,
			customGroupIds,
			yearIds,
			signatureIds,
			sourceTypeIds,
			stars: Number(stars.value) || 0,
			difficulty: Number(difficulty.value) || 0,
			duration: Number(duration.value) || 0,
			keywords: keywords.value.trim(),
			custom: custom.value.trim(),
			custom2: custom2.value.trim(),
			notes: notes.value.filter((n) => n.note.trim() !== '' || (n.title && n.title.trim() !== '')),
			tempos: Number(tempo.value) > 0 ? [Number(tempo.value)] : [],
			bookmarks: bookmarks.value.filter((b) => b.name.trim() !== ''),
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
				formData.append('libraryId', String(props.libraryId))

				const uploadResponse = await apiPost('/api/songs/{id}/file/upload', formData as any, {
					pathParams: { id: songId },
					config: {
						headers: { 'Content-Type': 'multipart/form-data' },
					},
				})
				data = uploadResponse.data.ocs.data as LibraryData
			}
		}

		pendingAttachments.value = []
		pendingUploads.value = []

		successMessage.value = isNewSong.value
			? t('mobilesheetsmanager', 'Song created successfully.')
			: t('mobilesheetsmanager', 'Song updated successfully.')
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
			? t('mobilesheetsmanager', 'Failed to create the song.')
			: t('mobilesheetsmanager', 'Failed to save song changes.')
		errorMessage.value = describeRequestFailure(error, fallback)
	} finally {
		isSaving.value = false
	}
}
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
						:placeholder="isNewSong ? t('mobilesheetsmanager', 'New Song') : t('mobilesheetsmanager', 'Enter song title')"
						:label="t('mobilesheetsmanager', 'Song Title')"
						required />
				</div>
			</div>

			<div :class="$style.formGroup">
				<NcSelect
					v-model="selectedComposers"
					:options="composers"
					:multiple="true"
					:taggable="true"
					:create-option="createComposerTag"
					:close-on-select="false"
					:input-label="t('mobilesheetsmanager', 'Composers')"
					label="name"
					track-by="name"
					:placeholder="t('mobilesheetsmanager', 'Select or type a composer…')" />
			</div>

			<div :class="$style.formGroup">
				<NcSelect
					v-model="selectedBooks"
					:options="books"
					:multiple="true"
					:taggable="true"
					:create-option="createBookTag"
					:close-on-select="false"
					:input-label="t('mobilesheetsmanager', 'Books / Albums')"
					label="title"
					track-by="title"
					:placeholder="t('mobilesheetsmanager', 'Select or type a book/album…')" />
			</div>

			<div :class="$style.formGroup">
				<NcSelect
					v-model="selectedCollections"
					:options="collections"
					:multiple="true"
					:taggable="true"
					:create-option="createCollectionTag"
					:close-on-select="false"
					:input-label="t('mobilesheetsmanager', 'Collections')"
					label="name"
					track-by="name"
					:placeholder="t('mobilesheetsmanager', 'Select or type a collection…')" />
			</div>

			<div :class="$style.formGroup">
				<NcSelect
					v-model="selectedArtists"
					:options="artists"
					:multiple="true"
					:taggable="true"
					:create-option="createArtistTag"
					:close-on-select="false"
					:input-label="t('mobilesheetsmanager', 'Artists')"
					label="name"
					track-by="name"
					:placeholder="t('mobilesheetsmanager', 'Select or type an artist…')" />
			</div>

			<div :class="$style.formGroup">
				<NcSelect
					v-model="selectedGenres"
					:options="genres"
					:multiple="true"
					:taggable="true"
					:create-option="createGenreTag"
					:close-on-select="false"
					:input-label="t('mobilesheetsmanager', 'Genres')"
					label="name"
					track-by="name"
					:placeholder="t('mobilesheetsmanager', 'Select or type a genre…')" />
			</div>

			<div :class="$style.formGroup">
				<NcSelect
					v-model="selectedKeys"
					:options="keys"
					:multiple="true"
					:taggable="true"
					:create-option="createKeyTag"
					:close-on-select="false"
					:input-label="t('mobilesheetsmanager', 'Keys')"
					label="name"
					track-by="name"
					:placeholder="t('mobilesheetsmanager', 'Select or type a key…')" />
			</div>

			<div :class="$style.formGroup">
				<NcSelect
					v-model="selectedCustomGroups"
					:options="customGroups"
					:multiple="true"
					:taggable="true"
					:create-option="createCustomGroupTag"
					:close-on-select="false"
					:input-label="t('mobilesheetsmanager', 'Custom Groups')"
					label="name"
					track-by="name"
					:placeholder="t('mobilesheetsmanager', 'Select or type a custom group…')" />
			</div>

			<div :class="$style.formGroup">
				<NcSelect
					v-model="selectedYears"
					:options="years"
					:multiple="true"
					:taggable="true"
					:create-option="createYearTag"
					:close-on-select="false"
					:input-label="t('mobilesheetsmanager', 'Years')"
					label="name"
					track-by="name"
					:placeholder="t('mobilesheetsmanager', 'Select or type a year…')" />
			</div>

			<div :class="$style.formGroup">
				<NcSelect
					v-model="selectedSignatures"
					:options="signatures"
					:multiple="true"
					:taggable="true"
					:create-option="createSignatureTag"
					:close-on-select="false"
					:input-label="t('mobilesheetsmanager', 'Time Signatures')"
					label="name"
					track-by="name"
					:placeholder="t('mobilesheetsmanager', 'Select or type a time signature (e.g. 4/4)…')" />
			</div>

			<div :class="$style.formGroup">
				<NcSelect
					v-model="selectedSourceTypes"
					:options="sourceTypes"
					:multiple="true"
					:taggable="true"
					:create-option="createSourceTypeTag"
					:close-on-select="false"
					:input-label="t('mobilesheetsmanager', 'Source Types')"
					label="name"
					track-by="name"
					:placeholder="t('mobilesheetsmanager', 'Select or type a source type…')" />
			</div>

			<div :class="$style.attributesGrid">
				<div :class="$style.formGroup">
					<NcTextField
						id="song-rating-input"
						v-model.number="stars"
						type="number"
						:min="0"
						:max="5"
						:placeholder="t('mobilesheetsmanager', '0-5')"
						:label="t('mobilesheetsmanager', 'Rating (Stars 0-5)')" />
				</div>
				<div :class="$style.formGroup">
					<NcTextField
						id="song-difficulty-input"
						v-model.number="difficulty"
						type="number"
						:min="0"
						:max="5"
						:placeholder="t('mobilesheetsmanager', '0-5')"
						:label="t('mobilesheetsmanager', 'Difficulty (0-5)')" />
				</div>
				<div :class="$style.formGroup">
					<NcTextField
						id="song-duration-input"
						v-model.number="duration"
						type="number"
						:min="0"
						:placeholder="t('mobilesheetsmanager', 'Seconds (e.g. 180)')"
						:label="t('mobilesheetsmanager', 'Duration (seconds)')" />
				</div>
				<div :class="$style.formGroup">
					<NcTextField
						id="song-keywords-input"
						v-model="keywords"
						:placeholder="t('mobilesheetsmanager', 'Keywords / tags…')"
						:label="t('mobilesheetsmanager', 'Keywords')" />
				</div>
				<div :class="$style.formGroup">
					<NcTextField
						id="song-custom-input"
						v-model="custom"
						:placeholder="t('mobilesheetsmanager', 'Custom field…')"
						:label="t('mobilesheetsmanager', 'Custom')" />
				</div>
				<div :class="$style.formGroup">
					<NcTextField
						id="song-custom2-input"
						v-model="custom2"
						:placeholder="t('mobilesheetsmanager', 'Custom field 2…')"
						:label="t('mobilesheetsmanager', 'Custom 2')" />
				</div>
				<div :class="$style.formGroup">
					<NcTextField
						id="song-tempo-input"
						:model-value="tempo ? String(tempo) : ''"
						type="number"
						:placeholder="t('mobilesheetsmanager', 'Tempo (BPM)…')"
						:label="t('mobilesheetsmanager', 'Tempo (BPM)')"
						@update:model-value="tempo = Number($event) || 0" />
				</div>
			</div>

			<div :class="$style.fileSection">
				<label :class="$style.label">{{ t('mobilesheetsmanager', 'Attached Files') }}</label>

				<ul v-if="currentFiles.length > 0" :class="$style.fileList">
					<SongFileListItem
						v-for="file in currentFiles"
						:key="file.id"
						:file="file"
						:disabled="isSaving"
						@open-external="openInExternalViewer"
						@delete="stageDeleteFile" />
				</ul>
				<p v-else :class="$style.noFiles">
					{{ t('mobilesheetsmanager', 'No files attached to this song.') }}
				</p>

				<SongPendingNotice
					:pending-delete-files="pendingDeleteFiles"
					:pending-additions="pendingAdditions"
					:save-action-label="saveActionLabel"
					@restore-delete="restoreDeletedFile"
					@remove-addition="(addition) => addition.remove()" />

				<div :class="$style.fileButtons">
					<input
						ref="fileInput"
						type="file"
						style="display: none;"
						@change="handleFileUpload">
					<NcButton
						type="button"
						variant="secondary"
						:disabled="isSaving"
						:title="t('mobilesheetsmanager', 'Upload file from computer')"
						:aria-label="t('mobilesheetsmanager', 'Upload file from computer')"
						@click="fileInput?.click()">
						<template #icon>
							<UploadIcon :size="20" />
						</template>
					</NcButton>

					<NcButton
						type="button"
						variant="secondary"
						:disabled="isSaving"
						:title="t('mobilesheetsmanager', 'Choose file from Nextcloud')"
						:aria-label="t('mobilesheetsmanager', 'Choose file from Nextcloud')"
						@click="openNextcloudPicker">
						<template #icon>
							<FolderIcon :size="20" />
						</template>
					</NcButton>
				</div>
			</div>

			<p v-if="errorMessage" :class="$style.error" role="alert">{{ errorMessage }}</p>
			<p v-if="successMessage" :class="$style.success" role="status">{{ successMessage }}</p>

			<div :class="$style.actions">
				<NcButton
					:disabled="isSaving"
					:title="t('mobilesheetsmanager', 'Cancel')"
					:aria-label="t('mobilesheetsmanager', 'Cancel')"
					@click="emit('cancel')">
					<template #icon>
						<CloseIcon :size="20" />
					</template>
				</NcButton>
				<!-- Only an existing song can be saved again: a new song has no id until it
				     is created, so saving it twice would write a second song. -->
				<NcButton
					v-if="!isNewSong"
					type="button"
					variant="secondary"
					:disabled="isSaving"
					:title="t('mobilesheetsmanager', 'Save without closing')"
					:aria-label="t('mobilesheetsmanager', 'Save without closing')"
					@click="saveSong(false)">
					<template #icon>
						<ContentSaveOutlineIcon :size="20" />
					</template>
				</NcButton>
				<NcButton
					variant="primary"
					type="submit"
					:disabled="isSaving"
					:title="saveActionLabel"
					:aria-label="saveActionLabel">
					<template #icon>
						<NcLoadingIcon v-if="isSaving" :size="16" />
						<CheckIcon v-else :size="20" />
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

.formGroup {
	display: flex;
	flex-direction: column;
	gap: 6px;
	min-width: 0;
	position: relative;
}

.attributesGrid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 12px;
}

.formGroup:focus-within {
	z-index: 10;
}

.label {
	font-size: 0.9rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.fileSection {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 16px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius, 4px);
	margin-top: 8px;
	min-width: 0;
	box-sizing: border-box;
}

.fileList {
	margin: 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-width: 0;
}

.noFiles {
	margin: 0;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.fileButtons {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	margin-top: 8px;
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
</style>
