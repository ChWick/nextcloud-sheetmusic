<script setup lang="ts">
import type { INode } from '@nextcloud/files'
import type { Artist, Book, Collection, Composer, LibraryData, Song, SongFile } from '../types/song'

import axios from '@nextcloud/axios'
import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref, watch } from 'vue'

const props = defineProps<{
	song: Song
    libraryId: number
	composers: Composer[]
	books: Book[]
	collections: Collection[]
	artists: Artist[]
}>()

const emit = defineEmits<{
	/** The song was saved; the editor closes. */
	saved: [data: LibraryData]
	/** The song's data changed without saving; the editor stays open. */
	updated: [data: LibraryData]
	cancel: []
}>()

function matchSelected<T extends { id: number }>(options: T[], selected?: { id: number }[]): T[] {
	const ids = new Set((selected ?? []).map((item) => item.id))
	return options.filter((option) => ids.has(option.id))
}

const title = ref(props.song.title)
const selectedComposers = ref<Array<Composer | { name: string, id?: number }>>(
	matchSelected(props.composers, props.song.composers),
)
const selectedBooks = ref<Array<Book | { title: string, id?: number }>>(
	matchSelected(props.books, props.song.books),
)
const selectedCollections = ref<Array<Collection | { name: string, id?: number }>>(
	matchSelected(props.collections, props.song.collections),
)
const selectedArtists = ref<Array<Artist | { name: string, id?: number }>>(
	matchSelected(props.artists, props.song.artists),
)

function getServerFiles(s: Song): SongFile[] {
	return s.files ? [...s.files] : []
}

/**
 * The database id is what a deletion targets and it survives a rename, while the path of a
 * file changes as soon as the song's files are renumbered.
 */
function isStagedForDeletion(file: SongFile, pending: SongFile[]): boolean {
	return pending.some((candidate) => candidate.id === file.id)
}

/** Files of a song the editor shows, i.e. without the ones staged for deletion. */
function getInitialFiles(s: Song, pending: SongFile[] = []): SongFile[] {
	return getServerFiles(s).filter((file) => !isStagedForDeletion(file, pending))
}

const currentFiles = ref<SongFile[]>(getInitialFiles(props.song))
const pendingDeleteFiles = ref<SongFile[]>([])

