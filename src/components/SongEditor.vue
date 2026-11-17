<script setup lang="ts">
import type { Arranger, Artist, Book, Collection, Composer, CreatedSongData, CustomGroup, Genre, Instrument, Key, LibraryData, Signature, Song, SongFile, SongPrefill, SourceType, Year } from '../types/song'

import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { apiPost } from '../api/client'
import { describeRequestFailure } from '../utils/api'
import { createEntityTag, createNamedTag, matchSelected, prefillByName, resolveSongEntityIds } from './song-editor/entity-utils'
import { useSongEditorFiles } from './song-editor/useSongEditorFiles'
import { ENTITY_COLUMNS, type SongEntityProp } from './songs/song-view-columns'
import { computed, ref, watch, type Ref } from 'vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import ContentSaveOutlineIcon from 'vue-material-design-icons/ContentSaveOutline.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import History from 'vue-material-design-icons/History.vue'
import AttachNextcloudFileDialog from './song-editor/AttachNextcloudFileDialog.vue'
import SongFileSection from './song-editor/SongFileSection.vue'
import SongMetadataFields from './song-editor/SongMetadataFields.vue'
import DeleteSongDialog from './DeleteSongDialog.vue'
import SongHistoryModal from './SongHistoryModal.vue'
import SongPageOrderModal from './song-pdf/SongPageOrderModal.vue'

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
		arrangers?: Arranger[]
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
		arrangers: () => [],
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

const isNewSong = computed(() => !props.song)

const saveActionLabel = computed(() => (isNewSong.value
	? t('sheetmusic', 'Create song')
	: t('sheetmusic', 'Save and Close')))

const sourceSong = computed(() => props.song ?? props.duplicateSong)

const createComposerTag = createNamedTag(props.composers)
const createBookTag = createEntityTag(props.books, (book) => book.title, (title) => ({ title }))
const createCollectionTag = createNamedTag(props.collections)
const createArtistTag = createNamedTag(props.artists)
const createGenreTag = createNamedTag(props.genres)
const createKeyTag = createNamedTag(props.keys)
const createCustomGroupTag = createNamedTag(props.customGroups)
const createYearTag = createNamedTag(props.years)
const createSignatureTag = createNamedTag(props.signatures)
const createSourceTypeTag = createNamedTag(props.sourceTypes)
const createInstrumentTag = createNamedTag(props.instruments)
const createArrangerTag = createNamedTag(props.arrangers)

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
const selectedGenres = ref<Array<Genre | { name: string, id?: number }>>(matchSelected(props.genres, sourceSong.value?.genres))
const selectedKeys = ref<Array<Key | { name: string, id?: number }>>(matchSelected(props.keys, sourceSong.value?.keys))
const selectedCustomGroups = ref<Array<CustomGroup | { name: string, id?: number }>>(matchSelected(props.customGroups, sourceSong.value?.customGroups))
const selectedYears = ref<Array<Year | { name: string, id?: number }>>(matchSelected(props.years, sourceSong.value?.years))
const selectedSignatures = ref<Array<Signature | { name: string, id?: number }>>(matchSelected(props.signatures, sourceSong.value?.signatures))
const selectedSourceTypes = ref<Array<SourceType | { name: string, id?: number }>>(matchSelected(props.sourceTypes, sourceSong.value?.sourceTypes))
const selectedInstruments = ref<Array<Instrument | { name: string, id?: number }>>(matchSelected(props.instruments, sourceSong.value?.instruments))
const selectedArrangers = ref<Array<Arranger | { name: string, id?: number }>>(matchSelected(props.arrangers, sourceSong.value?.arrangers))

const opus = ref<string>(sourceSong.value?.opus ?? '')
const catalogNumber = ref<string>(sourceSong.value?.catalogNumber ?? '')
const songNumber = ref<string>(sourceSong.value?.songNumber ?? '')
const stars = ref<number | null>(sourceSong.value?.stars || null)
const difficulty = ref<number | null>(sourceSong.value?.difficulty || null)
const duration = ref<number | null>(sourceSong.value?.duration || null)
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

const isSaving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const fileSection = ref<{ openUploadPicker: () => void }>()
const isHistoryOpen = ref(false)
const isPageOrderModalOpen = ref(false)
const selectedPageOrderFile = ref<SongFile | null>(null)
const isDeleteDialogOpen = ref(false)

const {
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
} = useSongEditorFiles({
	getSong: () => props.song,
	getLibraryId: () => props.libraryId,
	getTitle: () => title.value,
	importedFile: props.importedFile,
	importedNextcloudNode: props.importedNextcloudNode,
	onError: (msg) => { errorMessage.value = msg },
	onSuccess: (msg) => { successMessage.value = msg },
})

function openPageOrderModal(file: SongFile) {
	selectedPageOrderFile.value = file
	isPageOrderModalOpen.value = true
}

