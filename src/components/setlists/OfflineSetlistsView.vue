<template>
	<div :class="$style.offlineRoot">
		<div :class="$style.offlineBanner" role="status">
			<CloudOffOutline :size="18" />
			<span>{{ t('sheetmusic', 'Offline mode — showing setlists saved for offline use') }}</span>
		</div>

		<div v-if="isLoading" :class="$style.emptyState">
			<NcLoadingIcon :size="32" />
		</div>

		<div v-else-if="groups.length === 0" :class="$style.emptyState">
			<PlaylistMusic :size="56" :class="$style.emptyIcon" />
			<p>{{ t('sheetmusic', 'No setlists have been saved for offline use yet.') }}</p>
		</div>

		<div v-else :class="$style.layout">
			<aside :class="$style.sidebar" :aria-label="t('sheetmusic', 'Offline setlists')">
				<div v-for="group in groups" :key="group.libraryId" :class="$style.libraryGroup">
					<h3 :class="$style.libraryHeading">{{ group.libraryPath }}</h3>
					<ul :class="$style.setlistList">
						<li v-for="setlist in group.setlists" :key="setlist.id">
							<button
								type="button"
								:class="[
									$style.setlistButton,
									selectedLibraryId === group.libraryId && selectedSetlist?.id === setlist.id && $style.setlistButtonActive,
								]"
								@click="selectOfflineSetlist(group.libraryId, setlist)">
								<PlaylistMusic :size="18" :class="$style.itemIcon" />
								<span :class="$style.setlistName">{{ setlist.name }}</span>
								<span :class="$style.badge">{{ setlist.itemCount ?? 0 }}</span>
							</button>
						</li>
					</ul>
				</div>
			</aside>

			<section :class="$style.detail">
				<template v-if="selectedSetlist && selectedLibraryId >= 0">
					<SetlistDetailHeader
						:name="selectedSetlist.name"
						:item-count="selectedItems.length"
						:can-write="false"
						:offline-supported="false"
						@play="openPerformance(0)" />

					<SetlistFilesSection
						v-if="selectedFiles.length > 0"
						:files="selectedFiles"
						:can-write="false"
						:is-uploading="false"
						upload-error=""
						@preview="previewingFile = $event" />

					<SetlistItemsTable
						:items="selectedItems"
						:available-songs="selectedSongs"
						:is-loading="isLoadingDetail"
						:can-write="false"
						@open-song="openSongInPerformance" />
				</template>

				<div v-else :class="$style.emptyState">
					<PlaylistMusic :size="56" :class="$style.emptyIcon" />
					<p>{{ t('sheetmusic', 'Select an offline setlist to open it.') }}</p>
				</div>
			</section>
		</div>

		<SetlistPerformanceViewer
			v-if="isPerformanceMode && selectedSetlist && selectedItems.length > 0"
			:setlist-id="selectedSetlist.id"
			:setlist-name="selectedSetlist.name"
			:items="selectedItems"
			:files="selectedFiles"
			:songs="selectedSongs"
			:library-id="selectedLibraryId"
			:initial-index="performanceStartIndex"
			@close="isPerformanceMode = false" />

		<SetlistFilePreviewDialog
			v-if="previewingFile && selectedSetlist"
			:library-id="selectedLibraryId"
			:setlist-id="selectedSetlist.id"
			:file="previewingFile"
			:can-write="false"
			@close="previewingFile = null" />
	</div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { generateOcsUrl } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { translate as t } from '@nextcloud/l10n'
import CloudOffOutline from 'vue-material-design-icons/CloudOffOutline.vue'
import PlaylistMusic from 'vue-material-design-icons/PlaylistMusic.vue'
import { apiGet } from '../../api/client'
import { fetchSetlist, fetchSetlistFiles, fetchSetlists } from '../../api/setlists'
import type { Library } from '../../types/library'
import type { Setlist, SetlistFile, SetlistItem, Song } from '../../types/song'
import { getOfflineSetlistMap, type OfflineSetlistRecord, readOfflineCachedJson } from '../../utils/offlineSetlists'
import SetlistDetailHeader from './SetlistDetailHeader.vue'
import SetlistFilePreviewDialog from './SetlistFilePreviewDialog.vue'
import SetlistFilesSection from './SetlistFilesSection.vue'
import SetlistItemsTable from './SetlistItemsTable.vue'
import SetlistPerformanceViewer from './SetlistPerformanceViewer.vue'

interface OfflineLibraryGroup {
	libraryId: number
	libraryPath: string
	setlists: Setlist[]
}

const props = defineProps<{
	libraries: Library[]
}>()

const isLoading = ref(true)
const isLoadingDetail = ref(false)
const groups = ref<OfflineLibraryGroup[]>([])
const songsByLibrary = ref<Record<number, Song[]>>({})

const selectedLibraryId = ref(-1)
const selectedSetlist = ref<Setlist | null>(null)
const selectedItems = ref<SetlistItem[]>([])
const selectedFiles = ref<SetlistFile[]>([])
const previewingFile = ref<SetlistFile | null>(null)
const isPerformanceMode = ref(false)
const performanceStartIndex = ref(0)

const selectedSongs = computed(() => songsByLibrary.value[selectedLibraryId.value] ?? [])

async function loadLibrarySetlistsWithFallback(libraryId: number, records: OfflineSetlistRecord[]): Promise<Setlist[]> {
	const cached = await readOfflineCachedJson<{ ocs?: { data?: Setlist[] } }>(
		generateOcsUrl(`/apps/sheetmusic/api/libraries/${libraryId}/setlists`),
	)
	if (cached?.ocs?.data) {
		return cached.ocs.data
	}
	try {
		return await fetchSetlists(libraryId)
	} catch {
		return records.map(r => ({
			id: r.setlistId,
			libraryId: r.libraryId,
			name: r.setlistName ?? t('sheetmusic', 'Setlist #{id}', { id: r.setlistId }, { escape: false }),
			itemCount: r.itemCount ?? 0,
		} as Setlist))
	}
}

