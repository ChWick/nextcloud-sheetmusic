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

			<div v-else-if="step === 2" :class="$style.fieldGroup">
				<span :class="$style.sectionLabel">{{ t('sheetmusic', 'Where do you want to add this file?') }}</span>
				<div :class="$style.optionCards" role="radiogroup" :aria-label="t('sheetmusic', 'Destination')">
					<label v-for="opt in destinationOptions"
					       :key="opt.value"
					       :class="[$style.optionCard, mode === opt.value && $style.optionCardActive]">
						<input v-model="mode" type="radio" :value="opt.value">
						<span :class="$style.optionText">{{ opt.label }}</span>
					</label>
				</div>
			</div>

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
				<NcButton v-else :disabled="isSubmitting" @click="goBack">
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
import { translate as t } from '@nextcloud/l10n'
import {
	useShareTargetImport,
	type ShareImportResult,
	type ShareTargetMode,
} from '../composables/useShareTargetImport'
import type { Library } from '../types/song'
import { defaultTitleFromFilename } from '../utils/shareTarget'
import FilterableOptionList, { type FilterableOptionItem } from './share-target/FilterableOptionList.vue'

export type { ShareImportResult, ShareTargetMode }

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

const {
	songs,
	setlists,
	isLoadingTargets,
	isSubmitting,
	errorMessage,
	resetTargets,
	loadTargets,
	executeImport,
} = useShareTargetImport()

const step = ref<1 | 2 | 3>(1)
const mode = ref<ShareTargetMode>('new-song')
const targetLibraryId = ref(-1)
const newSongTitle = ref('')
const selectedSongId = ref(-1)
const selectedSetlistId = ref(-1)

const destinationOptions = computed<Array<{ value: ShareTargetMode; label: string }>>(() => [
	{ value: 'new-song', label: t('sheetmusic', 'New song') },
	{ value: 'existing-song', label: t('sheetmusic', 'Add to existing song') },
	{ value: 'setlist-file', label: t('sheetmusic', 'Annotation of setlist') },
])

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
	setlists.value.map(setlist => ({ id: setlist.id, label: setlist.name })),
)

const canAdvance = computed(() => step.value !== 1 || targetLibraryId.value >= 0)

const canSubmit = computed(() => {
	if (isSubmitting.value || props.files.length === 0 || targetLibraryId.value < 0) {
		return false
	}
	if (mode.value === 'new-song') return newSongTitle.value.trim().length > 0
	if (mode.value === 'existing-song') return selectedSongId.value > 0
	return selectedSetlistId.value > 0
})

function formatFileSize(bytes: number): string {
	if (bytes < 1024) return `${bytes} B`
	if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
	return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

watch(() => props.open, (isOpen) => {
	if (!isOpen) return
	step.value = 1
	mode.value = 'new-song'
	resetTargets()
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
	if (step.value === 3) step.value = 2
	else if (step.value === 2) step.value = 1
}

async function goNext() {
	errorMessage.value = ''
	if (step.value === 1 && targetLibraryId.value >= 0) {
		step.value = 2
	} else if (step.value === 2) {
		step.value = 3
		if (mode.value !== 'new-song') {
			const defaults = await loadTargets(targetLibraryId.value)
			if (defaults) {
				selectedSongId.value = defaults.firstSongId
				selectedSetlistId.value = defaults.firstSetlistId
			}
		}
	}
}

async function submitImport() {
	if (!canSubmit.value) return
	const result = await executeImport({
		mode: mode.value,
		libraryId: targetLibraryId.value,
		files: props.files,
		newSongTitle: newSongTitle.value,
		songId: selectedSongId.value,
		setlistId: selectedSetlistId.value,
	})
	if (result) {
		emit('imported', result)
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
