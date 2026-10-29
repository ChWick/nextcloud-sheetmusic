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
	areEntitiesEqual,
	toSelectOptions,
	wouldSongsCollide,
	type FieldName,
	type MergeMode,
	type MergedSongData,
	type ResolveBothSongData,
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

// -----------------------------------------------------------------------
// Mode 1: Merged Result Fields
// -----------------------------------------------------------------------
const mergedTitle = ref('')
const mergedComposers = ref<{ id: number; label: string }[]>([])
const mergedBooks = ref<{ id: number; label: string }[]>([])
const mergedCollections = ref<{ id: number; label: string }[]>([])
const mergedArtists = ref<{ id: number; label: string }[]>([])
const mergedGenres = ref<{ id: number; label: string }[]>([])
const mergedKeys = ref<{ id: number; label: string }[]>([])
const mergedKeywords = ref('')
const mergedSortTitle = ref('')

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

	mergedTitle.value = (a.title.trim() === b.title.trim()) ? a.title : ''
	mergedComposers.value = areEntitiesEqual(a.composers, b.composers) ? toSelectOptions(a.composers) : []
	mergedBooks.value = areEntitiesEqual(a.books, b.books) ? toSelectOptions(a.books) : []
	mergedCollections.value = areEntitiesEqual(a.collections, b.collections) ? toSelectOptions(a.collections) : []
	mergedArtists.value = areEntitiesEqual(a.artists, b.artists) ? toSelectOptions(a.artists) : []
	mergedGenres.value = areEntitiesEqual(a.genres, b.genres) ? toSelectOptions(a.genres) : []
	mergedKeys.value = areEntitiesEqual(a.keys, b.keys) ? toSelectOptions(a.keys) : []
	mergedKeywords.value = ((a.keywords ?? '').trim() === (b.keywords ?? '').trim()) ? (a.keywords ?? '') : ''
	mergedSortTitle.value = ((a.sortTitle ?? '').trim() === (b.sortTitle ?? '').trim()) ? (a.sortTitle ?? '') : ''
}

// -----------------------------------------------------------------------
// Mode 2: Separate Song Fields
// -----------------------------------------------------------------------
const songA_title = ref('')
const songA_composers = ref<{ id: number; label: string }[]>([])
const songA_books = ref<{ id: number; label: string }[]>([])
const songA_collections = ref<{ id: number; label: string }[]>([])
const songA_artists = ref<{ id: number; label: string }[]>([])
const songA_genres = ref<{ id: number; label: string }[]>([])
const songA_keys = ref<{ id: number; label: string }[]>([])
const songA_keywords = ref('')
const songA_detachedFiles = ref<number[]>([])

const songB_title = ref('')
const songB_composers = ref<{ id: number; label: string }[]>([])
const songB_books = ref<{ id: number; label: string }[]>([])
const songB_collections = ref<{ id: number; label: string }[]>([])
const songB_artists = ref<{ id: number; label: string }[]>([])
const songB_genres = ref<{ id: number; label: string }[]>([])
const songB_keys = ref<{ id: number; label: string }[]>([])
const songB_keywords = ref('')
const songB_detachedFiles = ref<number[]>([])

function prefillKeepBoth() {
	const a = songA.value
	const b = songB.value

	songA_title.value = a?.title ?? ''
	songA_composers.value = toSelectOptions(a?.composers)
	songA_books.value = toSelectOptions(a?.books)
	songA_collections.value = toSelectOptions(a?.collections)
	songA_artists.value = toSelectOptions(a?.artists)
	songA_genres.value = toSelectOptions(a?.genres)
	songA_keys.value = toSelectOptions(a?.keys)
	songA_keywords.value = a?.keywords ?? ''
	songA_detachedFiles.value = []

	songB_title.value = b?.title ?? ''
	songB_composers.value = toSelectOptions(b?.composers)
	songB_books.value = toSelectOptions(b?.books)
	songB_collections.value = toSelectOptions(b?.collections)
	songB_artists.value = toSelectOptions(b?.artists)
	songB_genres.value = toSelectOptions(b?.genres)
	songB_keys.value = toSelectOptions(b?.keys)
	songB_keywords.value = b?.keywords ?? ''
	songB_detachedFiles.value = []
}

watch(() => props.open, open => {
	if (open) {
		mode.value = props.initialMode ?? 'merge'
		keepId.value = songIds.value[0] ?? 0
		prefillMerged()
		prefillKeepBoth()
	}
}, { immediate: true })

