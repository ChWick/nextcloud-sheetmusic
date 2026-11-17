<template>
	<NcDialog v-if="open"
	          :name="t('sheetmusic', 'Import Shared File')"
	          size="normal"
	          @close="!isSubmitting && emit('close')">
		<div :class="$style.container">
			<div :class="$style.fileSummary">
				<div :class="$style.summaryHeader">
					<span :class="$style.sectionLabel">{{ t('sheetmusic', 'Shared files') }}</span>
					<span v-if="writableLibraries.length > 0" :class="$style.stepBadge">
						{{ t('sheetmusic', 'Step {step} of 3', { step }, { escape: false }) }}
					</span>
				</div>
				<ul :class="$style.fileList">
					<li v-for="(file, idx) in files" :key="`${file.name}-${idx}`" :class="$style.fileItem">
						<span :class="$style.fileName">{{ file.name }}</span>
						<span :class="$style.fileSize">{{ formatFileSize(file.size) }}</span>
					</li>
				</ul>
			</div>

			<div v-if="writableLibraries.length === 0" :class="$style.error" role="alert">
				{{ t('sheetmusic', 'No writable library is available to import shared files.') }}
			</div>

			<!-- Step 1: Select library -->
			<div v-else-if="step === 1" :class="$style.fieldGroup">
				<span :class="$style.sectionLabel">{{ t('sheetmusic', 'Select library') }}</span>
				<div :class="$style.optionCards" role="radiogroup" :aria-label="t('sheetmusic', 'Select library')">
					<label v-for="lib in writableLibraries"
					       :key="lib.id"
					       :class="[$style.optionCard, targetLibraryId === lib.id && $style.optionCardActive]">
						<input v-model.number="targetLibraryId" type="radio" :value="lib.id">
						<span :class="$style.optionText">{{ lib.path }}</span>
					</label>
				</div>
			</div>

			<!-- Step 2: Choose destination -->
			<div v-else-if="step === 2" :class="$style.fieldGroup">
				<span :class="$style.sectionLabel">{{ t('sheetmusic', 'Where do you want to add this file?') }}</span>
				<div :class="$style.optionCards" role="radiogroup" :aria-label="t('sheetmusic', 'Destination')">
					<label :class="[$style.optionCard, mode === 'new-song' && $style.optionCardActive]">
						<input v-model="mode" type="radio" value="new-song">
						<span :class="$style.optionText">{{ t('sheetmusic', 'New song') }}</span>
					</label>
					<label :class="[$style.optionCard, mode === 'existing-song' && $style.optionCardActive]">
						<input v-model="mode" type="radio" value="existing-song">
						<span :class="$style.optionText">{{ t('sheetmusic', 'Add to existing song') }}</span>
					</label>
					<label :class="[$style.optionCard, mode === 'setlist-file' && $style.optionCardActive]">
						<input v-model="mode" type="radio" value="setlist-file">
						<span :class="$style.optionText">{{ t('sheetmusic', 'Annotation of setlist') }}</span>
					</label>
				</div>
			</div>

			<!-- Step 3: Sub-options -->
			<div v-else-if="step === 3" :class="$style.fieldGroup">
				<NcTextField v-if="mode === 'new-song'"
				             v-model="newSongTitle"
				             :label="t('sheetmusic', 'Song title')"
				             :disabled="isSubmitting"
				             required />

				<FilterableOptionList v-else-if="mode === 'existing-song'"
				                      v-model="selectedSongId"
				                      :items="songOptions"
				                      :filter-label="t('sheetmusic', 'Filter songs')"
				                      :filter-placeholder="t('sheetmusic', 'Search by title or composer…')"
				                      :list-label="t('sheetmusic', 'Select song')"
				                      :empty-text="t('sheetmusic', 'No matching songs found.')"
				                      :loading-text="t('sheetmusic', 'Loading songs…')"
				                      :loading="isLoadingTargets"
				                      :disabled="isSubmitting" />

				<FilterableOptionList v-else
				                      v-model="selectedSetlistId"
				                      :items="setlistOptions"
				                      :filter-label="t('sheetmusic', 'Filter setlists')"
				                      :filter-placeholder="t('sheetmusic', 'Search setlists…')"
				                      :list-label="t('sheetmusic', 'Select setlist')"
				                      :empty-text="t('sheetmusic', 'No matching setlists found.')"
				                      :loading-text="t('sheetmusic', 'Loading setlists…')"
				                      :loading="isLoadingTargets"
				                      :disabled="isSubmitting" />
			</div>

			<p v-if="errorMessage" :class="$style.error" role="alert">{{ errorMessage }}</p>

			<div :class="$style.actions">
				<NcButton v-if="step === 1 || writableLibraries.length === 0"
				          :disabled="isSubmitting"
				          @click="emit('close')">
					{{ t('sheetmusic', 'Cancel') }}
				</NcButton>
				<NcButton v-else
				          :disabled="isSubmitting"
				          @click="goBack">
					{{ t('sheetmusic', 'Back') }}
				</NcButton>

				<NcButton v-if="writableLibraries.length > 0 && step < 3"
				          variant="primary"
				          :disabled="!canAdvance"
				          @click="goNext">
					{{ t('sheetmusic', 'Next') }}
				</NcButton>
				<NcButton v-else-if="writableLibraries.length > 0"
				          variant="primary"
				          :disabled="!canSubmit"
				          @click="submitImport">
					<template v-if="isSubmitting" #icon>
						<NcLoadingIcon :size="18" />
					</template>
					{{ isSubmitting ? t('sheetmusic', 'Importing…') : t('sheetmusic', 'Import') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { NcButton, NcDialog, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { apiGet, apiPost } from '../api/client'
import { fetchSetlists, uploadSetlistFile } from '../api/setlists'
import type { Library, Setlist, Song } from '../types/song'
import { defaultTitleFromFilename } from '../utils/shareTarget'
import FilterableOptionList, { type FilterableOptionItem } from './share-target/FilterableOptionList.vue'

export type ShareTargetMode = 'new-song' | 'existing-song' | 'setlist-file'

export interface ShareImportResult {
	targetType: ShareTargetMode
	libraryId: number
	songId?: number
	setlistId?: number
}

const props = defineProps<{
	open: boolean
	files: File[]
	initialTitle?: string
	libraries: Library[]
	selectedLibraryId: number
}>()

const emit = defineEmits<{
	close: []
	imported: [result: ShareImportResult]
}>()

const step = ref<1 | 2 | 3>(1)
const mode = ref<ShareTargetMode>('new-song')
const targetLibraryId = ref<number>(-1)
const newSongTitle = ref<string>('')
const selectedSongId = ref<number>(-1)
const selectedSetlistId = ref<number>(-1)
const songs = ref<Song[]>([])
const setlists = ref<Setlist[]>([])
const loadedLibraryId = ref<number>(-1)
const isLoadingTargets = ref<boolean>(false)
const isSubmitting = ref<boolean>(false)
const errorMessage = ref<string>('')

const writableLibraries = computed(() =>
	props.libraries.filter(lib => lib.canWrite !== false && !lib.isShared),
)

const songOptions = computed<FilterableOptionItem[]>(() =>
	songs.value.map(song => ({
		id: song.id,
		label: song.title,
		subtitle: song.composers?.map(c => c.name).filter(Boolean).join(', ') || undefined,
	})),
)

const setlistOptions = computed<FilterableOptionItem[]>(() =>
	setlists.value.map(setlist => ({
		id: setlist.id,
		label: setlist.name,
	})),
)

const canAdvance = computed(() => {
	if (step.value === 1) {
		return targetLibraryId.value >= 0
	}
	return true
})

const canSubmit = computed(() => {
	if (isSubmitting.value || props.files.length === 0 || targetLibraryId.value < 0) {
		return false
	}
	if (mode.value === 'new-song') {
		return newSongTitle.value.trim().length > 0
	}
	if (mode.value === 'existing-song') {
		return selectedSongId.value > 0
	}
	return selectedSetlistId.value > 0
})

function formatFileSize(bytes: number): string {
	if (bytes < 1024) return `${bytes} B`
	if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
	return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

async function loadTargetsForLibrary(libraryId: number) {
	if (libraryId < 0 || loadedLibraryId.value === libraryId) {
		return
	}
	isLoadingTargets.value = true
	errorMessage.value = ''
	try {
		const [songsRes, setlistList] = await Promise.all([
			apiGet('/api/songs', { queryParams: { id: libraryId } }),
			fetchSetlists(libraryId),
		])
		songs.value = (songsRes.data.ocs.data.songs ?? []) as Song[]
		setlists.value = setlistList
		loadedLibraryId.value = libraryId
		selectedSongId.value = songs.value[0]?.id ?? -1
		selectedSetlistId.value = setlists.value[0]?.id ?? -1
	} catch {
		errorMessage.value = t('sheetmusic', 'Could not load songs and setlists for the selected library.')
	} finally {
		isLoadingTargets.value = false
	}
}

watch(() => props.open, (isOpen) => {
	if (!isOpen) return
	step.value = 1
	mode.value = 'new-song'
	errorMessage.value = ''
	loadedLibraryId.value = -1
	songs.value = []
	setlists.value = []
	selectedSongId.value = -1
	selectedSetlistId.value = -1
	newSongTitle.value = props.initialTitle?.trim() || (props.files[0] ? defaultTitleFromFilename(props.files[0].name) : '')

	if (writableLibraries.value.length === 1) {
		targetLibraryId.value = writableLibraries.value[0].id
	} else {
		const preferredLib = writableLibraries.value.find(lib => lib.id === props.selectedLibraryId)
		targetLibraryId.value = preferredLib?.id ?? -1
	}
}, { immediate: true })

function goBack() {
	errorMessage.value = ''
	if (step.value === 3) {
		step.value = 2
	} else if (step.value === 2) {
		step.value = 1
	}
}

function goNext() {
	errorMessage.value = ''
	if (step.value === 1 && targetLibraryId.value >= 0) {
		step.value = 2
	} else if (step.value === 2) {
		step.value = 3
		if (mode.value !== 'new-song') {
			void loadTargetsForLibrary(targetLibraryId.value)
		}
	}
}

async function uploadFilesToSong(libraryId: number, songId: number) {
	for (const file of props.files) {
		const formData = new FormData()
		formData.append('file', file)
		await apiPost('/api/libraries/{libraryId}/songs/{id}/file/upload', formData as any, {
			pathParams: { libraryId, id: songId },
		})
	}
}

async function submitImport() {
	if (!canSubmit.value) return
	isSubmitting.value = true
	errorMessage.value = ''

	try {
		const libraryId = targetLibraryId.value
		if (mode.value === 'new-song') {
			const createRes = await apiPost('/api/songs', {
				libraryId,
				title: newSongTitle.value.trim(),
			} as any)
			const createdData = createRes.data.ocs.data as any
			const newSongId = Number(createdData.createdSongId ?? createdData.id)
			if (!newSongId) {
				throw new Error(t('sheetmusic', 'Failed to create song.'))
			}
			await uploadFilesToSong(libraryId, newSongId)
			showSuccess(t('sheetmusic', 'Imported shared file into new song.'))
			emit('imported', { targetType: 'new-song', libraryId, songId: newSongId })
		} else if (mode.value === 'existing-song') {
			const songId = selectedSongId.value
			await uploadFilesToSong(libraryId, songId)
			showSuccess(t('sheetmusic', 'Added shared file to song.'))
			emit('imported', { targetType: 'existing-song', libraryId, songId })
		} else {
			const setlistId = selectedSetlistId.value
			for (const file of props.files) {
				await uploadSetlistFile(libraryId, setlistId, file)
			}
			showSuccess(t('sheetmusic', 'Attached shared file to setlist.'))
			emit('imported', { targetType: 'setlist-file', libraryId, setlistId })
		}
	} catch (error: any) {
		errorMessage.value = error?.response?.data?.ocs?.meta?.message
			|| error?.message
			|| t('sheetmusic', 'Failed to import shared file.')
	} finally {
		isSubmitting.value = false
	}
}
</script>

<style module>
.container {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.fileSummary {
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 10px 12px;
}

.summaryHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	margin-bottom: 6px;
}

.sectionLabel {
	display: block;
	font-weight: 600;
	margin-bottom: 6px;
}

.summaryHeader .sectionLabel {
	margin-bottom: 0;
}

.stepBadge {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.fileList {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.fileItem {
	display: flex;
	justify-content: space-between;
	gap: 8px;
	font-size: 0.9em;
}

.fileName {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.fileSize {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.fieldGroup {
	display: flex;
	flex-direction: column;
}

.optionCards {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.optionCard {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
}

.optionCardActive {
	border-color: var(--color-primary-element);
	background: var(--color-background-hover);
}

.optionText {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.error {
	color: var(--color-error);
	margin: 0;
}

.actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
