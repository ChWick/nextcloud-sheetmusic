<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import FilePreviewer from '../FilePreviewer.vue'
import SetlistPerformanceBottomBar from './SetlistPerformanceBottomBar.vue'
import SetlistPerformanceDrawer from './SetlistPerformanceDrawer.vue'
import SetlistPerformanceSettingsPanel from './SetlistPerformanceSettingsPanel.vue'
import SetlistPerformanceTopBar from './SetlistPerformanceTopBar.vue'
import { fetchSongAnnotations } from '../../api/annotations'
import { useHudAutoHide } from '../../composables/useHudAutoHide'
import { usePerformanceStageInput } from '../../composables/usePerformanceStageInput'
import { useWakeLock } from '../../composables/useWakeLock'
import type { SheetAnnotation } from '../../types/annotation'
import type { SetlistItem, Song, SongFile } from '../../types/song'
import { defaultFileBinaryCache } from '../../utils/file-cache'
import { getFileVersionToken, getVersionedFileUrl } from '../../utils/file-version'
import type { DoublePageTurnMode } from '../../utils/page-turn'
import {
	createLibraryPerformanceStore,
	createSetlistPerformanceStore,
	loadEffectivePerformanceSettings,
	type PerformanceLayoutChoice,
	type PerformanceViewerSettings,
	type PerformanceZoomChoice,
} from '../../utils/performance-settings'

const props = withDefaults(defineProps<{
	setlistId?: number
	setlistName: string
	items: SetlistItem[]
	songs: Song[]
	libraryId: number
	initialIndex?: number
}>(), {
	setlistId: undefined,
	initialIndex: 0,
})

const emit = defineEmits<{
	close: []
}>()

const rootRef = ref<HTMLElement | null>(null)
const previewerRef = ref<InstanceType<typeof FilePreviewer> | null>(null)

const initialSettings = loadEffectivePerformanceSettings(props.libraryId, props.setlistId)

const currentIndex = ref(Math.min(Math.max(0, props.initialIndex), Math.max(0, props.items.length - 1)))
const startAtEnd = ref(false)
const isDrawerOpen = ref(false)
const isSettingsOpen = ref(false)
const isFullscreen = ref(false)
const isSetlistOverride = ref(initialSettings.overrideEnabled)
const showAnnotations = ref(initialSettings.settings.showAnnotations)

const layoutChoice = ref<PerformanceLayoutChoice>(initialSettings.settings.layoutChoice)
const doublePageTurnMode = ref<DoublePageTurnMode>(initialSettings.settings.doublePageTurnMode)
const zoomChoice = ref<PerformanceZoomChoice>(initialSettings.settings.zoomChoice)

const {
	isHudVisible,
	clearHudTimer,
	scheduleHudAutoHide,
	toggleHud,
	pauseHudAutoHide,
	resumeHudAutoHide,
} = useHudAutoHide(3000, () => isDrawerOpen.value || isSettingsOpen.value)

useWakeLock()

function currentViewerSettings(): PerformanceViewerSettings {
	return {
		layoutChoice: layoutChoice.value,
		doublePageTurnMode: doublePageTurnMode.value,
		zoomChoice: zoomChoice.value,
		showAnnotations: showAnnotations.value,
	}
}

function persistCurrentSettings() {
	if (props.libraryId <= 0) return
	const settings = currentViewerSettings()
	if (isSetlistOverride.value && props.setlistId && props.setlistId > 0) {
		createSetlistPerformanceStore(props.libraryId, props.setlistId).save({
			...settings,
			overrideEnabled: true,
		})
	} else {
		createLibraryPerformanceStore(props.libraryId).save(settings)
	}
}

function applySettingsToPreviewer(settings: PerformanceViewerSettings) {
	layoutChoice.value = settings.layoutChoice
	if (settings.layoutChoice === 'auto') {
		previewerRef.value?.setAutoLayout(true)
	} else {
		previewerRef.value?.setPageLayout(settings.layoutChoice)
	}
	doublePageTurnMode.value = settings.doublePageTurnMode
	previewerRef.value?.setDoublePageTurnMode(settings.doublePageTurnMode)
	zoomChoice.value = settings.zoomChoice
	previewerRef.value?.setZoomMode(settings.zoomChoice)
	if (showAnnotations.value !== settings.showAnnotations) {
		showAnnotations.value = settings.showAnnotations
		previewerRef.value?.toggleAnnotations()
	}
}

