<template>
	<NcDialog v-if="open"
	          :name="t('sheetmusic', 'Import Shared File')"
	          size="normal"
	          @close="!isSubmitting && emit('close')">
		<div :class="$style.container">
			<div :class="$style.fileSummary">
				<p :class="$style.sectionLabel">{{ t('sheetmusic', 'Shared files') }}</p>
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

			<template v-else>
				<div :class="$style.fieldGroup">
					<label :for="librarySelectId" :class="$style.sectionLabel">
						{{ t('sheetmusic', 'Library') }}
					</label>
					<select :id="librarySelectId"
					        v-model.number="targetLibraryId"
					        :disabled="isSubmitting"
					        :class="$style.select">
						<option v-for="lib in writableLibraries" :key="lib.id" :value="lib.id">
							{{ lib.name }}
						</option>
					</select>
				</div>

				<div :class="$style.fieldGroup">
					<span :class="$style.sectionLabel">{{ t('sheetmusic', 'Destination') }}</span>
					<div :class="$style.modeOptions" role="radiogroup" :aria-label="t('sheetmusic', 'Destination')">
						<label :class="[$style.modeOption, mode === 'new-song' && $style.modeOptionActive]">
							<input v-model="mode" type="radio" value="new-song" :disabled="isSubmitting">
							<span>{{ t('sheetmusic', 'New song') }}</span>
						</label>
						<label :class="[$style.modeOption, mode === 'existing-song' && $style.modeOptionActive]">
							<input v-model="mode" type="radio" value="existing-song" :disabled="isSubmitting">
							<span>{{ t('sheetmusic', 'Add to existing song') }}</span>
						</label>
						<label :class="[$style.modeOption, mode === 'setlist-file' && $style.modeOptionActive]">
							<input v-model="mode" type="radio" value="setlist-file" :disabled="isSubmitting">
							<span>{{ t('sheetmusic', 'Annotation of setlist') }}</span>
						</label>
					</div>
				</div>

				<div v-if="mode === 'new-song'" :class="$style.fieldGroup">
					<NcTextField v-model="newSongTitle"
					             :label="t('sheetmusic', 'Song title')"
					             :disabled="isSubmitting"
					             required />
				</div>

				<div v-else-if="mode === 'existing-song'" :class="$style.fieldGroup">
					<NcTextField v-model="songSearchQuery"
					             :label="t('sheetmusic', 'Filter songs')"
					             :disabled="isSubmitting || isLoadingTargets" />
					<label :for="songSelectId" :class="$style.subLabel">
						{{ t('sheetmusic', 'Select song') }}
					</label>
					<div v-if="isLoadingTargets" :class="$style.loadingRow">
						<NcLoadingIcon :size="20" />
						<span>{{ t('sheetmusic', 'Loading songs…') }}</span>
					</div>
					<select v-else
					        :id="songSelectId"
					        v-model.number="selectedSongId"
					        :disabled="isSubmitting || filteredSongs.length === 0"
					        :class="$style.select">
						<option :value="-1" disabled>{{ t('sheetmusic', 'Choose a song…') }}</option>
						<option v-for="song in filteredSongs" :key="song.id" :value="song.id">
							{{ song.title }}
						</option>
					</select>
				</div>

				<div v-else-if="mode === 'setlist-file'" :class="$style.fieldGroup">
					<label :for="setlistSelectId" :class="$style.sectionLabel">
						{{ t('sheetmusic', 'Select setlist') }}
					</label>
					<div v-if="isLoadingTargets" :class="$style.loadingRow">
						<NcLoadingIcon :size="20" />
						<span>{{ t('sheetmusic', 'Loading setlists…') }}</span>
					</div>
					<select v-else
					        :id="setlistSelectId"
					        v-model.number="selectedSetlistId"
					        :disabled="isSubmitting || setlists.length === 0"
					        :class="$style.select">
						<option :value="-1" disabled>{{ t('sheetmusic', 'Choose a setlist…') }}</option>
						<option v-for="setlist in setlists" :key="setlist.id" :value="setlist.id">
							{{ setlist.name }}
						</option>
					</select>
				</div>
			</template>

			<p v-if="errorMessage" :class="$style.error" role="alert">{{ errorMessage }}</p>

			<div :class="$style.actions">
				<NcButton :disabled="isSubmitting" @click="emit('close')">
					{{ t('sheetmusic', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="!canSubmit" @click="submitImport">
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

const librarySelectId = 'sheetmusic-share-library'
const songSelectId = 'sheetmusic-share-song'
const setlistSelectId = 'sheetmusic-share-setlist'

const mode = ref<ShareTargetMode>('new-song')
const targetLibraryId = ref<number>(-1)
const newSongTitle = ref<string>('')
const songSearchQuery = ref<string>('')
const selectedSongId = ref<number>(-1)
const selectedSetlistId = ref<number>(-1)
const songs = ref<Song[]>([])
const setlists = ref<Setlist[]>([])
const isLoadingTargets = ref<boolean>(false)
const isSubmitting = ref<boolean>(false)
const errorMessage = ref<string>('')

const writableLibraries = computed(() =>
	props.libraries.filter(lib => lib.canWrite !== false && !lib.isShared),
)

const filteredSongs = computed(() => {
	const q = songSearchQuery.value.trim().toLowerCase()
	if (!q) {
		return songs.value
	}
	return songs.value.filter(song => song.title.toLowerCase().includes(q))
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
	if (libraryId < 0) {
		songs.value = []
		setlists.value = []
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
	errorMessage.value = ''
	songSearchQuery.value = ''
	const defaultTitle = props.initialTitle?.trim() || (props.files[0] ? defaultTitleFromFilename(props.files[0].name) : '')
	newSongTitle.value = defaultTitle
	const preferredLib = writableLibraries.value.find(lib => lib.id === props.selectedLibraryId) ?? writableLibraries.value[0]
	targetLibraryId.value = preferredLib?.id ?? -1
	if (targetLibraryId.value >= 0) {
		void loadTargetsForLibrary(targetLibraryId.value)
	}
}, { immediate: true })

watch(targetLibraryId, (libraryId, prevId) => {
	if (props.open && libraryId >= 0 && libraryId !== prevId) {
		void loadTargetsForLibrary(libraryId)
	}
})

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

.sectionLabel {
	display: block;
	font-weight: 600;
	margin-bottom: 6px;
}

.subLabel {
	display: block;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	margin: 8px 0 4px;
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

.select {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
}

.modeOptions {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.modeOption {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
}

.modeOptionActive {
	border-color: var(--color-primary-element);
	background: var(--color-background-hover);
}

.loadingRow {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 0;
	color: var(--color-text-maxcontrast);
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
