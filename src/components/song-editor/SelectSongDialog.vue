<script setup lang="ts">
import type { Arranger, Artist, Book, Collection, Composer, CustomGroup, FilterEntity, Genre, Instrument, Key, LibraryData, Signature, Song, SourceType, Year } from '../../types/song'
import { computed, ref, type Ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import SongFilterBar from '../SongFilterBar.vue'
import { ENTITY_COLUMNS, type SongEntityProp } from '../songs/song-view-columns'
import MusicIcon from 'vue-material-design-icons/Music.vue'
import PaperclipIcon from 'vue-material-design-icons/Paperclip.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUpIcon from 'vue-material-design-icons/ChevronUp.vue'

const props = defineProps<{
	open: boolean
	orphanPath: string
	filename: string
	libraryId: number
	libraryData: LibraryData | null
	isAttaching?: boolean
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	selectSong: [songId: number]
}>()

const searchTitle = ref('')
const filtersExpanded = ref(false)
const withoutFiles = ref(false)

function toggleFilters() {
	filtersExpanded.value = !filtersExpanded.value
}

const selectedComposers = ref<Composer[]>([])
const selectedBooks = ref<Book[]>([])
const selectedCollections = ref<Collection[]>([])
const selectedArtists = ref<Artist[]>([])
const selectedGenres = ref<Genre[]>([])
const selectedKeys = ref<Key[]>([])
const selectedCustomGroups = ref<CustomGroup[]>([])
const selectedYears = ref<Year[]>([])
const selectedSignatures = ref<Signature[]>([])
const selectedSourceTypes = ref<SourceType[]>([])
const selectedInstruments = ref<Instrument[]>([])
const selectedArrangers = ref<Arranger[]>([])

const allSongs = computed<Song[]>(() => props.libraryData?.songs ?? [])
const composers = computed<Composer[]>(() => props.libraryData?.composers ?? [])
const books = computed<Book[]>(() => props.libraryData?.books ?? [])
const collections = computed<Collection[]>(() => props.libraryData?.collections ?? [])
const artists = computed<Artist[]>(() => props.libraryData?.artists ?? [])
const genres = computed<Genre[]>(() => props.libraryData?.genres ?? [])
const keys = computed<Key[]>(() => props.libraryData?.keys ?? [])
const customGroups = computed<CustomGroup[]>(() => props.libraryData?.customGroups ?? [])
const years = computed<Year[]>(() => props.libraryData?.years ?? [])
const signatures = computed<Signature[]>(() => props.libraryData?.signatures ?? [])
const sourceTypes = computed<SourceType[]>(() => props.libraryData?.sourceTypes ?? [])
const instruments = computed<Instrument[]>(() => props.libraryData?.instruments ?? [])
const arrangers = computed<Arranger[]>(() => props.libraryData?.arrangers ?? [])

const selectedFiltersByProp: Record<SongEntityProp, Ref<FilterEntity[]>> = {
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

function clearFilters() {
	searchTitle.value = ''
	withoutFiles.value = false
	for (const col of ENTITY_COLUMNS) {
		selectedFiltersByProp[col.songProp].value = []
	}
}

function matchesCategory<T extends { id: number }>(songItems: T[] | undefined, selected: T[]): boolean {
	if (selected.length === 0) return true
	const selectedIds = new Set(selected.map((item) => item.id))
	const hasUnassignedSelected = selectedIds.has(-1)
	const isUnassigned = !songItems || songItems.length === 0
	if (hasUnassignedSelected && isUnassigned) return true
	if (songItems && songItems.some((item) => selectedIds.has(item.id))) return true
	return false
}

const filteredSongs = computed<Song[]>(() => {
	const query = searchTitle.value.trim().toLowerCase()

	return allSongs.value.filter((song) => {
		if (query && !song.title.toLowerCase().includes(query)) {
			return false
		}

		if (withoutFiles.value && (song.files?.length ?? 0) > 0) {
			return false
		}

		for (const col of ENTITY_COLUMNS) {
			if (!matchesCategory(col.getItems(song), selectedFiltersByProp[col.songProp].value)) {
				return false
			}
		}

		return true
	})
})

function getSongSubtext(song: Song): string {
	const parts: string[] = []
	if (song.composers?.length) {
		parts.push(song.composers.map((c) => c.name).join(', '))
	}
	if (song.keys?.length) {
		parts.push(t('sheetmusic', 'Key: {key}', { key: song.keys.map((k) => k.name).join(', ') }))
	}
	if (song.books?.length) {
		parts.push(song.books.map((b) => b.title).join(', '))
	}
	return parts.join(' • ')
}
</script>

<template>
	<NcDialog
		v-if="open"
		:name="t('sheetmusic', 'Find Existing Song')"
		size="large"
		@close="emit('update:open', false)">
		<div :class="$style.dialogContent">
			<p :class="$style.introText">
				{{ t('sheetmusic', 'Search and select an existing song to link with "{filename}".', { filename }) }}
			</p>

			<NcButton
				:class="$style.filterToggle"
				variant="secondary"
				:aria-expanded="filtersExpanded"
				aria-controls="select-song-filter-panel"
				@click="toggleFilters">
				<template #icon>
					<ChevronUpIcon v-if="filtersExpanded" :size="18" />
					<ChevronDownIcon v-else :size="18" />
				</template>
				{{ filtersExpanded ? t('sheetmusic', 'Hide filters') : t('sheetmusic', 'Show filters') }}
			</NcButton>

			<div
				id="select-song-filter-panel"
				:class="[$style.filterSection, !filtersExpanded && $style.filtersCollapsed]">
				<SongFilterBar
					v-model:search-title="searchTitle"
					v-model:without-files="withoutFiles"
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
					@clear="clearFilters" />
			</div>

			<div :class="$style.resultsCount">
				{{ t('sheetmusic', '{count} song(s) found', { count: filteredSongs.length }) }}
			</div>

			<div :class="$style.songListContainer">
				<div v-if="filteredSongs.length === 0" :class="$style.emptyState">
					<MusicIcon :size="40" />
					<p>{{ t('sheetmusic', 'No songs match the current search filters.') }}</p>
				</div>

				<ul v-else :class="$style.songList">
					<li v-for="song in filteredSongs" :key="song.id" :class="$style.songItem">
						<div :class="$style.songInfo">
							<span :class="$style.songTitle">{{ song.title }}</span>
							<span v-if="getSongSubtext(song)" :class="$style.songSubtext">{{ getSongSubtext(song) }}</span>
							<span :class="$style.fileBadge">
								<PaperclipIcon :size="14" />
								{{ t('sheetmusic', '{count} file(s)', { count: song.files?.length ?? 0 }) }}
							</span>
						</div>

						<NcButton
							variant="primary"
							size="small"
							:disabled="isAttaching"
							@click="emit('selectSong', song.id)">
							{{ isAttaching ? t('sheetmusic', 'Linking…') : t('sheetmusic', 'Select & Attach') }}
						</NcButton>
					</li>
				</ul>
			</div>
		</div>

		<template #actions>
			<NcButton @click="emit('update:open', false)">
				{{ t('sheetmusic', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style module>
.dialogContent {
	display: flex;
	flex-direction: column;
	gap: 16px;
	min-width: 500px;
	max-height: 70vh;
}

.introText {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.95rem;
}

.filterSection {
	padding: 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large, 8px);
}

.resultsCount {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.songListContainer {
	max-height: 360px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

.emptyState {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 40px;
	gap: 12px;
	color: var(--color-text-maxcontrast);
}

.songList {
	margin: 0;
	padding: 0;
	list-style: none;
}

.songItem {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
	gap: 16px;
}

.songItem:last-child {
	border-bottom: none;
}

.songItem:hover {
	background-color: var(--color-background-hover);
}

.songInfo {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 0;
}

.songTitle {
	font-weight: 600;
	font-size: 1rem;
	color: var(--color-main-text);
}

.songSubtext {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.fileBadge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 0.78rem;
	color: var(--color-text-maxcontrast);
}

@media (max-width: 768px) {
	.dialogContent {
		width: 100%;
		min-width: 0;
		max-width: 100%;
		max-height: calc(100dvh - 140px);
		overflow-y: auto;
		gap: 12px;
		box-sizing: border-box;
	}

	.filterSection {
		min-width: 0;
		max-height: none;
		overflow: visible;
	}

	.filterToggle {
		display: inline-flex;
		align-self: flex-start;
	}

	.filtersCollapsed {
		display: none;
	}

	.resultsCount {
		flex: 0 0 auto;
	}

	.songListContainer {
		flex: 1 1 auto;
		min-width: 0;
		min-height: 120px;
		max-height: 30dvh;
		overflow: auto;
	}

	.songItem {
		align-items: flex-start;
		flex-wrap: wrap;
		gap: 8px;
		padding: 12px;
	}

	.songInfo {
		width: 100%;
		overflow-wrap: anywhere;
	}

	.songItem > :global(button) {
		width: 100%;
		justify-content: center;
	}
}

@media (min-width: 769px) {
	.filterToggle {
		display: none;
	}
}
</style>
