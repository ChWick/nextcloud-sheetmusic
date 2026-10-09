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
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import FileMultipleIcon from 'vue-material-design-icons/FileMultiple.vue'
import MergeIcon from 'vue-material-design-icons/Merge.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import TrashCanOutlineIcon from 'vue-material-design-icons/TrashCanOutline.vue'
import { getFileName, openFileExternally } from '../../utils/file'

export interface MergedSongData {
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

export interface ResolveBothSongData extends MergedSongData {
	id: number
	detachFileIds: number[]
}

type MergeMode = 'merge' | 'keepBoth'
type FieldName = 'title' | 'composers' | 'books' | 'collections' | 'artists' | 'genres' | 'keys' | 'keywords'

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

function entityName<T extends { id: number; name?: string; title?: string; year?: number }>(e: T): string {
	return e.name ?? e.title ?? String((e as { year?: number }).year ?? e.id)
}

function toSelectOptions<T extends { id: number; name?: string; title?: string; year?: number }>(items: T[] = []): { id: number; label: string }[] {
	return items.map(e => ({ id: e.id, label: entityName(e) }))
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
// Mode 1: Merged Result Fields (3-column layout)
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

function areEntitiesEqual(a?: { id: number }[], b?: { id: number }[]): boolean {
	const idsA = (a ?? []).map(e => e.id).sort((x, y) => x - y)
	const idsB = (b ?? []).map(e => e.id).sort((x, y) => x - y)
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
// Mode 2: Separate Song Fields (2-column side-by-side editing)
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

// Conflict detector for Mode 2: checks if saving Song A & Song B in their current state would trigger a library integrity audit issue.
const wouldCollide = computed(() => {
	if (mode.value !== 'keepBoth') return false

	const activeFilesA = (songA.value?.files ?? []).filter(f => !songA_detachedFiles.value.includes(f.id))
	const activeFilesB = (songB.value?.files ?? []).filter(f => !songB_detachedFiles.value.includes(f.id))

	// Check 1: Shared physical file path collision
	// If both songs still share active attached file paths (even if titles or metadata differ), keeping both will cause duplicate file path conflicts or cross-song file dependencies
	if (activeFilesA.length > 0 && activeFilesB.length > 0) {
		const pathsA = new Set(activeFilesA.map(f => f.path))
		const sharesPhysicalFile = activeFilesB.some(f => pathsA.has(f.path))
		if (sharesPhysicalFile) {
			return true
		}
	}

	// Check 2: Naming rule / target path collision
	// When both songs have identical titles and folder-defining metadata + matching active file extensions
	const sameTitle = songA_title.value.trim().toLowerCase() === songB_title.value.trim().toLowerCase()
	const sameComposers = areEntitiesEqual(songA_composers.value, songB_composers.value)
	const sameArtists = areEntitiesEqual(songA_artists.value, songB_artists.value)
	const sameBooks = areEntitiesEqual(songA_books.value, songB_books.value)
	const sameCollections = areEntitiesEqual(songA_collections.value, songB_collections.value)

	if (sameTitle && sameComposers && sameArtists && sameBooks && sameCollections && activeFilesA.length > 0 && activeFilesB.length > 0) {
		const extsA = new Set(activeFilesA.map(f => {
			const ext = f.path.split('.').pop()
			return ext ? ext.toLowerCase() : ''
		}))
		const hasOverlappingExtension = activeFilesB.some(f => {
			const ext = f.path.split('.').pop()
			return extsA.has(ext ? ext.toLowerCase() : '')
		})
		if (hasOverlappingExtension) {
			return true
		}
	}

	return false
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

// Transfer helper for Mode 2 (A -> B or B -> A)
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

function formatList(items?: { name?: string; title?: string }[]): string {
	if (!items || items.length === 0) return t('sheetmusic', '(None)')
	return items.map(entityName).join(', ')
}

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

		<!-- MODE 1: MERGE INTO 1 SONG (3-Column Aligned Grid) -->
		<div v-if="mode === 'merge'" :class="$style.comparisonGrid">

			<!-- Column Headers -->
			<div :class="[$style.headerCol, $style.songLeft]">
				<strong>{{ t('sheetmusic', 'Song A') }}</strong>
				<span :class="$style.idBadge">ID {{ songA?.id }}</span>
				<NcCheckboxRadioSwitch
					:checked="keepId === (songA?.id ?? 0)"
					type="radio"
					name="keepId"
					:value="String(songA?.id)"
					@update:checked="keepId = songA?.id ?? 0">
					{{ t('sheetmusic', 'Keep ID') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div :class="[$style.headerCol, $style.songMerged]">
				<MergeIcon :size="18" />
				<strong>{{ t('sheetmusic', 'Merged Result') }}</strong>
				<span :class="$style.idBadge">Keeping ID {{ keepId }}</span>
			</div>

			<div :class="[$style.headerCol, $style.songRight]">
				<strong>{{ t('sheetmusic', 'Song B') }}</strong>
				<span :class="$style.idBadge">ID {{ songB?.id }}</span>
				<NcCheckboxRadioSwitch
					:checked="keepId === (songB?.id ?? 0)"
					type="radio"
					name="keepId"
					:value="String(songB?.id)"
					@update:checked="keepId = songB?.id ?? 0">
					{{ t('sheetmusic', 'Keep ID') }}
				</NcCheckboxRadioSwitch>
			</div>

			<!-- Row: Title -->
			<div :class="$style.cellA">
				<div :class="$style.fieldInfo">
					<span :class="$style.label">{{ t('sheetmusic', 'Title') }}</span>
					<span :class="$style.val">{{ songA?.title || '-' }}</span>
				</div>
				<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song A Title')" @click="copyField('title', 'A')">
					<template #icon><ArrowRightIcon :size="16" /></template>
				</NcButton>
			</div>
			<div :class="$style.cellMerged">
				<NcTextField
					:label="t('sheetmusic', 'Title')"
					:value="mergedTitle"
					@update:value="mergedTitle = $event" />
			</div>
			<div :class="$style.cellB">
				<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song B Title')" @click="copyField('title', 'B')">
					<template #icon><ArrowLeftIcon :size="16" /></template>
				</NcButton>
				<div :class="[$style.fieldInfo, $style.alignRight]">
					<span :class="$style.label">{{ t('sheetmusic', 'Title') }}</span>
					<span :class="$style.val">{{ songB?.title || '-' }}</span>
				</div>
			</div>

			<!-- Row: Composers -->
			<div :class="$style.cellA">
				<div :class="$style.fieldInfo">
					<span :class="$style.label">{{ t('sheetmusic', 'Composers') }}</span>
					<span :class="$style.val">{{ formatList(songA?.composers) }}</span>
				</div>
				<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song A Composers')" @click="copyField('composers', 'A')">
					<template #icon><ArrowRightIcon :size="16" /></template>
				</NcButton>
			</div>
			<div :class="$style.cellMerged">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Composers') }}</label>
				<NcSelect
					v-model="mergedComposers"
					:options="composerOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>
			<div :class="$style.cellB">
				<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song B Composers')" @click="copyField('composers', 'B')">
					<template #icon><ArrowLeftIcon :size="16" /></template>
				</NcButton>
				<div :class="[$style.fieldInfo, $style.alignRight]">
					<span :class="$style.label">{{ t('sheetmusic', 'Composers') }}</span>
					<span :class="$style.val">{{ formatList(songB?.composers) }}</span>
				</div>
			</div>

			<!-- Row: Books -->
			<div :class="$style.cellA">
				<div :class="$style.fieldInfo">
					<span :class="$style.label">{{ t('sheetmusic', 'Books') }}</span>
					<span :class="$style.val">{{ formatList(songA?.books) }}</span>
				</div>
				<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song A Books')" @click="copyField('books', 'A')">
					<template #icon><ArrowRightIcon :size="16" /></template>
				</NcButton>
			</div>
			<div :class="$style.cellMerged">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Books') }}</label>
				<NcSelect
					v-model="mergedBooks"
					:options="bookOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>
			<div :class="$style.cellB">
				<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song B Books')" @click="copyField('books', 'B')">
					<template #icon><ArrowLeftIcon :size="16" /></template>
				</NcButton>
				<div :class="[$style.fieldInfo, $style.alignRight]">
					<span :class="$style.label">{{ t('sheetmusic', 'Books') }}</span>
					<span :class="$style.val">{{ formatList(songB?.books) }}</span>
				</div>
			</div>

			<!-- Row: Collections -->
			<div :class="$style.cellA">
				<div :class="$style.fieldInfo">
					<span :class="$style.label">{{ t('sheetmusic', 'Collections') }}</span>
					<span :class="$style.val">{{ formatList(songA?.collections) }}</span>
				</div>
				<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song A Collections')" @click="copyField('collections', 'A')">
					<template #icon><ArrowRightIcon :size="16" /></template>
				</NcButton>
			</div>
			<div :class="$style.cellMerged">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Collections') }}</label>
				<NcSelect
					v-model="mergedCollections"
					:options="collectionOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>
			<div :class="$style.cellB">
				<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song B Collections')" @click="copyField('collections', 'B')">
					<template #icon><ArrowLeftIcon :size="16" /></template>
				</NcButton>
				<div :class="[$style.fieldInfo, $style.alignRight]">
					<span :class="$style.label">{{ t('sheetmusic', 'Collections') }}</span>
					<span :class="$style.val">{{ formatList(songB?.collections) }}</span>
				</div>
			</div>

			<!-- Row: Artists -->
			<div :class="$style.cellA">
				<div :class="$style.fieldInfo">
					<span :class="$style.label">{{ t('sheetmusic', 'Artists') }}</span>
					<span :class="$style.val">{{ formatList(songA?.artists) }}</span>
				</div>
				<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song A Artists')" @click="copyField('artists', 'A')">
					<template #icon><ArrowRightIcon :size="16" /></template>
				</NcButton>
			</div>
			<div :class="$style.cellMerged">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Artists') }}</label>
				<NcSelect
					v-model="mergedArtists"
					:options="artistOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>
			<div :class="$style.cellB">
				<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song B Artists')" @click="copyField('artists', 'B')">
					<template #icon><ArrowLeftIcon :size="16" /></template>
				</NcButton>
				<div :class="[$style.fieldInfo, $style.alignRight]">
					<span :class="$style.label">{{ t('sheetmusic', 'Artists') }}</span>
					<span :class="$style.val">{{ formatList(songB?.artists) }}</span>
				</div>
			</div>

			<!-- Row: Genres -->
			<div :class="$style.cellA">
				<div :class="$style.fieldInfo">
					<span :class="$style.label">{{ t('sheetmusic', 'Genres') }}</span>
					<span :class="$style.val">{{ formatList(songA?.genres) }}</span>
				</div>
				<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song A Genres')" @click="copyField('genres', 'A')">
					<template #icon><ArrowRightIcon :size="16" /></template>
				</NcButton>
			</div>
			<div :class="$style.cellMerged">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Genres') }}</label>
				<NcSelect
					v-model="mergedGenres"
					:options="genreOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>
			<div :class="$style.cellB">
				<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song B Genres')" @click="copyField('genres', 'B')">
					<template #icon><ArrowLeftIcon :size="16" /></template>
				</NcButton>
				<div :class="[$style.fieldInfo, $style.alignRight]">
					<span :class="$style.label">{{ t('sheetmusic', 'Genres') }}</span>
					<span :class="$style.val">{{ formatList(songB?.genres) }}</span>
				</div>
			</div>

			<!-- Row: Keys -->
			<div :class="$style.cellA">
				<div :class="$style.fieldInfo">
					<span :class="$style.label">{{ t('sheetmusic', 'Keys') }}</span>
					<span :class="$style.val">{{ formatList(songA?.keys) }}</span>
				</div>
				<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song A Keys')" @click="copyField('keys', 'A')">
					<template #icon><ArrowRightIcon :size="16" /></template>
				</NcButton>
			</div>
			<div :class="$style.cellMerged">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Keys') }}</label>
				<NcSelect
					v-model="mergedKeys"
					:options="keyOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>
			<div :class="$style.cellB">
				<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song B Keys')" @click="copyField('keys', 'B')">
					<template #icon><ArrowLeftIcon :size="16" /></template>
				</NcButton>
				<div :class="[$style.fieldInfo, $style.alignRight]">
					<span :class="$style.label">{{ t('sheetmusic', 'Keys') }}</span>
					<span :class="$style.val">{{ formatList(songB?.keys) }}</span>
				</div>
			</div>

			<!-- Row: Keywords -->
			<div :class="$style.cellA">
				<div :class="$style.fieldInfo">
					<span :class="$style.label">{{ t('sheetmusic', 'Keywords') }}</span>
					<span :class="$style.val">{{ songA?.keywords || '-' }}</span>
				</div>
				<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song A Keywords')" @click="copyField('keywords', 'A')">
					<template #icon><ArrowRightIcon :size="16" /></template>
				</NcButton>
			</div>
			<div :class="$style.cellMerged">
				<NcTextField
					:label="t('sheetmusic', 'Keywords')"
					:value="mergedKeywords"
					@update:value="mergedKeywords = $event" />
			</div>
			<div :class="$style.cellB">
				<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song B Keywords')" @click="copyField('keywords', 'B')">
					<template #icon><ArrowLeftIcon :size="16" /></template>
				</NcButton>
				<div :class="[$style.fieldInfo, $style.alignRight]">
					<span :class="$style.label">{{ t('sheetmusic', 'Keywords') }}</span>
					<span :class="$style.val">{{ songB?.keywords || '-' }}</span>
				</div>
			</div>

			<!-- Row: Attached Files -->
			<div :class="$style.cellA">
				<div :class="$style.fieldInfo">
					<span :class="$style.label">{{ t('sheetmusic', 'Attached Files') }}</span>
					<ul v-if="songA?.files?.length" :class="$style.fileList">
						<li v-for="f in songA.files" :key="f.id" :class="$style.fileItem">
							<FileDocumentIcon :size="16" />
							<span :class="$style.fileName">{{ getFileName(f.path) }}</span>
							<NcButton type="tertiary" :title="t('sheetmusic', 'Open File')" @click="openFileExternally(f.path)">
								<template #icon><OpenInNewIcon :size="14" /></template>
							</NcButton>
						</li>
					</ul>
					<span v-else :class="$style.val">{{ t('sheetmusic', '(None)') }}</span>
				</div>
			</div>
			<div :class="$style.cellMerged">
				<div :class="$style.filesNotice">
					<strong>{{ t('sheetmusic', 'Attached Files Policy') }}</strong>
					<p>{{ t('sheetmusic', 'All files from both songs will be attached to Song ID {id}. Duplicate files are merged automatically.', { id: String(keepId) }) }}</p>
				</div>
			</div>
			<div :class="$style.cellB">
				<div :class="[$style.fieldInfo, $style.alignRight]">
					<span :class="$style.label">{{ t('sheetmusic', 'Attached Files') }}</span>
					<ul v-if="songB?.files?.length" :class="$style.fileList">
						<li v-for="f in songB.files" :key="f.id" :class="$style.fileItem">
							<FileDocumentIcon :size="16" />
							<span :class="$style.fileName">{{ getFileName(f.path) }}</span>
							<NcButton type="tertiary" :title="t('sheetmusic', 'Open File')" @click="openFileExternally(f.path)">
								<template #icon><OpenInNewIcon :size="14" /></template>
							</NcButton>
						</li>
					</ul>
					<span v-else :class="$style.val">{{ t('sheetmusic', '(None)') }}</span>
				</div>
			</div>
		</div>

		<!-- MODE 2: KEEP BOTH SONGS AS SEPARATE ENTITIES (Side-by-Side Editable Grid) -->
		<div v-else-if="mode === 'keepBoth'" :class="$style.keepBothGrid">

			<!-- Column Headers -->
			<div :class="[$style.headerCol, $style.songLeft]">
				<strong>{{ t('sheetmusic', 'Song A') }}</strong>
				<span :class="$style.idBadge">ID {{ songA?.id }}</span>
				<NcButton type="tertiary" :title="t('sheetmusic', 'Copy Song A values to Song B')" @click="copyFieldsBetweenSongs('A')">
					<template #icon><ArrowRightIcon :size="16" /></template>
					{{ t('sheetmusic', 'Copy all to B') }}
				</NcButton>
			</div>

			<div :class="[$style.headerCol, $style.songRight]">
				<NcButton type="tertiary" :title="t('sheetmusic', 'Copy Song B values to Song A')" @click="copyFieldsBetweenSongs('B')">
					<template #icon><ArrowLeftIcon :size="16" /></template>
					{{ t('sheetmusic', 'Copy all to A') }}
				</NcButton>
				<strong>{{ t('sheetmusic', 'Song B') }}</strong>
				<span :class="$style.idBadge">ID {{ songB?.id }}</span>
			</div>

			<!-- Row: Title -->
			<div :class="$style.cellEditable">
				<NcTextField
					:label="t('sheetmusic', 'Song A Title')"
					:value="songA_title"
					@update:value="songA_title = $event" />
			</div>
			<div :class="$style.cellEditable">
				<NcTextField
					:label="t('sheetmusic', 'Song B Title')"
					:value="songB_title"
					@update:value="songB_title = $event" />
			</div>

			<!-- Row: Composers -->
			<div :class="$style.cellEditable">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song A Composers') }}</label>
				<NcSelect
					v-model="songA_composers"
					:options="composerOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>
			<div :class="$style.cellEditable">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song B Composers') }}</label>
				<NcSelect
					v-model="songB_composers"
					:options="composerOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>

			<!-- Row: Books -->
			<div :class="$style.cellEditable">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song A Books') }}</label>
				<NcSelect
					v-model="songA_books"
					:options="bookOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>
			<div :class="$style.cellEditable">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song B Books') }}</label>
				<NcSelect
					v-model="songB_books"
					:options="bookOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>

			<!-- Row: Collections -->
			<div :class="$style.cellEditable">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song A Collections') }}</label>
				<NcSelect
					v-model="songA_collections"
					:options="collectionOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>
			<div :class="$style.cellEditable">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song B Collections') }}</label>
				<NcSelect
					v-model="songB_collections"
					:options="collectionOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>

			<!-- Row: Artists -->
			<div :class="$style.cellEditable">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song A Artists') }}</label>
				<NcSelect
					v-model="songA_artists"
					:options="artistOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>
			<div :class="$style.cellEditable">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song B Artists') }}</label>
				<NcSelect
					v-model="songB_artists"
					:options="artistOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>

			<!-- Row: Genres -->
			<div :class="$style.cellEditable">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song A Genres') }}</label>
				<NcSelect
					v-model="songA_genres"
					:options="genreOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>
			<div :class="$style.cellEditable">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song B Genres') }}</label>
				<NcSelect
					v-model="songB_genres"
					:options="genreOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>

			<!-- Row: Keys -->
			<div :class="$style.cellEditable">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song A Keys') }}</label>
				<NcSelect
					v-model="songA_keys"
					:options="keyOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>
			<div :class="$style.cellEditable">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song B Keys') }}</label>
				<NcSelect
					v-model="songB_keys"
					:options="keyOptions"
					:multiple="true"
					label="label"
					track-by="id" />
			</div>

			<!-- Row: Keywords -->
			<div :class="$style.cellEditable">
				<NcTextField
					:label="t('sheetmusic', 'Song A Keywords')"
					:value="songA_keywords"
					@update:value="songA_keywords = $event" />
			</div>
			<div :class="$style.cellEditable">
				<NcTextField
					:label="t('sheetmusic', 'Song B Keywords')"
					:value="songB_keywords"
					@update:value="songB_keywords = $event" />
			</div>

			<!-- Row: Attached Files -->
			<div :class="$style.cellEditable">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song A Files') }}</label>
				<ul v-if="songA?.files?.length" :class="$style.fileList">
					<li v-for="f in songA.files" :key="f.id" :class="[$style.fileItem, songA_detachedFiles.includes(f.id) && $style.detachedFile]">
						<FileDocumentIcon :size="16" />
						<span :class="$style.fileName">{{ getFileName(f.path) }}</span>
						<NcButton type="tertiary" :title="t('sheetmusic', 'Open File')" @click="openFileExternally(f.path)">
							<template #icon><OpenInNewIcon :size="14" /></template>
						</NcButton>
						<NcButton
							:variant="songA_detachedFiles.includes(f.id) ? 'secondary' : 'tertiary'"
							:title="songA_detachedFiles.includes(f.id) ? t('sheetmusic', 'Keep File') : t('sheetmusic', 'Detach File from Song A')"
							@click="toggleDetachFile('A', f.id)">
							<template #icon><TrashCanOutlineIcon :size="14" /></template>
						</NcButton>
					</li>
				</ul>
				<span v-else :class="$style.val">{{ t('sheetmusic', '(None)') }}</span>
			</div>

			<div :class="$style.cellEditable">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song B Files') }}</label>
				<ul v-if="songB?.files?.length" :class="$style.fileList">
					<li v-for="f in songB.files" :key="f.id" :class="[$style.fileItem, songB_detachedFiles.includes(f.id) && $style.detachedFile]">
						<FileDocumentIcon :size="16" />
						<span :class="$style.fileName">{{ getFileName(f.path) }}</span>
						<NcButton type="tertiary" :title="t('sheetmusic', 'Open File')" @click="openFileExternally(f.path)">
							<template #icon><OpenInNewIcon :size="14" /></template>
						</NcButton>
						<NcButton
							:variant="songB_detachedFiles.includes(f.id) ? 'secondary' : 'tertiary'"
							:title="songB_detachedFiles.includes(f.id) ? t('sheetmusic', 'Keep File') : t('sheetmusic', 'Detach File from Song B')"
							@click="toggleDetachFile('B', f.id)">
							<template #icon><TrashCanOutlineIcon :size="14" /></template>
						</NcButton>
					</li>
				</ul>
				<span v-else :class="$style.val">{{ t('sheetmusic', '(None)') }}</span>
			</div>
		</div>

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

.comparisonGrid {
	display: grid;
	grid-template-columns: 1fr 1.3fr 1fr;
	gap: 12px 16px;
	align-items: center;
}

.keepBothGrid {
	display: grid;
	grid-template-columns: 1fr 1fr;
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

.cellEditable {
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

.detachedFile {
	opacity: 0.5;
	text-decoration: line-through;
}

.fileName {
	flex: 1;
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