function toggleSetlistOverride(enabled: boolean) {
	if (!props.setlistId || props.setlistId <= 0 || props.libraryId <= 0) return
	isSetlistOverride.value = enabled
	const setlistStore = createSetlistPerformanceStore(props.libraryId, props.setlistId)
	if (enabled) {
		setlistStore.save({
			...currentViewerSettings(),
			overrideEnabled: true,
		})
	} else {
		const libraryDefaults = createLibraryPerformanceStore(props.libraryId).get()
		setlistStore.save({
			...libraryDefaults,
			overrideEnabled: false,
		})
		applySettingsToPreviewer(libraryDefaults)
	}
}

function saveCurrentAsLibraryDefault() {
	if (props.libraryId <= 0) return
	createLibraryPerformanceStore(props.libraryId).save(currentViewerSettings())
}

function toggleSettingsPanel() {
	isSettingsOpen.value = !isSettingsOpen.value
	if (isSettingsOpen.value) {
		isDrawerOpen.value = false
		clearHudTimer()
	} else {
		scheduleHudAutoHide()
	}
}

function toggleDrawer() {
	isDrawerOpen.value = !isDrawerOpen.value
	if (isDrawerOpen.value) {
		isSettingsOpen.value = false
		clearHudTimer()
	} else {
		scheduleHudAutoHide()
	}
}

const annotations = ref<SheetAnnotation[]>([])
let annotationRequestId = 0

const songsById = computed(() => {
	const map = new Map<number, Song>()
	for (const song of props.songs) {
		map.set(song.id, song)
	}
	return map
})

const currentItem = computed<SetlistItem | null>(() => props.items[currentIndex.value] ?? null)

const currentSong = computed<Song | null>(() => {
	if (!currentItem.value) return null
	return songsById.value.get(currentItem.value.songId) ?? null
})

const currentSongTitle = computed(() => {
	return currentSong.value?.title || currentItem.value?.songTitle || t('sheetmusic', 'Unknown Song')
})

function pickPrimaryFile(song: Song | null): SongFile | null {
	if (!song) return null
	const files = song.files ?? []
	if (files.length === 0) return null
	return files.find(f => f.path?.toLowerCase().endsWith('.pdf'))
		?? files.find(f => f.isPrimary)
		?? files[0]
		?? null
}

function buildVersionedPath(file: SongFile | null): string {
	if (!file?.path) return ''
	return getVersionedFileUrl(file.path, getFileVersionToken(file))
}

const currentFile = computed(() => pickPrimaryFile(currentSong.value))
const currentFilePath = computed(() => buildVersionedPath(currentFile.value))
const currentPageOrder = computed(() => currentFile.value?.pageOrder ?? null)
const currentTargetPage = computed(() => {
	if (startAtEnd.value) return undefined
	return currentItem.value?.pageNum && currentItem.value.pageNum >= 1 ? currentItem.value.pageNum : 1
})

const effectiveLayout = computed(() => {
	if (layoutChoice.value !== 'auto') return layoutChoice.value
	return previewerRef.value?.pageLayout ?? 'single'
})
const currentPageDisplay = computed(() => previewerRef.value?.currentPageDisplay ?? '1')
const totalPages = computed(() => previewerRef.value?.totalPages ?? 1)
const hasPrevSong = computed(() => currentIndex.value > 0)
const hasNextSong = computed(() => currentIndex.value < props.items.length - 1)