const wouldCollide = computed(() => {
	if (mode.value !== 'keepBoth') return false
	return wouldSongsCollide({
		songA: songA.value,
		songB: songB.value,
		titleA: songA_title.value,
		titleB: songB_title.value,
		composersA: songA_composers.value,
		composersB: songB_composers.value,
		artistsA: songA_artists.value,
		artistsB: songB_artists.value,
		booksA: songA_books.value,
		booksB: songB_books.value,
		collectionsA: songA_collections.value,
		collectionsB: songB_collections.value,
		detachedFilesA: songA_detachedFiles.value,
		detachedFilesB: songB_detachedFiles.value,
	})
})

function differentiateTitles() {
	if (!songA_title.value.endsWith(' (1)')) {
		songA_title.value = songA_title.value.trim() + ' (1)'
	}
	if (!songB_title.value.endsWith(' (2)')) {
		songB_title.value = songB_title.value.trim() + ' (2)'
	}
}

// Transfer helper for Mode 1
function copyField(field: FieldName, from: 'A' | 'B') {
	const s = from === 'A' ? songA.value : songB.value
	if (!s) return

	switch (field) {
		case 'title': mergedTitle.value = s.title; break
		case 'keywords': mergedKeywords.value = s.keywords ?? ''; break
		case 'composers': mergedComposers.value = toSelectOptions(s.composers); break
		case 'books': mergedBooks.value = toSelectOptions(s.books); break
		case 'collections': mergedCollections.value = toSelectOptions(s.collections); break
		case 'artists': mergedArtists.value = toSelectOptions(s.artists); break
		case 'genres': mergedGenres.value = toSelectOptions(s.genres); break
		case 'keys': mergedKeys.value = toSelectOptions(s.keys); break
	}
}

// Transfer helper for Mode 2
function copyFieldsBetweenSongs(from: 'A' | 'B') {
	if (from === 'A') {
		songB_title.value = songA_title.value
		songB_composers.value = [...songA_composers.value]
		songB_books.value = [...songA_books.value]
		songB_collections.value = [...songA_collections.value]
		songB_artists.value = [...songA_artists.value]
		songB_genres.value = [...songA_genres.value]
		songB_keys.value = [...songA_keys.value]
		songB_keywords.value = songA_keywords.value
	} else {
		songA_title.value = songB_title.value
		songA_composers.value = [...songB_composers.value]
		songA_books.value = [...songB_books.value]
		songA_collections.value = [...songB_collections.value]
		songA_artists.value = [...songB_artists.value]
		songA_genres.value = [...songB_genres.value]
		songA_keys.value = [...songB_keys.value]
		songA_keywords.value = songB_keywords.value
	}
}

function toggleDetachFile(target: 'A' | 'B', fileId: number) {
	const list = target === 'A' ? songA_detachedFiles : songB_detachedFiles
	if (list.value.includes(fileId)) {
		list.value = list.value.filter(id => id !== fileId)
	} else {
		list.value.push(fileId)
	}
}

// Option lists
const composerOptions = computed(() => toSelectOptions(props.libraryData?.composers ?? []))
const bookOptions = computed(() => toSelectOptions(props.libraryData?.books ?? []))
const collectionOptions = computed(() => toSelectOptions(props.libraryData?.collections ?? []))
const artistOptions = computed(() => toSelectOptions(props.libraryData?.artists ?? []))
const genreOptions = computed(() => toSelectOptions(props.libraryData?.genres ?? []))
const keyOptions = computed(() => toSelectOptions(props.libraryData?.keys ?? []))

