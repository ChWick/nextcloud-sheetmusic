<script setup lang="ts">
import type { INode } from '@nextcloud/files'
import type { Artist, Book, Collection, Composer, CreatedSongData, LibraryData, PendingAddition, PendingAttachment, PendingUpload, Song, SongFile, SongPrefill } from '../types/song'

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
		/** Metadata read from an imported PDF, prefilled into a new song. */
		prefill?: SongPrefill | null
		/** The imported PDF itself, staged as the new song's file. */
		importedFile?: File | null
	}>(),
	{
		song: null,
		prefill: null,
		importedFile: null,
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

	if (!file.name.toLowerCase().endsWith('.pdf')) {
		errorMessage.value = t('mobilesheetsmanager', 'Only PDF files are supported.')
		return
	}

	errorMessage.value = ''
	stageUpload(file)
	successMessage.value = t('mobilesheetsmanager', 'The file is added to the song when you save.')
}

async function openNextcloudPicker() {
	errorMessage.value = ''
	const picker = getFilePickerBuilder(t('mobilesheetsmanager', 'Select a PDF file for this song'))
		.setMultiSelect(false)
		.setFilter((node) => node.type !== 'file' || node.basename.toLowerCase().endsWith('.pdf'))
		.setCanPick((node) => node.type === 'file' && node.basename.toLowerCase().endsWith('.pdf'))
		.setButtonFactory(([node]) => [{
			label: node
				? t('mobilesheetsmanager', 'Select {file}', { file: node.basename })
				: t('mobilesheetsmanager', 'Select a PDF'),
			callback: () => {},
			disabled: !node || node.type !== 'file' || !node.basename.toLowerCase().endsWith('.pdf'),
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
		<div :class="$style.header">
			<h3>{{ isNewSong ? t('mobilesheetsmanager', 'New Song') : t('mobilesheetsmanager', 'Edit Song') }}</h3>
		</div>

		<form :class="$style.form" @submit.prevent="saveSong(true)">
			<div :class="$style.formGroup">
				<label :class="$style.label" for="song-title-input">{{ t('mobilesheetsmanager', 'Song Title') }}</label>
				<NcTextField
					id="song-title-input"
					v-model="title"
					:placeholder="t('mobilesheetsmanager', 'Enter song title')"
					required />
			</div>

			<div :class="$style.formGroup">
				<label :class="$style.label">{{ t('mobilesheetsmanager', 'Composers') }}</label>
				<NcSelect
					v-model="selectedComposers"
					:options="composers"
					:multiple="true"
					:taggable="true"
					:create-option="createComposerTag"
					:close-on-select="false"
					label="name"
					track-by="name"
					:placeholder="t('mobilesheetsmanager', 'Select or type a composer…')" />
			</div>

			<div :class="$style.formGroup">
				<label :class="$style.label">{{ t('mobilesheetsmanager', 'Books / Albums') }}</label>
				<NcSelect
					v-model="selectedBooks"
					:options="books"
					:multiple="true"
					:taggable="true"
					:create-option="createBookTag"
					:close-on-select="false"
					label="title"
					track-by="title"
					:placeholder="t('mobilesheetsmanager', 'Select or type a book/album…')" />
			</div>

			<div :class="$style.formGroup">
				<label :class="$style.label">{{ t('mobilesheetsmanager', 'Collections') }}</label>
				<NcSelect
					v-model="selectedCollections"
					:options="collections"
					:multiple="true"
					:taggable="true"
					:create-option="createCollectionTag"
					:close-on-select="false"
					label="name"
					track-by="name"
					:placeholder="t('mobilesheetsmanager', 'Select or type a collection…')" />
			</div>

			<div :class="$style.formGroup">
				<label :class="$style.label">{{ t('mobilesheetsmanager', 'Artists') }}</label>
				<NcSelect
					v-model="selectedArtists"
					:options="artists"
					:multiple="true"
					:taggable="true"
					:create-option="createArtistTag"
					:close-on-select="false"
					label="name"
					track-by="name"
					:placeholder="t('mobilesheetsmanager', 'Select or type an artist…')" />
			</div>

			<div :class="$style.fileSection">
				<label :class="$style.label">{{ t('mobilesheetsmanager', 'Attached PDF Files') }}</label>

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
					{{ t('mobilesheetsmanager', 'No PDF files attached to this song.') }}
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
						accept=".pdf,application/pdf"
						style="display: none;"
						@change="handleFileUpload">
					<NcButton
						type="button"
						variant="secondary"
						:disabled="isSaving"
						@click="fileInput?.click()">
						{{ t('mobilesheetsmanager', 'Upload PDF from computer') }}
					</NcButton>

					<NcButton
						type="button"
						variant="secondary"
						:disabled="isSaving"
						@click="openNextcloudPicker">
						{{ t('mobilesheetsmanager', 'Choose PDF from Nextcloud') }}
					</NcButton>
				</div>
			</div>

			<p v-if="errorMessage" :class="$style.error" role="alert">{{ errorMessage }}</p>
			<p v-if="successMessage" :class="$style.success" role="status">{{ successMessage }}</p>

			<div :class="$style.actions">
				<NcButton :disabled="isSaving" @click="emit('cancel')">
					{{ t('mobilesheetsmanager', 'Cancel') }}
				</NcButton>
				<!-- Only an existing song can be saved again: a new song has no id until it
				     is created, so saving it twice would write a second song. -->
				<NcButton
					v-if="!isNewSong"
					type="button"
					variant="secondary"
					:disabled="isSaving"
					:title="t('mobilesheetsmanager', 'Save the changes and keep the editor open')"
					@click="saveSong(false)">
					{{ t('mobilesheetsmanager', 'Save without closing') }}
				</NcButton>
				<NcButton
					variant="primary"
					type="submit"
					:disabled="isSaving">
					<template v-if="isSaving">
						<NcLoadingIcon :size="16" />
					</template>
					{{ saveActionLabel }}
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
	margin-bottom: 20px;
	padding-bottom: 10px;
	border-bottom: 1px solid var(--color-border);
}

.header h3 {
	margin: 0;
	font-size: 1.3rem;
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