function preloadNextSong() {
	const nextItem = props.items[currentIndex.value + 1]
	if (!nextItem) return
	const nextSong = songsById.value.get(nextItem.songId) ?? null
	const nextFile = pickPrimaryFile(nextSong)
	const nextPath = buildVersionedPath(nextFile)
	if (!nextPath || !nextPath.toLowerCase().split('?')[0].endsWith('.pdf')) return
	if (defaultFileBinaryCache.has(nextPath)) return

	axios.get(nextPath, { responseType: 'arraybuffer' })
		.then((resp) => {
			if (resp.data) {
				const bytes = new Uint8Array(resp.data)
				if (bytes.length >= 4 && bytes[0] === 0x25 && bytes[1] === 0x50 && bytes[2] === 0x44 && bytes[3] === 0x46) {
					defaultFileBinaryCache.set(nextPath, bytes)
				}
			}
		})
		.catch(() => {
			// Ignore background preload errors
		})
}

watch(currentSong, async (song) => {
	const reqId = ++annotationRequestId
	if (!song || props.libraryId <= 0) {
		annotations.value = []
		return
	}
	try {
		const list = await fetchSongAnnotations(props.libraryId, song.id)
		if (reqId === annotationRequestId) {
			annotations.value = list
		}
	} catch {
		if (reqId === annotationRequestId) {
			annotations.value = []
		}
	}
}, { immediate: true })

watch(currentIndex, () => {
	preloadNextSong()
}, { immediate: true })

function goToSong(index: number, openAtEnd = false) {
	if (index < 0 || index >= props.items.length) return
	startAtEnd.value = openAtEnd
	currentIndex.value = index
	isDrawerOpen.value = false
	scheduleHudAutoHide()
}

function handleNextPage() {
	const turned = previewerRef.value?.nextPage()
	if (!turned && hasNextSong.value) {
		goToSong(currentIndex.value + 1, false)
	}
}

function handlePrevPage() {
	const turned = previewerRef.value?.prevPage()
	if (!turned && hasPrevSong.value) {
		goToSong(currentIndex.value - 1, true)
	}
}

function onLayoutChange(choice: PerformanceLayoutChoice) {
	layoutChoice.value = choice
	if (choice === 'auto') {
		previewerRef.value?.setAutoLayout(true)
	} else {
		previewerRef.value?.setPageLayout(choice)
	}
	persistCurrentSettings()
}

function onDoublePageTurnModeChange(mode: DoublePageTurnMode) {
	doublePageTurnMode.value = mode
	previewerRef.value?.setDoublePageTurnMode(mode)
	persistCurrentSettings()
}

function onZoomToggle() {
	const next = zoomChoice.value === 'fit-page' ? 'fit-width' : 'fit-page'
	zoomChoice.value = next
	previewerRef.value?.setZoomMode(next)
	persistCurrentSettings()
}

function onToggleAnnotations() {
	showAnnotations.value = !showAnnotations.value
	previewerRef.value?.toggleAnnotations()
	persistCurrentSettings()
}

async function toggleFullscreen() {
	try {
		if (!document.fullscreenElement && rootRef.value?.requestFullscreen) {
			await rootRef.value.requestFullscreen()
		} else if (document.fullscreenElement && document.exitFullscreen) {
			await document.exitFullscreen()
		}
	} catch {
		// Fullscreen API may be blocked by browser policy
	}
}

function handleFullscreenChange() {
	isFullscreen.value = Boolean(document.fullscreenElement)
}

const { onStageTouchStart, onStageTouchEnd, onStageClick } = usePerformanceStageInput({
	isScrollMode: () => effectiveLayout.value === 'continuous',
	onNextPage: handleNextPage,
	onPrevPage: handlePrevPage,
	onToggleHud: toggleHud,
	onEscape: () => {
		if (isSettingsOpen.value) {
			isSettingsOpen.value = false
			scheduleHudAutoHide()
		} else if (isDrawerOpen.value) {
			isDrawerOpen.value = false
			scheduleHudAutoHide()
		} else {
			emit('close')
		}
	},
})

onMounted(() => {
	document.addEventListener('fullscreenchange', handleFullscreenChange)
	scheduleHudAutoHide()
})

onUnmounted(() => {
	document.removeEventListener('fullscreenchange', handleFullscreenChange)
	if (document.fullscreenElement && document.exitFullscreen) {
		document.exitFullscreen().catch(() => {})
	}
})
</script>