watch(
	() => props.song,
	(newSong, previousSong) => {
		title.value = newSong.title
		selectedComposers.value = matchSelected(props.composers, newSong.composers)
		selectedBooks.value = matchSelected(props.books, newSong.books)
		selectedCollections.value = matchSelected(props.collections, newSong.collections)
		selectedArtists.value = matchSelected(props.artists, newSong.artists)

		// A refresh in place keeps the deletions staged in this session: the editor stays
		// open now, and the file list of the refreshed song is what the displayed paths
		// are taken from. Editing another song starts from a clean slate instead, which
		// also drops the additions staged for the song edited before.
		const sameSong = newSong.id === previousSong?.id
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

/** A Nextcloud file the user picked: only moved/copied when the song is saved. */
interface PendingAttachment {
	key: string
	/** Path of the file in the user's Nextcloud files */
	path: string
	name: string
	action: 'copy' | 'move'
}

/** A file chosen on the computer: only uploaded when the song is saved. */
interface PendingUpload {
	key: string
	file: File
	name: string
}

/** Row of the pending additions notice; both kinds of additions share the display. */
interface PendingAddition {
	key: string
	name: string
	hint: string
	remove: () => void
}

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

function getFileName(filePath: string): string {
	return filePath.split('/').pop() ?? filePath
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

async function saveSong() {
	if (!title.value.trim()) {
		errorMessage.value = t('mobilesheetsmanager', 'Song title cannot be empty.')
		return
	}

	errorMessage.value = ''
	isSaving.value = true

	try {
		// Process pending file deletions
		if (pendingDeleteFiles.value.length > 0) {
			for (const file of pendingDeleteFiles.value) {
				if (file.id > 0) {
					await axios.post(
						generateOcsUrl(`/apps/mobilesheetsmanager/api/songs/${props.song.id}/file/${file.id}/delete`),
						{
							libraryId: props.libraryId,
						},
					)
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
				const createRes = await axios.post(generateOcsUrl('/apps/mobilesheetsmanager/api/composers'), {
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
				const createRes = await axios.post(generateOcsUrl('/apps/mobilesheetsmanager/api/books'), {
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
				const createRes = await axios.post(generateOcsUrl('/apps/mobilesheetsmanager/api/collections'), {
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
				const createRes = await axios.post(generateOcsUrl('/apps/mobilesheetsmanager/api/artists'), {
					libraryId: props.libraryId,
					name: art.name,
				})
				artistIds.push(createRes.data.ocs.data.id)
			}
		}

		const response = await axios.post(
			generateOcsUrl(`/apps/mobilesheetsmanager/api/songs/${props.song.id}`),
			{
				libraryId: props.libraryId,
				title: title.value.trim(),
				composerIds,
				bookIds,
				collectionIds,
				artistIds,
			},
		)
		let data = response.data.ocs.data as LibraryData

		// Staged file additions are applied after the metadata: the naming rules of a song
		// use its composers, artists and collections, so an attached or uploaded file has
		// to be named with the values the user just saved. Each addition renumbers the
		// song's files, so the data of the last one is what the parent gets.
		for (const attachment of pendingAttachments.value) {
			const attachResponse = await axios.post(
				generateOcsUrl(`/apps/mobilesheetsmanager/api/songs/${props.song.id}/file/attach`),
				{
					libraryId: props.libraryId,
					sourcePath: attachment.path,
					action: attachment.action,
				},
			)
			data = attachResponse.data.ocs.data as LibraryData
		}

		for (const upload of pendingUploads.value) {
			const formData = new FormData()
			formData.append('file', upload.file)
			formData.append('libraryId', String(props.libraryId))

			const uploadResponse = await axios.post(
				generateOcsUrl(`/apps/mobilesheetsmanager/api/songs/${props.song.id}/file/upload`),
				formData,
				{
					headers: { 'Content-Type': 'multipart/form-data' },
				},
			)
			data = uploadResponse.data.ocs.data as LibraryData
		}

		pendingAttachments.value = []
		pendingUploads.value = []

		successMessage.value = t('mobilesheetsmanager', 'Song updated successfully.')
		emit('saved', data)
	} catch {
		errorMessage.value = t('mobilesheetsmanager', 'Failed to save song changes.')
	} finally {
		isSaving.value = false
	}
}
</script>

<template>
	<div :class="$style.editor">
		<div :class="$style.header">
			<h3>{{ t('mobilesheetsmanager', 'Edit Song') }}</h3>
		</div>

		<form :class="$style.form" @submit.prevent="saveSong">
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
					<li v-for="file in currentFiles" :key="file.id" :class="$style.fileItem">
						<div :class="$style.fileInfo">
							<span :class="$style.fileName">{{ getFileName(file.path) }}</span>
							<span :class="$style.filePathText">{{ file.path }}</span>
						</div>
						<NcButton
							type="button"
							variant="error"
							:class="$style.deleteButton"
							:disabled="isSaving"
							:aria-label="t('mobilesheetsmanager', 'Delete file {file}', { file: getFileName(file.path) })"
							@click="stageDeleteFile(file)">
							{{ t('mobilesheetsmanager', 'Delete') }}
						</NcButton>
					</li>
				</ul>
				<p v-else :class="$style.noFiles">
					{{ t('mobilesheetsmanager', 'No PDF files attached to this song.') }}
				</p>

				<!-- Staged deletions notice -->
				<div v-if="pendingDeleteFiles.length > 0" :class="$style.deletionWarning">
					<div :class="$style.warningText">
						<strong>{{ t('mobilesheetsmanager', 'Notice:') }}</strong>
						{{ t('mobilesheetsmanager', '{count} file(s) marked for removal will be permanently deleted from the song and moved to your Nextcloud trash bin when you click "Save and Close".', { count: pendingDeleteFiles.length }) }}
					</div>
					<ul :class="$style.pendingList">
						<li v-for="file in pendingDeleteFiles" :key="file.id" :class="$style.pendingItem">
							<span :class="$style.pendingName">{{ getFileName(file.path) }}</span>
							<NcButton
								type="button"
								variant="tertiary"
								size="small"
								@click="restoreDeletedFile(file)">
								{{ t('mobilesheetsmanager', 'Restore') }}
							</NcButton>
						</li>
					</ul>
				</div>

				<!-- Staged additions notice -->
				<div v-if="pendingAdditions.length > 0" :class="$style.additionNotice">
					<div :class="$style.warningText">
						<strong>{{ t('mobilesheetsmanager', 'Notice:') }}</strong>
						{{ t('mobilesheetsmanager', '{count} file(s) will be added to the song when you click "Save and Close".', { count: pendingAdditions.length }) }}
					</div>
					<ul :class="$style.pendingList">
						<li v-for="addition in pendingAdditions" :key="addition.key" :class="$style.pendingAdditionItem">
							<span :class="$style.pendingAdditionText">
								{{ addition.name }}
								<span :class="$style.pendingAdditionHint">{{ addition.hint }}</span>
							</span>
							<NcButton
								type="button"
								variant="tertiary"
								size="small"
								@click="addition.remove()">
								{{ t('mobilesheetsmanager', 'Remove') }}
							</NcButton>
						</li>
					</ul>
				</div>

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
				<NcButton
					variant="primary"
					type="submit"
					:disabled="isSaving">
					<template v-if="isSaving">
						<NcLoadingIcon :size="16" />
					</template>
					{{ t('mobilesheetsmanager', 'Save and Close') }}
				</NcButton>
			</div>
		</form>

		<!-- Nextcloud File Choice Modal (Copy vs Move) -->
		<NcDialog
			v-if="isAttachDialogOpen"
			:name="t('mobilesheetsmanager', 'Attach Nextcloud PDF')"
			@close="isAttachDialogOpen = false">
			<p>
				{{ t('mobilesheetsmanager', 'Attach {file} to this song?', { file: selectedNextcloudNode?.basename }) }}
			</p>
			<div :class="$style.radioGroup">
				<label :class="$style.radioLabel">
					<input
						v-model="attachAction"
						type="radio"
						name="attachMode"
						value="copy">
					{{ t('mobilesheetsmanager', 'Copy file to library folder (default)') }}
				</label>
				<label :class="$style.radioLabel">
					<input
						v-model="attachAction"
						type="radio"
						name="attachMode"
						value="move">
					{{ t('mobilesheetsmanager', 'Move file to library folder') }}
				</label>
			</div>
			<p>{{ t('mobilesheetsmanager', 'The file is added to the song when you save it.') }}</p>
			<div :class="$style.dialogActions">
				<NcButton @click="isAttachDialogOpen = false">
					{{ t('mobilesheetsmanager', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					@click="stageAttachNextcloudFile">
					{{ t('mobilesheetsmanager', 'Attach File') }}
				</NcButton>
			</div>
		</NcDialog>
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

.fileItem {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 16px;
	padding: 8px 12px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	min-width: 0;
	box-sizing: border-box;
}

.fileInfo {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1 1 0;
	min-width: 0;
	overflow: hidden;
}

.fileName {
	font-weight: 600;
	font-size: 0.95rem;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.filePathText {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.deleteButton {
	flex-shrink: 0;
	min-width: 70px;
	background-color: var(--color-error, #e9322d) !important;
	color: var(--color-primary-text, #ffffff) !important;
	border-color: transparent !important;
}

.deleteButton:hover {
	opacity: 0.9;
}

.deletionWarning {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	background: var(--color-background-dark);
	border-left: 4px solid var(--color-warning);
	border-radius: var(--border-radius, 4px);
}

.warningText {
	font-size: 0.9rem;
}

.additionNotice {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	background-color: var(--color-background-dark);
	border-left: 4px solid var(--color-primary);
	border-radius: var(--border-radius, 4px);
}

.pendingAdditionItem {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	font-size: 0.85rem;
}

.pendingAdditionText {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
}

.pendingAdditionHint {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.pendingList {
	margin: 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.pendingItem {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
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

.radioGroup {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin: 16px 0;
}

.radioLabel {
	display: flex;
	align-items: center;
	gap: 8px;
	cursor: pointer;
	font-size: 0.95rem;
}

.dialogActions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 20px;
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
