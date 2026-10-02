<script setup lang="ts">
import type { Artist, Book, Collection, Composer, Genre, IntegrityIssue, Key, LibraryData, Song, SongFile } from '../../types/song'
import { computed, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import MergeIcon from 'vue-material-design-icons/Merge.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import { getFileName, openFileExternally } from '../../utils/file'

const props = defineProps<{
	open: boolean
	issue: IntegrityIssue
	libraryData: LibraryData
	isMerging?: boolean
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	merge: [payload: {
		keepId: number
		droppedId: number
		merged: {
			title: string
			composerIds: number[]
			bookIds: number[]
			collectionIds: number[]
			artistIds: number[]
			genreIds: number[]
			keyIds: number[]
			customGroupIds: number[]
			yearIds: number[]
			signatureIds: number[]
			sourceTypeIds: number[]
			stars: number
			difficulty: number
			duration: number
			keywords: string
			custom: string
			custom2: string
			sortTitle: string
		}
	}]
}>()

function songById(id: number): Song | undefined {
	return props.libraryData?.songs.find(s => s.id === id)
}

function entityName<T extends { id: number; name?: string; title?: string; year?: number }>(e: T): string {
	return e.name ?? e.title ?? String((e as { year?: number }).year ?? e.id)
}

function toSelectOptions<T extends { id: number; name?: string; title?: string; year?: number }>(items: T[] = []): { id: number; label: string }[] {
	return items.map(e => ({ id: e.id, label: entityName(e) }))
}

const songIds = computed<number[]>(() => props.issue.details?.songIds ?? [])
const songA = computed<Song | undefined>(() => songById(songIds.value[0]))
const songB = computed<Song | undefined>(() => songById(songIds.value[1]))

const keepId = ref<number>(songIds.value[0] ?? 0)
const droppedId = computed<number>(() => {
	const ids = songIds.value
	return ids.find(id => id !== keepId.value) ?? ids[1] ?? 0
})

// Merged fields
const mergedTitle = ref('')
const mergedComposers = ref<{ id: number; label: string }[]>([])
const mergedBooks = ref<{ id: number; label: string }[]>([])
const mergedCollections = ref<{ id: number; label: string }[]>([])
const mergedArtists = ref<{ id: number; label: string }[]>([])
const mergedGenres = ref<{ id: number; label: string }[]>([])
const mergedKeys = ref<{ id: number; label: string }[]>([])
const mergedKeywords = ref('')
const mergedSortTitle = ref('')

function sortedIds(entities?: { id: number }[]): number[] {
	const ids = (entities ?? []).map(e => e.id)
	ids.sort((a, b) => a - b)
	return ids
}

function areEntitiesEqual(a?: { id: number }[], b?: { id: number }[]): boolean {
	const idsA = sortedIds(a)
	const idsB = sortedIds(b)
	if (idsA.length !== idsB.length) return false
	return idsA.every((id, idx) => id === idsB[idx])
}

function prefillMerged() {
	const a = songA.value
	const b = songB.value
	if (!a || !b) {
		mergedTitle.value = a?.title ?? ''
		mergedComposers.value = toSelectOptions(a?.composers)
		mergedBooks.value = toSelectOptions(a?.books)
		mergedCollections.value = toSelectOptions(a?.collections)
		mergedArtists.value = toSelectOptions(a?.artists)
		mergedGenres.value = toSelectOptions(a?.genres)
		mergedKeys.value = toSelectOptions(a?.keys)
		mergedKeywords.value = a?.keywords ?? ''
		mergedSortTitle.value = a?.sortTitle ?? ''
		return
	}

	// Title: prefill if both agree, otherwise empty
	mergedTitle.value = (a.title.trim() === b.title.trim()) ? a.title : ''

	// Entity lists: prefill if both agree, otherwise empty
	mergedComposers.value = areEntitiesEqual(a.composers, b.composers) ? toSelectOptions(a.composers) : []
	mergedBooks.value = areEntitiesEqual(a.books, b.books) ? toSelectOptions(a.books) : []
	mergedCollections.value = areEntitiesEqual(a.collections, b.collections) ? toSelectOptions(a.collections) : []
	mergedArtists.value = areEntitiesEqual(a.artists, b.artists) ? toSelectOptions(a.artists) : []
	mergedGenres.value = areEntitiesEqual(a.genres, b.genres) ? toSelectOptions(a.genres) : []
	mergedKeys.value = areEntitiesEqual(a.keys, b.keys) ? toSelectOptions(a.keys) : []

	// Keywords
	mergedKeywords.value = ((a.keywords ?? '').trim() === (b.keywords ?? '').trim()) ? (a.keywords ?? '') : ''
	mergedSortTitle.value = ((a.sortTitle ?? '').trim() === (b.sortTitle ?? '').trim()) ? (a.sortTitle ?? '') : ''
}

watch(() => props.open, open => {
	if (open) {
		keepId.value = songIds.value[0] ?? 0
		prefillMerged()
	}
}, { immediate: true })

// Transfer field values from A or B
function copyTitle(from: 'A' | 'B') {
	const s = from === 'A' ? songA.value : songB.value
	if (s) mergedTitle.value = s.title
}

function copyComposers(from: 'A' | 'B') {
	const s = from === 'A' ? songA.value : songB.value
	if (s) mergedComposers.value = toSelectOptions(s.composers)
}

function copyBooks(from: 'A' | 'B') {
	const s = from === 'A' ? songA.value : songB.value
	if (s) mergedBooks.value = toSelectOptions(s.books)
}

function copyCollections(from: 'A' | 'B') {
	const s = from === 'A' ? songA.value : songB.value
	if (s) mergedCollections.value = toSelectOptions(s.collections)
}

function copyArtists(from: 'A' | 'B') {
	const s = from === 'A' ? songA.value : songB.value
	if (s) mergedArtists.value = toSelectOptions(s.artists)
}

function copyGenres(from: 'A' | 'B') {
	const s = from === 'A' ? songA.value : songB.value
	if (s) mergedGenres.value = toSelectOptions(s.genres)
}

function copyKeys(from: 'A' | 'B') {
	const s = from === 'A' ? songA.value : songB.value
	if (s) mergedKeys.value = toSelectOptions(s.keys)
}

function copyKeywords(from: 'A' | 'B') {
	const s = from === 'A' ? songA.value : songB.value
	if (s) mergedKeywords.value = s.keywords ?? ''
}

// Option lists
const composerOptions = computed(() => toSelectOptions(props.libraryData?.composers ?? []))
const bookOptions = computed(() => toSelectOptions(props.libraryData?.books ?? []))
const collectionOptions = computed(() => toSelectOptions(props.libraryData?.collections ?? []))
const artistOptions = computed(() => toSelectOptions(props.libraryData?.artists ?? []))
const genreOptions = computed(() => toSelectOptions(props.libraryData?.genres ?? []))
const keyOptions = computed(() => toSelectOptions(props.libraryData?.keys ?? []))

function formatList(items?: { name?: string; title?: string }[]): string {
	if (!items || items.length === 0) return t('mobilesheetsmanager', '(None)')
	return items.map(entityName).join(', ')
}

function submit() {
	emit('merge', {
		keepId: keepId.value,
		droppedId: droppedId.value,
		merged: {
			title: mergedTitle.value,
			composerIds: mergedComposers.value.map(e => e.id),
			bookIds: mergedBooks.value.map(e => e.id),
			collectionIds: mergedCollections.value.map(e => e.id),
			artistIds: mergedArtists.value.map(e => e.id),
			genreIds: mergedGenres.value.map(e => e.id),
			keyIds: mergedKeys.value.map(e => e.id),
			customGroupIds: [],
			yearIds: [],
			signatureIds: [],
			sourceTypeIds: [],
			stars: Math.max(songA.value?.stars ?? 0, songB.value?.stars ?? 0),
			difficulty: Math.max(songA.value?.difficulty ?? 0, songB.value?.difficulty ?? 0),
			duration: Math.max(songA.value?.duration ?? 0, songB.value?.duration ?? 0),
			keywords: mergedKeywords.value,
			custom: songA.value?.custom ?? songB.value?.custom ?? '',
			custom2: songA.value?.custom2 ?? songB.value?.custom2 ?? '',
			sortTitle: mergedSortTitle.value,
		},
	})
}
</script>

<template>
	<NcDialog
		v-if="open"
		:name="t('mobilesheetsmanager', 'Merge Duplicate Songs')"
		size="full"
		@close="emit('update:open', false)">

		<p :class="$style.hint">
			{{ t('mobilesheetsmanager', 'Choose which song ID to keep. Use arrows to copy values from Song A or Song B into the merged result. Fields pre-fill if both songs agree.') }}
		</p>

		<!-- Comparison Table / Grid Layout -->
		<div :class="$style.comparisonGrid">

			<!-- Column Headers -->
			<div :class="[$style.headerCol, $style.songLeft]">
				<strong>{{ t('mobilesheetsmanager', 'Song A') }}</strong>
				<span :class="$style.idBadge">ID {{ songA?.id }}</span>
				<NcCheckboxRadioSwitch
					:checked="keepId === (songA?.id ?? 0)"
					type="radio"
					name="keepId"
					:value="String(songA?.id)"
					@update:checked="keepId = songA?.id ?? 0">
					{{ t('mobilesheetsmanager', 'Keep ID') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div :class="[$style.headerCol, $style.songMerged]">
				<MergeIcon :size="18" />
				<strong>{{ t('mobilesheetsmanager', 'Merged Result') }}</strong>
				<span :class="$style.idBadge">Keeping ID {{ keepId }}</span>
			</div>

			<div :class="[$style.headerCol, $style.songRight]">
				<strong>{{ t('mobilesheetsmanager', 'Song B') }}</strong>
				<span :class="$style.idBadge">ID {{ songB?.id }}</span>
				<NcCheckboxRadioSwitch
					:checked="keepId === (songB?.id ?? 0)"
					type="radio"
					name="keepId"
					:value="String(songB?.id)"
					@update:checked="keepId = songB?.id ?? 0">
					{{ t('mobilesheetsmanager', 'Keep ID') }}
				</NcCheckboxRadioSwitch>
			</div>

			<!-- Row: Title -->
			<div :class="$style.cellA">
				<div :class="$style.fieldInfo">
					<span :class="$style.label">{{ t('mobilesheetsmanager', 'Title') }}</span>
					<span :class="$style.val">{{ songA?.title || '-' }}</span>
				</div>
				<NcButton type="tertiary" :title="t('mobilesheetsmanager', 'Use Song A Title')" @click="copyTitle('A')">
					<template #icon><ArrowRightIcon :size="16" /></template>
				</NcButton>
			</div>
			<div :class="$style.cellMerged">
				<NcTextField
					:label="t('mobilesheetsmanager', 'Title')"
					:value="mergedTitle"
					@update:value="mergedTitle = $event" />
			</div>
			<div :class="$style.cellB">
				<NcButton type="tertiary" :title="t('mobilesheetsmanager', 'Use Song B Title')" @click="copyTitle('B')">
					<template #icon><ArrowLeftIcon :size="16" /></template>
				</NcButton>
				<div :class="[$style.fieldInfo, $style.alignRight]">
					<span :class="$style.label">{{ t('mobilesheetsmanager', 'Title') }}</span>
					<span :class="$style.val">{{ songB?.title || '-' }}</span>
				</div>
			</div>

			<!-- Row: Composers -->
			<div :class="$style.cellA">
				<div :class="$style.fieldInfo">
					<span :class="$style.label">{{ t('mobilesheetsmanager', 'Composers') }}</span>
					<span :class="$style.val">{{ formatList(songA?.composers) }}</span>
				</div>
				<NcButton type="tertiary" :title="t('mobilesheetsmanager', 'Use Song A Composers')" @click="copyComposers('A')">
					<template #icon><ArrowRightIcon :size="16" /></template>
				</NcButton>
			</div>
			<div :class="$style.cellMerged">
				<label :class="$style.fieldLabel">{{ t('mobilesheetsmanager', 'Composers') }}</label>
				<NcSelect
					v-model="mergedComposers"
					:options="composerOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>
			<div :class="$style.cellB">
				<NcButton type="tertiary" :title="t('mobilesheetsmanager', 'Use Song B Composers')" @click="copyComposers('B')">
					<template #icon><ArrowLeftIcon :size="16" /></template>
				</NcButton>
				<div :class="[$style.fieldInfo, $style.alignRight]">
					<span :class="$style.label">{{ t('mobilesheetsmanager', 'Composers') }}</span>
					<span :class="$style.val">{{ formatList(songB?.composers) }}</span>
				</div>
			</div>

			<!-- Row: Books -->
			<div :class="$style.cellA">
				<div :class="$style.fieldInfo">
					<span :class="$style.label">{{ t('mobilesheetsmanager', 'Books') }}</span>
					<span :class="$style.val">{{ formatList(songA?.books) }}</span>
				</div>
				<NcButton type="tertiary" :title="t('mobilesheetsmanager', 'Use Song A Books')" @click="copyBooks('A')">
					<template #icon><ArrowRightIcon :size="16" /></template>
				</NcButton>
			</div>
			<div :class="$style.cellMerged">
				<label :class="$style.fieldLabel">{{ t('mobilesheetsmanager', 'Books') }}</label>
				<NcSelect
					v-model="mergedBooks"
					:options="bookOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>
			<div :class="$style.cellB">
				<NcButton type="tertiary" :title="t('mobilesheetsmanager', 'Use Song B Books')" @click="copyBooks('B')">
					<template #icon><ArrowLeftIcon :size="16" /></template>
				</NcButton>
				<div :class="[$style.fieldInfo, $style.alignRight]">
					<span :class="$style.label">{{ t('mobilesheetsmanager', 'Books') }}</span>
					<span :class="$style.val">{{ formatList(songB?.books) }}</span>
				</div>
			</div>

			<!-- Row: Collections -->
			<div :class="$style.cellA">
				<div :class="$style.fieldInfo">
					<span :class="$style.label">{{ t('mobilesheetsmanager', 'Collections') }}</span>
					<span :class="$style.val">{{ formatList(songA?.collections) }}</span>
				</div>
				<NcButton type="tertiary" :title="t('mobilesheetsmanager', 'Use Song A Collections')" @click="copyCollections('A')">
					<template #icon><ArrowRightIcon :size="16" /></template>
				</NcButton>
			</div>
			<div :class="$style.cellMerged">
				<label :class="$style.fieldLabel">{{ t('mobilesheetsmanager', 'Collections') }}</label>
				<NcSelect
					v-model="mergedCollections"
					:options="collectionOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>
			<div :class="$style.cellB">
				<NcButton type="tertiary" :title="t('mobilesheetsmanager', 'Use Song B Collections')" @click="copyCollections('B')">
					<template #icon><ArrowLeftIcon :size="16" /></template>
				</NcButton>
				<div :class="[$style.fieldInfo, $style.alignRight]">
					<span :class="$style.label">{{ t('mobilesheetsmanager', 'Collections') }}</span>
					<span :class="$style.val">{{ formatList(songB?.collections) }}</span>
				</div>
			</div>

			<!-- Row: Artists -->
			<div :class="$style.cellA">
				<div :class="$style.fieldInfo">
					<span :class="$style.label">{{ t('mobilesheetsmanager', 'Artists') }}</span>
					<span :class="$style.val">{{ formatList(songA?.artists) }}</span>
				</div>
				<NcButton type="tertiary" :title="t('mobilesheetsmanager', 'Use Song A Artists')" @click="copyArtists('A')">
					<template #icon><ArrowRightIcon :size="16" /></template>
				</NcButton>
			</div>
			<div :class="$style.cellMerged">
				<label :class="$style.fieldLabel">{{ t('mobilesheetsmanager', 'Artists') }}</label>
				<NcSelect
					v-model="mergedArtists"
					:options="artistOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>
			<div :class="$style.cellB">
				<NcButton type="tertiary" :title="t('mobilesheetsmanager', 'Use Song B Artists')" @click="copyArtists('B')">
					<template #icon><ArrowLeftIcon :size="16" /></template>
				</NcButton>
				<div :class="[$style.fieldInfo, $style.alignRight]">
					<span :class="$style.label">{{ t('mobilesheetsmanager', 'Artists') }}</span>
					<span :class="$style.val">{{ formatList(songB?.artists) }}</span>
				</div>
			</div>

			<!-- Row: Genres -->
			<div :class="$style.cellA">
				<div :class="$style.fieldInfo">
					<span :class="$style.label">{{ t('mobilesheetsmanager', 'Genres') }}</span>
					<span :class="$style.val">{{ formatList(songA?.genres) }}</span>
				</div>
				<NcButton type="tertiary" :title="t('mobilesheetsmanager', 'Use Song A Genres')" @click="copyGenres('A')">
					<template #icon><ArrowRightIcon :size="16" /></template>
				</NcButton>
			</div>
			<div :class="$style.cellMerged">
				<label :class="$style.fieldLabel">{{ t('mobilesheetsmanager', 'Genres') }}</label>
				<NcSelect
					v-model="mergedGenres"
					:options="genreOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>
			<div :class="$style.cellB">
				<NcButton type="tertiary" :title="t('mobilesheetsmanager', 'Use Song B Genres')" @click="copyGenres('B')">
					<template #icon><ArrowLeftIcon :size="16" /></template>
				</NcButton>
				<div :class="[$style.fieldInfo, $style.alignRight]">
					<span :class="$style.label">{{ t('mobilesheetsmanager', 'Genres') }}</span>
					<span :class="$style.val">{{ formatList(songB?.genres) }}</span>
				</div>
			</div>

			<!-- Row: Keys -->
			<div :class="$style.cellA">
				<div :class="$style.fieldInfo">
					<span :class="$style.label">{{ t('mobilesheetsmanager', 'Keys') }}</span>
					<span :class="$style.val">{{ formatList(songA?.keys) }}</span>
				</div>
				<NcButton type="tertiary" :title="t('mobilesheetsmanager', 'Use Song A Keys')" @click="copyKeys('A')">
					<template #icon><ArrowRightIcon :size="16" /></template>
				</NcButton>
			</div>
			<div :class="$style.cellMerged">
				<label :class="$style.fieldLabel">{{ t('mobilesheetsmanager', 'Keys') }}</label>
				<NcSelect
					v-model="mergedKeys"
					:options="keyOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>
			<div :class="$style.cellB">
				<NcButton type="tertiary" :title="t('mobilesheetsmanager', 'Use Song B Keys')" @click="copyKeys('B')">
					<template #icon><ArrowLeftIcon :size="16" /></template>
				</NcButton>
				<div :class="[$style.fieldInfo, $style.alignRight]">
					<span :class="$style.label">{{ t('mobilesheetsmanager', 'Keys') }}</span>
					<span :class="$style.val">{{ formatList(songB?.keys) }}</span>
				</div>
			</div>

			<!-- Row: Keywords -->
			<div :class="$style.cellA">
				<div :class="$style.fieldInfo">
					<span :class="$style.label">{{ t('mobilesheetsmanager', 'Keywords') }}</span>
					<span :class="$style.val">{{ songA?.keywords || '-' }}</span>
				</div>
				<NcButton type="tertiary" :title="t('mobilesheetsmanager', 'Use Song A Keywords')" @click="copyKeywords('A')">
					<template #icon><ArrowRightIcon :size="16" /></template>
				</NcButton>
			</div>
			<div :class="$style.cellMerged">
				<NcTextField
					:label="t('mobilesheetsmanager', 'Keywords')"
					:value="mergedKeywords"
					@update:value="mergedKeywords = $event" />
			</div>
			<div :class="$style.cellB">
				<NcButton type="tertiary" :title="t('mobilesheetsmanager', 'Use Song B Keywords')" @click="copyKeywords('B')">
					<template #icon><ArrowLeftIcon :size="16" /></template>
				</NcButton>
				<div :class="[$style.fieldInfo, $style.alignRight]">
					<span :class="$style.label">{{ t('mobilesheetsmanager', 'Keywords') }}</span>
					<span :class="$style.val">{{ songB?.keywords || '-' }}</span>
				</div>
			</div>

			<!-- Row: Attached Files -->
			<div :class="$style.cellA">
				<div :class="$style.fieldInfo">
					<span :class="$style.label">{{ t('mobilesheetsmanager', 'Attached Files') }}</span>
					<ul v-if="songA?.files?.length" :class="$style.fileList">
						<li v-for="f in songA.files" :key="f.id" :class="$style.fileItem">
							<FileDocumentIcon :size="16" />
							<span :class="$style.fileName">{{ getFileName(f.path) }}</span>
							<NcButton type="tertiary" :title="t('mobilesheetsmanager', 'Open File')" @click="openFileExternally(f.path)">
								<template #icon><OpenInNewIcon :size="14" /></template>
							</NcButton>
						</li>
					</ul>
					<span v-else :class="$style.val">{{ t('mobilesheetsmanager', '(None)') }}</span>
				</div>
			</div>
			<div :class="$style.cellMerged">
				<div :class="$style.filesNotice">
					<strong>{{ t('mobilesheetsmanager', 'Attached Files Policy') }}</strong>
					<p>{{ t('mobilesheetsmanager', 'All files from both songs will be attached to Song ID {id}. Duplicate files are merged automatically.', { id: String(keepId) }) }}</p>
				</div>
			</div>
			<div :class="$style.cellB">
				<div :class="[$style.fieldInfo, $style.alignRight]">
					<span :class="$style.label">{{ t('mobilesheetsmanager', 'Attached Files') }}</span>
					<ul v-if="songB?.files?.length" :class="$style.fileList">
						<li v-for="f in songB.files" :key="f.id" :class="$style.fileItem">
							<FileDocumentIcon :size="16" />
							<span :class="$style.fileName">{{ getFileName(f.path) }}</span>
							<NcButton type="tertiary" :title="t('mobilesheetsmanager', 'Open File')" @click="openFileExternally(f.path)">
								<template #icon><OpenInNewIcon :size="14" /></template>
							</NcButton>
						</li>
					</ul>
					<span v-else :class="$style.val">{{ t('mobilesheetsmanager', '(None)') }}</span>
				</div>
			</div>
		</div>

		<!-- Dialog Footer Actions -->
		<div :class="$style.dialogActions">
			<NcButton @click="emit('update:open', false)">
				{{ t('mobilesheetsmanager', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="isMerging || !songA || !songB || !mergedTitle.trim()"
				@click="submit">
				<template #icon>
					<NcLoadingIcon v-if="isMerging" :size="16" />
					<MergeIcon v-else :size="16" />
				</template>
				{{ isMerging ? t('mobilesheetsmanager', 'Merging…') : t('mobilesheetsmanager', 'Merge Songs') }}
			</NcButton>
		</div>
	</NcDialog>
</template>

<style module>
.hint {
	color: var(--color-text-maxcontrast);
	margin: 0 0 16px;
}

.comparisonGrid {
	display: grid;
	grid-template-columns: 1fr 1.3fr 1fr;
	gap: 12px 16px;
	align-items: center;
}

.headerCol {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
	font-weight: 600;
	padding: 12px;
	border-radius: var(--border-radius-large, 8px);
	border: 1px solid var(--color-border);
}

.songLeft {
	background: var(--color-background-hover);
	border-left: 4px solid var(--color-primary-element);
}

.songRight {
	background: var(--color-background-hover);
	border-left: 4px solid var(--color-warning);
}

.songMerged {
	background: var(--color-main-background);
	border-left: 4px solid var(--color-success);
}

.idBadge {
	background: var(--color-background-dark);
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 16px);
	font-size: 0.8rem;
	font-weight: normal;
}

.cellA {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 10px 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius, 4px);
	border: 1px solid var(--color-border);
	min-height: 48px;
}

.cellB {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 10px 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius, 4px);
	border: 1px solid var(--color-border);
	min-height: 48px;
}

.cellMerged {
	display: flex;
	flex-direction: column;
	justify-content: center;
	min-height: 48px;
}

.fieldInfo {
	display: flex;
	flex-direction: column;
	gap: 2px;
	overflow: hidden;
}

.alignRight {
	align-items: flex-end;
	text-align: right;
}

.label {
	font-size: 0.75rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.val {
	font-size: 0.9rem;
	color: var(--color-main-text);
	word-break: break-word;
}

.fieldLabel {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin-bottom: -6px;
}

.fileList {
	margin: 4px 0 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.fileItem {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 0.8rem;
	padding: 2px 6px;
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
}

.fileName {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.filesNotice {
	padding: 8px 12px;
	border-radius: var(--border-radius, 4px);
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	font-size: 0.85rem;
}

.filesNotice p {
	margin: 2px 0 0;
	color: var(--color-text-maxcontrast);
}

.dialogActions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 20px;
}
</style>