<template>
	<Teleport to="body">
		<div ref="rootRef" :class="$style.overlay" role="dialog" aria-modal="true" :aria-label="setlistName">
			<SetlistPerformanceTopBar
				:visible="isHudVisible"
				:setlist-name="setlistName"
				:song-title="currentSongTitle"
				:bookmark-name="currentItem?.bookmarkName"
				:item-index="currentIndex"
				:item-count="items.length"
				:show-annotations="showAnnotations"
				:is-fullscreen="isFullscreen"
				:show-settings-button="Boolean(setlistId && setlistId > 0)"
				:is-settings-open="isSettingsOpen"
				:is-setlist-override="isSetlistOverride"
				@exit="emit('close')"
				@toggle-drawer="toggleDrawer"
				@toggle-annotations="onToggleAnnotations"
				@toggle-settings="toggleSettingsPanel"
				@toggle-fullscreen="toggleFullscreen"
				@pause-auto-hide="pauseHudAutoHide"
				@resume-auto-hide="resumeHudAutoHide" />

			<div
				:class="$style.stage"
				@click="onStageClick"
				@touchstart.passive="onStageTouchStart"
				@touchend.passive="onStageTouchEnd">
				<FilePreviewer
					v-if="currentFilePath"
					:key="`${currentIndex}-${currentFilePath}`"
					ref="previewerRef"
					:file-path="currentFilePath"
					:page-order="currentPageOrder"
					:target-page="currentTargetPage"
					:start-at-end="startAtEnd"
					:annotations="annotations"
					:show-annotations="showAnnotations"
					:hide-toolbar="true"
					:show-external-button="false"
					:no-padding="true"
					:initial-page-layout="layoutChoice"
					:initial-double-page-turn-mode="doublePageTurnMode"
					:initial-zoom-mode="zoomChoice" />
				<div v-else :class="$style.emptyFile">
					{{ t('sheetmusic', 'No score file attached to this song.') }}
				</div>
			</div>

			<SetlistPerformanceBottomBar
				:visible="isHudVisible"
				:has-prev-song="hasPrevSong"
				:has-next-song="hasNextSong"
				:can-go-prev-page="Boolean(previewerRef?.canGoPrev)"
				:can-go-next-page="Boolean(previewerRef?.canGoNext)"
				:current-page-display="currentPageDisplay"
				:total-pages="totalPages"
				:layout-choice="layoutChoice"
				:effective-layout="effectiveLayout"
				:double-page-turn-mode="doublePageTurnMode"
				:zoom-choice="zoomChoice"
				@prev-song="goToSong(currentIndex - 1, false)"
				@prev-page="handlePrevPage"
				@next-page="handleNextPage"
				@next-song="goToSong(currentIndex + 1, false)"
				@change-layout="onLayoutChange"
				@change-double-page-turn-mode="onDoublePageTurnModeChange"
				@toggle-zoom="onZoomToggle"
				@pause-auto-hide="pauseHudAutoHide"
				@resume-auto-hide="resumeHudAutoHide" />

			<SetlistPerformanceDrawer
				:open="isDrawerOpen"
				:setlist-name="setlistName"
				:items="items"
				:current-index="currentIndex"
				@close="toggleDrawer"
				@select="goToSong($event, false)" />

			<SetlistPerformanceSettingsPanel
				:open="isSettingsOpen"
				:setlist-name="setlistName"
				:is-setlist-override="isSetlistOverride"
				@close="toggleSettingsPanel"
				@update:is-setlist-override="toggleSetlistOverride"
				@save-as-library-default="saveCurrentAsLibraryDefault" />
		</div>
	</Teleport>
</template>

<style module>
.overlay {
	position: fixed;
	inset: 0;
	width: 100vw;
	height: 100vh;
	z-index: 100000;
	display: flex;
	flex-direction: column;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.stage {
	position: relative;
	flex: 1;
	min-height: 0;
	display: flex;
	flex-direction: column;
	overflow: hidden;
	user-select: none;
}

.emptyFile {
	margin: auto;
	padding: 24px;
	color: var(--color-text-maxcontrast);
}
</style>