async function loadLibrarySongsWithFallback(libraryId: number): Promise<Song[]> {
	const cached = await readOfflineCachedJson<{ ocs?: { data?: { songs?: Song[] } } }>(
		`${generateOcsUrl('/apps/sheetmusic/api/songs')}?id=${libraryId}`,
	)
	if (cached?.ocs?.data?.songs) {
		return cached.ocs.data.songs
	}
	try {
		const res = await apiGet('/api/songs', { queryParams: { id: libraryId } })
		return (res.data.ocs.data.songs ?? []) as Song[]
	} catch {
		return []
	}
}

async function loadOfflineGroups() {
	isLoading.value = true
	try {
		const map = getOfflineSetlistMap()
		const byLibrary = new Map<number, OfflineSetlistRecord[]>()
		for (const entry of Object.values(map)) {
			const list = byLibrary.get(entry.libraryId) ?? []
			list.push(entry)
			byLibrary.set(entry.libraryId, list)
		}

		const loadedGroups: OfflineLibraryGroup[] = []
		const loadedSongs: Record<number, Song[]> = {}

		for (const [libraryId, records] of byLibrary.entries()) {
			const setlistIds = new Set(records.map(r => r.setlistId))
			const lib = props.libraries.find(l => l.id === libraryId)
			const libraryPath = lib?.path ?? t('sheetmusic', 'Library #{id}', { id: libraryId }, { escape: false })
			const [allSetlists, librarySongs] = await Promise.all([
				loadLibrarySetlistsWithFallback(libraryId, records),
				loadLibrarySongsWithFallback(libraryId),
			])
			loadedSongs[libraryId] = librarySongs
			const offlineSetlists = allSetlists.filter(s => setlistIds.has(s.id))
			if (offlineSetlists.length > 0) {
				loadedGroups.push({
					libraryId,
					libraryPath,
					setlists: offlineSetlists,
				})
			}
		}

		songsByLibrary.value = loadedSongs
		groups.value = loadedGroups

		if (loadedGroups.length > 0 && loadedGroups[0].setlists.length > 0) {
			await selectOfflineSetlist(loadedGroups[0].libraryId, loadedGroups[0].setlists[0])
		}
	} finally {
		isLoading.value = false
	}
}

async function selectOfflineSetlist(libraryId: number, setlist: Setlist) {
	selectedLibraryId.value = libraryId
	selectedSetlist.value = setlist
	isLoadingDetail.value = true
	try {
		const [cachedDetail, cachedFiles] = await Promise.all([
			readOfflineCachedJson<{ ocs?: { data?: Setlist } }>(
				generateOcsUrl(`/apps/sheetmusic/api/libraries/${libraryId}/setlists/${setlist.id}`),
			),
			readOfflineCachedJson<{ ocs?: { data?: SetlistFile[] } }>(
				generateOcsUrl(`/apps/sheetmusic/api/libraries/${libraryId}/setlists/${setlist.id}/files`),
			),
		])
		const detail = cachedDetail?.ocs?.data
			?? await fetchSetlist(libraryId, setlist.id).catch(() => ({ ...setlist, items: [] }))
		const files = cachedFiles?.ocs?.data
			?? await fetchSetlistFiles(libraryId, setlist.id).catch(() => [])
		selectedItems.value = detail.items ?? []
		selectedFiles.value = files
	} catch {
		selectedItems.value = []
		selectedFiles.value = []
	} finally {
		isLoadingDetail.value = false
	}
}

function openPerformance(index: number) {
	if (!selectedSetlist.value || selectedItems.value.length === 0) return
	performanceStartIndex.value = index
	isPerformanceMode.value = true
}

function openSongInPerformance(item: SetlistItem) {
	const idx = selectedItems.value.findIndex(i => i.id === item.id)
	openPerformance(idx >= 0 ? idx : 0)
}

onMounted(() => {
	void loadOfflineGroups()
})
</script>

<style module>
.offlineRoot {
	display: flex;
	flex-direction: column;
	width: 100%;
	height: 100%;
	min-height: 0;
}

.offlineBanner {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 16px;
	background-color: var(--color-background-dark);
	border-bottom: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
	font-weight: 500;
	flex-shrink: 0;
}

.layout {
	display: grid;
	grid-template-columns: 300px minmax(0, 1fr);
	flex: 1;
	min-height: 0;
	overflow: hidden;
}

@media (max-width: 768px) {
	.layout {
		grid-template-columns: 1fr;
		overflow: auto;
	}
}

.sidebar {
	border-inline-end: 1px solid var(--color-border);
	overflow-y: auto;
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.libraryGroup {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.libraryHeading {
	margin: 0;
	font-size: 0.85rem;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	padding: 0 6px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.setlistList {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.setlistButton {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
	padding: 8px 10px;
	border: 0;
	border-radius: var(--border-radius);
	background: transparent;
	color: inherit;
	font: inherit;
	text-align: start;
	cursor: pointer;
}

.setlistButton:hover {
	background-color: var(--color-background-hover);
}

.setlistButtonActive {
	background-color: var(--color-primary-light);
	font-weight: 600;
}

.itemIcon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.setlistName {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.badge {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	background-color: var(--color-background-darker);
	padding: 2px 8px;
	border-radius: 12px;
	flex-shrink: 0;
}

.detail {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px;
	overflow-y: auto;
	min-height: 0;
}

.emptyState {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	flex: 1;
	padding: 32px;
	gap: 12px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.emptyIcon {
	opacity: 0.5;
}
</style>