function submit() {
	if (mode.value === 'merge') {
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
	} else {
		if (wouldCollide.value) {
			return
		}
		emit('resolveBoth', {
			songA: {
				id: songA.value?.id ?? 0,
				title: songA_title.value,
				composerIds: songA_composers.value.map(e => e.id),
				bookIds: songA_books.value.map(e => e.id),
				collectionIds: songA_collections.value.map(e => e.id),
				artistIds: songA_artists.value.map(e => e.id),
				genreIds: songA_genres.value.map(e => e.id),
				keyIds: songA_keys.value.map(e => e.id),
				customGroupIds: [],
				yearIds: [],
				signatureIds: [],
				sourceTypeIds: [],
				stars: songA.value?.stars ?? 0,
				difficulty: songA.value?.difficulty ?? 0,
				duration: songA.value?.duration ?? 0,
				keywords: songA_keywords.value,
				custom: songA.value?.custom ?? '',
				custom2: songA.value?.custom2 ?? '',
				sortTitle: songA.value?.sortTitle ?? '',
				detachFileIds: [...songA_detachedFiles.value],
			},
			songB: {
				id: songB.value?.id ?? 0,
				title: songB_title.value,
				composerIds: songB_composers.value.map(e => e.id),
				bookIds: songB_books.value.map(e => e.id),
				collectionIds: songB_collections.value.map(e => e.id),
				artistIds: songB_artists.value.map(e => e.id),
				genreIds: songB_genres.value.map(e => e.id),
				keyIds: songB_keys.value.map(e => e.id),
				customGroupIds: [],
				yearIds: [],
				signatureIds: [],
				sourceTypeIds: [],
				stars: songB.value?.stars ?? 0,
				difficulty: songB.value?.difficulty ?? 0,
				duration: songB.value?.duration ?? 0,
				keywords: songB_keywords.value,
				custom: songB.value?.custom ?? '',
				custom2: songB.value?.custom2 ?? '',
				sortTitle: songB.value?.sortTitle ?? '',
				detachFileIds: [...songB_detachedFiles.value],
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
		@close="emit('update:open', false)">

		<!-- Mode Switcher -->
		<div :class="$style.modeSwitchBar">
			<NcButton
				:variant="mode === 'merge' ? 'primary' : 'secondary'"
				@click="mode = 'merge'">
				<template #icon><MergeIcon :size="16" /></template>
				{{ t('sheetmusic', 'Merge into 1 Song') }}
			</NcButton>
			<NcButton
				:variant="mode === 'keepBoth' ? 'primary' : 'secondary'"
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
			<NcButton variant="primary" type="tertiary" @click="differentiateTitles">
				{{ t('sheetmusic', 'Differentiate Titles') }}
			</NcButton>
		</div>

		<!-- MODE 1: MERGE INTO 1 SONG -->
		<SongMergeModeView
			v-if="mode === 'merge'"
			v-model:keep-id="keepId"
			v-model:merged-title="mergedTitle"
			v-model:merged-composers="mergedComposers"
			v-model:merged-books="mergedBooks"
			v-model:merged-collections="mergedCollections"
			v-model:merged-artists="mergedArtists"
			v-model:merged-genres="mergedGenres"
			v-model:merged-keys="mergedKeys"
			v-model:merged-keywords="mergedKeywords"
			:song-a="songA"
			:song-b="songB"
			:composer-options="composerOptions"
			:book-options="bookOptions"
			:collection-options="collectionOptions"
			:artist-options="artistOptions"
			:genre-options="genreOptions"
			:key-options="keyOptions"
			@copy-field="copyField" />

		<!-- MODE 2: KEEP BOTH SONGS AS SEPARATE ENTITIES -->
		<SongResolveBothModeView
			v-else-if="mode === 'keepBoth'"
			v-model:song-a_title="songA_title"
			v-model:song-b_title="songB_title"
			v-model:song-a_composers="songA_composers"
			v-model:song-b_composers="songB_composers"
			v-model:song-a_books="songA_books"
			v-model:song-b_books="songB_books"
			v-model:song-a_collections="songA_collections"
			v-model:song-b_collections="songB_collections"
			v-model:song-a_artists="songA_artists"
			v-model:song-b_artists="songB_artists"
			v-model:song-a_genres="songA_genres"
			v-model:song-b_genres="songB_genres"
			v-model:song-a_keys="songA_keys"
			v-model:song-b_keys="songB_keys"
			v-model:song-a_keywords="songA_keywords"
			v-model:song-b_keywords="songB_keywords"
			:song-a="songA"
			:song-b="songB"
			:song-a_detached-files="songA_detachedFiles"
			:song-b_detached-files="songB_detachedFiles"
			:composer-options="composerOptions"
			:book-options="bookOptions"
			:collection-options="collectionOptions"
			:artist-options="artistOptions"
			:genre-options="genreOptions"
			:key-options="keyOptions"
			@copy-fields-between-songs="copyFieldsBetweenSongs"
			@toggle-detach-file="toggleDetachFile" />

		<!-- Dialog Footer Actions -->
		<div :class="$style.dialogActions">
			<NcButton @click="emit('update:open', false)">
				{{ t('sheetmusic', 'Cancel') }}
			</NcButton>

			<!-- Submit for Mode 1 ("merge") -->
			<NcButton
				v-if="mode === 'merge'"
				variant="primary"
				:disabled="isMerging || !songA || !songB || !mergedTitle.trim()"
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
				variant="primary"
				:disabled="isMerging || wouldCollide || !songA_title.trim() || !songB_title.trim()"
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