function handlePageOrderSaved(data: LibraryData, updatedFile: SongFile) {
	updateFilePageOrder(updatedFile)
	emit('updated', data)
}

function handleReverted(data: LibraryData) {
	emit('updated', data)
}

const selectedEntitiesByProp: Record<SongEntityProp, Ref<Array<{ id?: number, name?: string, title?: string }>>> = {
	composers: selectedComposers,
	books: selectedBooks,
	collections: selectedCollections,
	artists: selectedArtists,
	genres: selectedGenres,
	keys: selectedKeys,
	customGroups: selectedCustomGroups,
	years: selectedYears,
	signatures: selectedSignatures,
	sourceTypes: selectedSourceTypes,
	instruments: selectedInstruments,
	arrangers: selectedArrangers,
}

watch(
	sourceSong,
	(newSong, previousSong) => {
		title.value = newSong?.title ?? ''
		for (const col of ENTITY_COLUMNS) {
			selectedEntitiesByProp[col.songProp].value = matchSelected(
				(props[col.songProp] ?? []) as Array<{ id: number }>,
				newSong?.[col.songProp] as Array<{ id: number }> | undefined,
			)
		}
		opus.value = newSong?.opus ?? ''
		catalogNumber.value = newSong?.catalogNumber ?? ''
		songNumber.value = newSong?.songNumber ?? ''
		stars.value = newSong?.stars || null
		difficulty.value = newSong?.difficulty || null
		duration.value = newSong?.duration || null
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

		syncFilesOnSongChange(newSong, previousSong)
	},
)

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

	const existingSong = props.song
	const detachedFileCount = existingSong ? pendingDetachFiles.value.length : 0

	try {
		const pendingRemovals = getPendingRemovalRequests(existingSong)
		if (pendingRemovals.length === 1) {
			await pendingRemovals[0]()
		} else if (pendingRemovals.length > 1) {
			await Promise.all(pendingRemovals.map((runRemoval) => runRemoval()))
		}

		const entityIds = await resolveSongEntityIds(
			ENTITY_COLUMNS.map((col) => ({
				idField: col.idField,
				endpoint: col.endpoint as any,
				labelField: col.nameKey,
				selected: selectedEntitiesByProp[col.songProp].value,
				options: (props[col.songProp] ?? []) as Array<{ id: number, name?: string, title?: string }>,
			})),
			async (endpoint, labelField, label) => {
				const response = await apiPost(endpoint, {
					libraryId: props.libraryId,
					[labelField]: label,
				})
				return response.data.ocs.data.id
			},
		)

		const payload = {
			libraryId: props.libraryId,
			title: title.value.trim(),
			...entityIds,
			opus: opus.value.trim(),
			catalogNumber: catalogNumber.value.trim(),
			songNumber: songNumber.value.trim(),
			stars: Number(stars.value) > 0 ? Number(stars.value) : null,
			difficulty: Number(difficulty.value) > 0 ? Number(difficulty.value) : null,
			duration: Number(duration.value) > 0 ? Number(duration.value) : null,
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
		const songId: number | undefined = existingSong?.id ?? (data as CreatedSongData).createdSongId

		for (const runAddition of getPendingAdditionRequests(songId)) {
			data = (await runAddition()).data.ocs.data as LibraryData
		}

		const songTitle = title.value?.trim()
		if (detachedFileCount > 0) {
			successMessage.value = songTitle
				? t('sheetmusic', 'Song "{title}" updated successfully. {count} file(s) detached and kept in storage.', { title: songTitle, count: detachedFileCount }, { escape: false })
				: t('sheetmusic', 'Song updated successfully. {count} file(s) detached and kept in storage.', { count: detachedFileCount }, { escape: false })
			showSuccess(successMessage.value)
		} else {
			successMessage.value = isNewSong.value
				? songTitle
					? t('sheetmusic', 'Song "{title}" created successfully.', { title: songTitle }, { escape: false })
					: t('sheetmusic', 'Song created successfully.')
				: songTitle
					? t('sheetmusic', 'Song "{title}" updated successfully.', { title: songTitle }, { escape: false })
					: t('sheetmusic', 'Song updated successfully.')
			showSuccess(successMessage.value)
		}
		if (closeAfter) {
			emit('saved', data, songId)
		} else {
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
				v-model:selected-arrangers="selectedArrangers"
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
				:arrangers="arrangers"
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
				:create-instrument-tag="createInstrumentTag"
				:create-arranger-tag="createArrangerTag" />

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
				@edit-page-order="openPageOrderModal"
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

		<!-- Page Order Modal -->
		<SongPageOrderModal
			v-if="song && isPageOrderModalOpen"
			v-model:open="isPageOrderModalOpen"
			:file="selectedPageOrderFile"
			:song-id="song.id"
			:library-id="libraryId"
			@saved="handlePageOrderSaved" />
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
	color: var(--color-text-success);
	margin: 8px 0;
}

.dialogActions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	margin-top: 24px;
}
</style>
