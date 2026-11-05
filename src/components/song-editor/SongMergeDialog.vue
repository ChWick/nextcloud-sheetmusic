<script setup lang="ts">
import type { IntegrityIssue, LibraryData, Song } from '../../types/song'
import { computed, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import FileMultipleIcon from 'vue-material-design-icons/FileMultiple.vue'
import MergeIcon from 'vue-material-design-icons/Merge.vue'
import SongMergeModeView from './SongMergeModeView.vue'
import SongResolveBothModeView from './SongResolveBothModeView.vue'
import {
	buildLibraryOptionsMap,
	copyDraftToDraft,
	copyFieldIntoDraft,
	copySingleFieldDraftToDraft,
	createDraftFromSong,
	createMergedDraft,
	draftToMergedSongData,
	wouldSongsCollide,
	type FieldName,
	type MergeMode,
	type MergedSongData,
	type ResolveBothSongData,
	type SelectOption,
	type SongEditableDraft,
	type SongEntityProp,
	type SongScalarProp,
} from './song-merge-utils'

export type { MergedSongData, ResolveBothSongData }

const props = withDefaults(
	defineProps<{
		open: boolean
		issue: IntegrityIssue
		libraryData: LibraryData
		isMerging?: boolean
		initialMode?: MergeMode
		error?: string
	}>(),
	{
		isMerging: false,
		initialMode: 'merge',
		error: '',
	},
)

const emit = defineEmits<{
	'update:open': [value: boolean]
	merge: [payload: {
		keepId: number
		droppedId: number
		merged: MergedSongData
	}]
	resolveBoth: [payload: {
		songA: ResolveBothSongData
		songB: ResolveBothSongData
	}]
}>()

const mode = ref<MergeMode>(props.initialMode ?? 'merge')

function songById(id: number): Song | undefined {
	return props.libraryData?.songs.find(s => s.id === id)
}

const songIds = computed<number[]>(() => props.issue.details?.songIds ?? [])
const songA = computed<Song | undefined>(() => songById(songIds.value[0]))
const songB = computed<Song | undefined>(() => songById(songIds.value[1]))

// Keep ID selection for Mode 1 ("merge")
const keepId = ref<number>(songIds.value[0] ?? 0)
const droppedId = computed<number>(() => {
	const ids = songIds.value
	return ids.find(id => id !== keepId.value) ?? ids[1] ?? 0
})

// Mode 1: Merged Result Draft
const mergedDraft = ref<SongEditableDraft>(createMergedDraft())

// Mode 2: Separate Song Drafts
const songADraft = ref<SongEditableDraft>(createDraftFromSong())
const songBDraft = ref<SongEditableDraft>(createDraftFromSong())
const songADetachedFiles = ref<number[]>([])
const songBDetachedFiles = ref<number[]>([])

function resetAllDrafts() {
	mergedDraft.value = createMergedDraft(songA.value, songB.value)
	songADraft.value = createDraftFromSong(songA.value)
	songBDraft.value = createDraftFromSong(songB.value)
	songADetachedFiles.value = []
	songBDetachedFiles.value = []
}

watch(
	() => [props.open, songA.value?.id, songB.value?.id] as const,
	([open]) => {
		if (open) {
			mode.value = props.initialMode ?? 'merge'
			keepId.value = songIds.value[0] ?? 0
			resetAllDrafts()
		}
	},
	{ immediate: true },
)

const optionsMap = computed(() => buildLibraryOptionsMap(props.libraryData, songA.value, songB.value))

const wouldCollide = computed(() => {
	if (mode.value !== 'keepBoth') return false
	return wouldSongsCollide({
		songA: songA.value,
		songB: songB.value,
		titleA: songADraft.value.title,
		titleB: songBDraft.value.title,
		composersA: songADraft.value.entities.composers ?? [],
		composersB: songBDraft.value.entities.composers ?? [],
		artistsA: songADraft.value.entities.artists ?? [],
		artistsB: songBDraft.value.entities.artists ?? [],
		booksA: songADraft.value.entities.books ?? [],
		booksB: songBDraft.value.entities.books ?? [],
		collectionsA: songADraft.value.entities.collections ?? [],
		collectionsB: songBDraft.value.entities.collections ?? [],
		detachedFilesA: songADetachedFiles.value,
		detachedFilesB: songBDetachedFiles.value,
	})
})

function differentiateTitles() {
	if (!songADraft.value.title.endsWith(' (1)')) {
		songADraft.value.title = songADraft.value.title.trim() + ' (1)'
	}
	if (!songBDraft.value.title.endsWith(' (2)')) {
		songBDraft.value.title = songBDraft.value.title.trim() + ' (2)'
	}
}

// Mode 1 handlers
function copyField(field: FieldName, from: 'A' | 'B') {
	copyFieldIntoDraft(mergedDraft.value, field, from === 'A' ? songA.value : songB.value)
}

function copyAllIntoMerged(from: 'A' | 'B') {
	const source = from === 'A' ? songA.value : songB.value
	if (!source) return
	mergedDraft.value = createDraftFromSong(source)
}

function updateMergedTitle(val: string) {
	mergedDraft.value.title = val
}

function updateMergedEntity(prop: SongEntityProp, val: SelectOption[]) {
	mergedDraft.value.entities[prop] = val
}

function updateMergedScalar(key: SongScalarProp, val: string | number | null) {
	mergedDraft.value.scalars[key] = val
}

// Mode 2 handlers
function copyFieldsBetweenSongs(from: 'A' | 'B') {
	if (from === 'A') {
		copyDraftToDraft(songADraft.value, songBDraft.value)
	} else {
		copyDraftToDraft(songBDraft.value, songADraft.value)
	}
}

function copySingleFieldBetweenSongs(field: FieldName, from: 'A' | 'B') {
	if (from === 'A') {
		copySingleFieldDraftToDraft(songADraft.value, songBDraft.value, field)
	} else {
		copySingleFieldDraftToDraft(songBDraft.value, songADraft.value, field)
	}
}

function updateResolveTitle(target: 'A' | 'B', val: string) {
	const draft = target === 'A' ? songADraft.value : songBDraft.value
	draft.title = val
}

function updateResolveEntity(target: 'A' | 'B', prop: SongEntityProp, val: SelectOption[]) {
	const draft = target === 'A' ? songADraft.value : songBDraft.value
	draft.entities[prop] = val
}

function updateResolveScalar(target: 'A' | 'B', key: SongScalarProp, val: string | number | null) {
	const draft = target === 'A' ? songADraft.value : songBDraft.value
	draft.scalars[key] = val
}

function toggleDetachFile(target: 'A' | 'B', fileId: number) {
	const list = target === 'A' ? songADetachedFiles : songBDetachedFiles
	if (list.value.includes(fileId)) {
		list.value = list.value.filter(id => id !== fileId)
	} else {
		list.value.push(fileId)
	}
}

function submit() {
	if (mode.value === 'merge') {
		emit('merge', {
			keepId: keepId.value,
			droppedId: droppedId.value,
			merged: draftToMergedSongData(mergedDraft.value),
		})
	} else {
		if (wouldCollide.value) {
			return
		}
		emit('resolveBoth', {
			songA: {
				id: songA.value?.id ?? 0,
				...draftToMergedSongData(songADraft.value),
				detachFileIds: [...songADetachedFiles.value],
			},
			songB: {
				id: songB.value?.id ?? 0,
				...draftToMergedSongData(songBDraft.value),
				detachFileIds: [...songBDetachedFiles.value],
			},
		})
	}
}
</script>

<template>
	<NcDialog
		v-if="open"
		:name="t('sheetmusic', 'Duplicate Songs Resolution')"
		size="full"
		:class="$style.mergeDialog"
		@close="emit('update:open', false)">

		<!-- Mode Switcher -->
		<div :class="$style.modeSwitchBar">
			<NcButton
				type="button"
				:variant="mode === 'merge' ? 'primary' : 'secondary'"
				data-test="mode-merge-btn"
				@click="mode = 'merge'">
				<template #icon><MergeIcon :size="16" /></template>
				{{ t('sheetmusic', 'Merge into 1 Song') }}
			</NcButton>
			<NcButton
				type="button"
				:variant="mode === 'keepBoth' ? 'primary' : 'secondary'"
				data-test="mode-keep-both-btn"
				@click="mode = 'keepBoth'">
				<template #icon><FileMultipleIcon :size="16" /></template>
				{{ t('sheetmusic', 'Keep as 2 Separate Songs (Resolve Conflicts)') }}
			</NcButton>
		</div>

		<p :class="$style.hint">
			<template v-if="mode === 'merge'">
				{{ t('sheetmusic', 'Choose which song ID to keep. Use arrows to copy values from Song A or Song B into the merged result. Fields pre-fill if both songs agree.') }}
			</template>
			<template v-else>
				{{ t('sheetmusic', 'Keep both songs in your library as separate entries. Adapt titles, metadata, or detach files to resolve path conflicts.') }}
			</template>
		</p>

		<!-- Server Error Banner -->
		<div v-if="error" :class="$style.conflictWarning">
			<AlertIcon :size="20" />
			<div :class="$style.warningText">
				<strong>{{ t('sheetmusic', 'Error') }}</strong>
				<p>{{ error }}</p>
			</div>
		</div>

		<!-- Mode 2 Conflict Warning Banner -->
		<div v-if="wouldCollide" :class="$style.conflictWarning">
			<AlertIcon :size="20" />
			<div :class="$style.warningText">
				<strong>{{ t('sheetmusic', 'Conflict Not Resolved') }}</strong>
				<p>{{ t('sheetmusic', 'Saving would recreate a duplicate file path or naming conflict in the library audit. Adapt at least one title, metadata field, or detach a file to differentiate them.') }}</p>
			</div>
			<NcButton variant="tertiary" type="button" data-test="differentiate-titles-btn" @click="differentiateTitles">
				{{ t('sheetmusic', 'Differentiate Titles') }}
			</NcButton>
		</div>

		<!-- MODE 1: MERGE INTO 1 SONG -->
		<SongMergeModeView
			v-if="mode === 'merge'"
			v-model:keep-id="keepId"
			:song-a="songA"
			:song-b="songB"
			:draft="mergedDraft"
			:options-map="optionsMap"
			@update:title="updateMergedTitle"
			@update:entity="updateMergedEntity"
			@update:scalar="updateMergedScalar"
			@copy-field="copyField"
			@copy-all="copyAllIntoMerged" />

		<!-- MODE 2: KEEP BOTH SONGS AS SEPARATE ENTITIES -->
		<SongResolveBothModeView
			v-else-if="mode === 'keepBoth'"
			:song-a="songA"
			:song-b="songB"
			:draft-a="songADraft"
			:draft-b="songBDraft"
			:detached-files-a="songADetachedFiles"
			:detached-files-b="songBDetachedFiles"
			:options-map="optionsMap"
			@update:title="updateResolveTitle"
			@update:entity="updateResolveEntity"
			@update:scalar="updateResolveScalar"
			@copy-fields-between-songs="copyFieldsBetweenSongs"
			@copy-single-field-between-songs="copySingleFieldBetweenSongs"
			@toggle-detach-file="toggleDetachFile" />

		<!-- Dialog Footer Actions -->
		<div :class="$style.dialogActions">
			<NcButton type="button" data-test="cancel-merge-btn" @click="emit('update:open', false)">
				{{ t('sheetmusic', 'Cancel') }}
			</NcButton>

			<!-- Submit for Mode 1 ("merge") -->
			<NcButton
				v-if="mode === 'merge'"
				type="button"
				variant="primary"
				:disabled="isMerging || !songA || !songB || !mergedDraft.title.trim()"
				data-test="submit-merge-btn"
				@click="submit">
				<template #icon>
					<NcLoadingIcon v-if="isMerging" :size="16" />
					<MergeIcon v-else :size="16" />
				</template>
				{{ isMerging ? t('sheetmusic', 'Merging…') : t('sheetmusic', 'Merge Songs') }}
			</NcButton>

			<!-- Submit for Mode 2 ("keepBoth") -->
			<NcButton
				v-else
				type="button"
				variant="primary"
				:disabled="isMerging || wouldCollide || !songADraft.title.trim() || !songBDraft.title.trim()"
				data-test="submit-resolve-both-btn"
				@click="submit">
				<template #icon>
					<NcLoadingIcon v-if="isMerging" :size="16" />
					<FileMultipleIcon v-else :size="16" />
				</template>
				{{ isMerging ? t('sheetmusic', 'Saving…') : t('sheetmusic', 'Save & Resolve Conflicts') }}
			</NcButton>
		</div>
	</NcDialog>
</template>

<style module>
.mergeDialog :global(.modal-container),
.mergeDialog :global(.dialog) {
	width: min(1320px, 94vw) !important;
	max-width: 1320px !important;
}

.modeSwitchBar {
	display: flex;
	gap: 12px;
	margin-bottom: 12px;
}

.hint {
	color: var(--color-text-maxcontrast);
	margin: 0 0 16px;
}

.conflictWarning {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	margin-bottom: 16px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-warning-light);
	border: 1px solid var(--color-warning);
	color: var(--color-text-maxcontrast);
}

.warningText {
	flex: 1;
}

.warningText strong {
	color: var(--color-main-text);
}

.warningText p {
	margin: 2px 0 0;
	font-size: 0.85rem;
}

.dialogActions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 20px;
}
</style>
