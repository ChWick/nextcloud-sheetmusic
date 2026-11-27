<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue'
import FilePreviewer from '../FilePreviewer.vue'
import SetlistPerformanceBottomBar from './SetlistPerformanceBottomBar.vue'
import SetlistPerformanceDrawer from './SetlistPerformanceDrawer.vue'
import SetlistPerformancePageStrip, { type SetlistStripSong } from './SetlistPerformancePageStrip.vue'
import SetlistPerformanceSettingsPanel from './SetlistPerformanceSettingsPanel.vue'
import SetlistPerformanceSupplementalDrawer from './SetlistPerformanceSupplementalDrawer.vue'
import SetlistPerformanceTopBar from './SetlistPerformanceTopBar.vue'
import ConcertModeUnlockBadge from './ConcertModeUnlockBadge.vue'
import PerformanceToolsButtons from './PerformanceToolsButtons.vue'
import { fetchSongAnnotations } from '../../api/annotations'
import { useAutoScroll } from '../../composables/useAutoScroll'
import { useHudAutoHide } from '../../composables/useHudAutoHide'
import { useMetronome } from '../../composables/useMetronome'
import { usePerformanceStageInput } from '../../composables/usePerformanceStageInput'
import { usePitchPipe } from '../../composables/usePitchPipe'
import { useScoreAppearance } from '../../composables/useScoreAppearance'
import { useWakeLock } from '../../composables/useWakeLock'
import {
	extractSongKeyPitchClass,
	extractSongTempo,
	extractSongTimeSignature,
} from '../../utils/music-tools'
import type { AnnotationLayer, SheetAnnotation } from '../../types/annotation'
import {
	createDefaultAnnotationLayers,
	filterAndSortAnnotationsForLayers,
	getCachedFileAnnotationLayers,
	loadFileAnnotationLayers,
	persistFileAnnotationLayers,
	toggleAnnotationLayerVisibility,
} from '../../utils/annotationLayers'
import type { SetlistFile, SetlistItem, Song, SongFile } from '../../types/song'
import { defaultFileBinaryCache } from '../../utils/file-cache'
import { isPdfFile, pickPrimarySongFile } from '../../utils/file'
import { getFileVersionToken, getVersionedFileUrl } from '../../utils/file-version'
import type { DoublePageTurnMode } from '../../utils/page-turn'
import {
	createLibraryPerformanceStore,
	createSetlistPerformanceStore,
	getEffectiveStageBackground,
	loadEffectivePerformanceSettings,
	type PerformanceLayoutChoice,
	type PerformanceViewerSettings,
	type PerformanceZoomChoice,
} from '../../utils/performance-settings'

const props = withDefaults(defineProps<{
	setlistId?: number
	setlistName?: string
	items: SetlistItem[]
	files?: SetlistFile[]
	songs: Song[]
	libraryId: number
	initialIndex?: number
	preferredFileId?: string | number
	initialAnnotations?: SheetAnnotation[]
}>(), {
	setlistId: undefined,
	setlistName: '',
	files: () => [],
	initialIndex: 0,
	preferredFileId: undefined,
	initialAnnotations: undefined,
})

const emit = defineEmits<{
	close: []
	'update:current-index': [index: number]
}>()

const rootRef = ref<HTMLElement | null>(null)
const previewerRef = ref<InstanceType<typeof FilePreviewer> | null>(null)
const toolsButtonsRef = ref<InstanceType<typeof PerformanceToolsButtons> | null>(null)

const initialSettings = loadEffectivePerformanceSettings(props.libraryId, props.setlistId)

const currentIndex = ref(Math.min(Math.max(0, props.initialIndex), Math.max(0, props.items.length - 1)))
const startAtEnd = ref(false)
const skipFirstPageOnNextSong = ref(false)
const jumpTargetPage = ref<number | null>(null)
const isDrawerOpen = ref(false)
const isSettingsOpen = ref(false)
const isSupplementalDrawerOpen = ref(false)
const isPagePickerOpen = ref(false)
const isToolsPopoverOpen = ref(false)
const isAppearancePopoverOpen = ref(false)
const isAutoScrollPopoverOpen = ref(false)
const isConcertMode = ref(false)
const isFullscreen = ref(typeof document !== 'undefined' && Boolean(document.fullscreenElement))
let keepFullscreenOnUnmount = false
const isSetlistOverride = ref(initialSettings.overrideEnabled)
const showAnnotations = ref(initialSettings.settings.showAnnotations)
const interleaveSongs = ref(initialSettings.settings.interleaveSongs)
const backgroundColor = ref(initialSettings.settings.backgroundColor)
const tightCrop = ref(initialSettings.settings.tightCrop)
const {
	scoreColorMode,
	scoreBrightness,
	scoreSepia,
	isNightMode,
	apply: applyScoreAppearance,
	setMode: onUpdateScoreColorMode,
	toggleNight: onToggleNightMode,
	setBrightness: onUpdateScoreBrightness,
	setSepia: onUpdateScoreSepia,
} = useScoreAppearance({
	initial: initialSettings.settings,
	onChange: () => persistCurrentSettings(),
})
const effectiveOverlayBackground = computed(() =>
	getEffectiveStageBackground(backgroundColor.value, scoreColorMode.value, scoreSepia.value),
)

const layoutChoice = ref<PerformanceLayoutChoice>(initialSettings.settings.layoutChoice)
const doublePageTurnMode = ref<DoublePageTurnMode>(initialSettings.settings.doublePageTurnMode)
const zoomChoice = ref<PerformanceZoomChoice>(initialSettings.settings.zoomChoice)

const metronome = useMetronome()
const pitchPipe = usePitchPipe()
const autoScroll = useAutoScroll({
	getContainer: () => previewerRef.value?.getScrollContainer?.() ?? null,
	getSongDurationSeconds: () => currentSong.value?.duration ?? null,
	onBeforeStart: () => {
		if (effectiveLayout.value !== 'continuous') {
			onLayoutChange('continuous')
		}
	},
	initialSpeed: initialSettings.settings.autoScrollSpeed,
	initialDelay: initialSettings.settings.autoScrollDelay,
	onSettingsChange: () => persistCurrentSettings(),
})

const {
	isHudVisible,
	clearHudTimer,
	scheduleHudAutoHide,
	toggleHud,
	pauseHudAutoHide,
	resumeHudAutoHide,
} = useHudAutoHide(3000, () =>
	isDrawerOpen.value
	|| isSettingsOpen.value
	|| isSupplementalDrawerOpen.value
	|| isPagePickerOpen.value
	|| isToolsPopoverOpen.value
	|| isAppearancePopoverOpen.value
	|| isAutoScrollPopoverOpen.value,
)

useWakeLock()

function currentViewerSettings(): PerformanceViewerSettings {
	return {
		layoutChoice: layoutChoice.value,
		doublePageTurnMode: doublePageTurnMode.value,
		zoomChoice: zoomChoice.value,
		showAnnotations: showAnnotations.value,
		interleaveSongs: interleaveSongs.value,
		backgroundColor: backgroundColor.value,
		tightCrop: tightCrop.value,
		scoreColorMode: scoreColorMode.value,
		scoreBrightness: scoreBrightness.value,
		scoreSepia: scoreSepia.value,
		autoScrollSpeed: autoScroll.speedPxPerSec.value,
		autoScrollDelay: autoScroll.startDelaySeconds.value,
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
	interleaveSongs.value = settings.interleaveSongs
	backgroundColor.value = settings.backgroundColor
	tightCrop.value = settings.tightCrop
	applyScoreAppearance(settings)
	autoScroll.applySettings(settings)
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
const annotationLayers = ref<AnnotationLayer[]>(createDefaultAnnotationLayers())
let annotationRequestId = 0

const visibleAnnotations = computed(() =>
	filterAndSortAnnotationsForLayers(annotations.value, annotationLayers.value),
)

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

const currentSongTempo = computed(() => extractSongTempo(currentSong.value))
const currentSongTimeSignature = computed(() => extractSongTimeSignature(currentSong.value))
const currentSongKeyPitchClass = computed(() => extractSongKeyPitchClass(currentSong.value))
const currentSongKeyLabel = computed(() => currentSong.value?.keys?.[0]?.name ?? null)
const metronomeBeatIndices = computed(() =>
	Array.from({ length: metronome.timeSignature.value.beats }, (_, i) => i),
)

watch(currentSong, (song) => {
	metronome.syncFromSong(song)
}, { immediate: true })

const isSingleSongMode = computed(() => !props.setlistId && props.items.length === 1)

function pickPrimaryFile(song: Song | null, preferredFileId?: string | number | null): SongFile | null {
	return pickPrimarySongFile(song?.files, preferredFileId)
}

function buildVersionedPath(file: SongFile | null): string {
	if (!file?.path) return ''
	return getVersionedFileUrl(file.path, getFileVersionToken(file))
}

const currentFile = computed(() => pickPrimaryFile(currentSong.value, isSingleSongMode.value ? props.preferredFileId : undefined))
const currentFilePath = computed(() => buildVersionedPath(currentFile.value))
const currentPageOrder = computed(() => currentFile.value?.pageOrder ?? null)
const currentTargetPage = computed(() => {
	if (startAtEnd.value) return undefined
	if (jumpTargetPage.value !== null && jumpTargetPage.value >= 1) {
		return jumpTargetPage.value
	}
	const base = currentItem.value?.pageNum && currentItem.value.pageNum >= 1 ? currentItem.value.pageNum : 1
	return skipFirstPageOnNextSong.value ? base + 1 : base
})

const setlistStripSongs = computed<SetlistStripSong[]>(() => {
	return props.items.map((item, idx) => {
		const song = songsById.value.get(item.songId) ?? null
		const file = pickPrimaryFile(song, isSingleSongMode.value && idx === 0 ? props.preferredFileId : undefined)
		const filePath = buildVersionedPath(file)
		const title = song?.title || item.songTitle || t('sheetmusic', 'Unknown Song')
		const pageCount = file?.pageCount && file.pageCount > 0 ? file.pageCount : 1
		return {
			itemIndex: idx,
			title,
			bookmarkName: item.bookmarkName,
			filePath,
			pageOrder: file?.pageOrder ?? null,
			pageCount,
		}
	})
})

const nextItem = computed<SetlistItem | null>(() => props.items[currentIndex.value + 1] ?? null)
const nextSong = computed<Song | null>(() => {
	if (!nextItem.value) return null
	return songsById.value.get(nextItem.value.songId) ?? null
})
const nextFile = computed(() => pickPrimaryFile(nextSong.value))
const nextFilePath = computed(() => buildVersionedPath(nextFile.value))
const nextPageOrder = computed(() => nextFile.value?.pageOrder ?? null)
const nextTargetPage = computed(() => {
	return nextItem.value?.pageNum && nextItem.value.pageNum >= 1 ? nextItem.value.pageNum : 1
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
	if (!nextPath || !isPdfFile(nextPath)) return
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

watch([currentSong, currentFile], async ([song, file]) => {
	const reqId = ++annotationRequestId
	const targetFileId = Number(file?.fileId ?? file?.id ?? song?.files?.[0]?.fileId ?? song?.files?.[0]?.id ?? 0)
	annotationLayers.value = getCachedFileAnnotationLayers(targetFileId)
	if (targetFileId > 0) {
		void loadFileAnnotationLayers(targetFileId).then((loaded) => {
			if (reqId === annotationRequestId) {
				annotationLayers.value = loaded
			}
		})
	}
	if (!song || props.libraryId <= 0) {
		annotations.value = []
		return
	}
	if (isSingleSongMode.value && props.initialAnnotations !== undefined) {
		annotations.value = props.initialAnnotations
		return
	}
	try {
		const list = await fetchSongAnnotations(song.id, targetFileId || undefined)
		if (reqId === annotationRequestId) {
			annotations.value = list
		}
	} catch {
		if (reqId === annotationRequestId) {
			annotations.value = []
		}
	}
}, { immediate: true })

watch(currentIndex, (index) => {
	emit('update:current-index', index)
	preloadNextSong()
}, { immediate: true })

function goToSong(index: number, openAtEnd = false, skipFirstPage = false, explicitTargetPage: number | null = null) {
	if (index < 0 || index >= props.items.length) return
	startAtEnd.value = openAtEnd
	skipFirstPageOnNextSong.value = skipFirstPage
	jumpTargetPage.value = explicitTargetPage
	currentIndex.value = index
	isDrawerOpen.value = false
	isPagePickerOpen.value = false
	scheduleHudAutoHide()
}

function onJumpToPage(payload: { itemIndex: number; page: number }) {
	if (payload.itemIndex === currentIndex.value) {
		previewerRef.value?.goToPage(payload.page)
		isPagePickerOpen.value = false
		scheduleHudAutoHide()
		return
	}
	goToSong(payload.itemIndex, false, false, payload.page)
}

function handleNextPage() {
	const wasInterleaved = Boolean(previewerRef.value?.isShowingNextSongFirstPage)
	const turned = previewerRef.value?.nextPage()
	if (!turned && hasNextSong.value) {
		const shouldSkipFirst = wasInterleaved
			&& (doublePageTurnMode.value === 'half' || doublePageTurnMode.value === 'spread')
		goToSong(currentIndex.value + 1, false, shouldSkipFirst)
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

function onToggleInterleave() {
	interleaveSongs.value = !interleaveSongs.value
	persistCurrentSettings()
}

function onUpdateBackgroundColor(color: string) {
	backgroundColor.value = color
	persistCurrentSettings()
}

function onUpdateTightCrop(enabled: boolean) {
	tightCrop.value = enabled
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

function onToggleLayerVisibility(layerId: number) {
	const next = toggleAnnotationLayerVisibility(annotationLayers.value, layerId)
	annotationLayers.value = next
	const targetFileId = Number(
		currentFile.value?.fileId ?? currentFile.value?.id
		?? currentSong.value?.files?.[0]?.fileId ?? currentSong.value?.files?.[0]?.id ?? 0,
	)
	if (targetFileId > 0) {
		void persistFileAnnotationLayers(targetFileId, next)
	}
	const toggled = next.find((l) => l.id === layerId)
	if (toggled?.visible && !showAnnotations.value) {
		showAnnotations.value = true
		previewerRef.value?.toggleAnnotations()
	}
}

async function toggleFullscreen() {
	try {
		const target = document.documentElement?.requestFullscreen ? document.documentElement : rootRef.value
		if (!document.fullscreenElement && target?.requestFullscreen) {
			await target.requestFullscreen()
		} else if (document.fullscreenElement && document.exitFullscreen) {
			await document.exitFullscreen()
		}
	} catch {
		// Fullscreen API may be blocked by browser policy
	}
}

function handleStop() {
	keepFullscreenOnUnmount = true
	emit('close')
}

function enterConcertMode() {
	toolsButtonsRef.value?.closeOpenPopover()
	isSettingsOpen.value = false
	isDrawerOpen.value = false
	isSupplementalDrawerOpen.value = false
	isPagePickerOpen.value = false
	isAppearancePopoverOpen.value = false
	isAutoScrollPopoverOpen.value = false
	isConcertMode.value = true
	clearHudTimer()
	isHudVisible.value = false
}

function exitConcertMode() {
	isConcertMode.value = false
	isHudVisible.value = true
	scheduleHudAutoHide()
}

function handleFullscreenChange() {
	isFullscreen.value = Boolean(document.fullscreenElement)
}

const { onStageTouchStart, onStageTouchEnd, onStageClick } = usePerformanceStageInput({
	isScrollMode: () => effectiveLayout.value === 'continuous',
	isPanningOrPinching: () => Boolean(
		previewerRef.value?.wasRecentlyPinching?.() || previewerRef.value?.wasRecentlyDragging?.(),
	),
	canPanHorizontally: () => Boolean(previewerRef.value?.canPanHorizontally?.()),
	onNextPage: handleNextPage,
	onPrevPage: handlePrevPage,
	onToggleHud: () => {
		if (isConcertMode.value) return
		toggleHud()
	},
	onSpace: () => {
		if (effectiveLayout.value === 'continuous' || autoScroll.isActive.value || autoScroll.isPaused.value) {
			autoScroll.toggle()
			return true
		}
		return false
	},
	onStageTap: () => {
		if (autoScroll.isActive.value) {
			autoScroll.pause()
			return true
		}
		return false
	},
	onEscape: () => {
		if (isConcertMode.value) {
			return
		}
		if (toolsButtonsRef.value?.closeOpenPopover()) {
			scheduleHudAutoHide()
		} else if (isAutoScrollPopoverOpen.value) {
			isAutoScrollPopoverOpen.value = false
			scheduleHudAutoHide()
		} else if (isAppearancePopoverOpen.value) {
			isAppearancePopoverOpen.value = false
			scheduleHudAutoHide()
		} else if (isPagePickerOpen.value) {
			isPagePickerOpen.value = false
			scheduleHudAutoHide()
		} else if (isSupplementalDrawerOpen.value) {
			isSupplementalDrawerOpen.value = false
			scheduleHudAutoHide()
		} else if (isSettingsOpen.value) {
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

watch(currentIndex, () => {
	autoScroll.stop()
})

onMounted(() => {
	document.addEventListener('fullscreenchange', handleFullscreenChange)
	handleFullscreenChange()
	scheduleHudAutoHide()
})

onUnmounted(() => {
	document.removeEventListener('fullscreenchange', handleFullscreenChange)
	if (!keepFullscreenOnUnmount && document.fullscreenElement && document.exitFullscreen) {
		document.exitFullscreen().catch(() => {})
	}
})
</script>

<template>
	<Teleport to="body">
		<div
			ref="rootRef"
			:class="[$style.overlay, isNightMode && $style.nightChrome]"
			:style="effectiveOverlayBackground ? { backgroundColor: effectiveOverlayBackground } : undefined"
			:data-night-chrome="isNightMode ? 'true' : 'false'"
			:data-concert-mode="isConcertMode ? 'true' : 'false'"
			role="dialog"
			aria-modal="true"
			:aria-label="setlistName || currentSongTitle">
			<ConcertModeUnlockBadge
				v-if="isConcertMode"
				@unlock="exitConcertMode" />

			<SetlistPerformanceTopBar
				:visible="isHudVisible && !isConcertMode"
				:setlist-name="setlistName"
				:song-title="currentSongTitle"
				:bookmark-name="currentItem?.bookmarkName"
				:item-index="currentIndex"
				:item-count="items.length"
				:show-item-counter="!isSingleSongMode"
				:show-annotations="showAnnotations"
				:annotation-layers="annotationLayers"
				:is-fullscreen="isFullscreen"
				:show-settings-button="true"
				:is-settings-open="isSettingsOpen"
				:is-setlist-override="isSetlistOverride"
				@exit="emit('close')"
				@stop="handleStop"
				@toggle-annotations="onToggleAnnotations"
				@toggle-layer-visibility="onToggleLayerVisibility"
				@toggle-settings="toggleSettingsPanel"
				@enter-concert-mode="enterConcertMode"
				@toggle-fullscreen="toggleFullscreen"
				@pause-auto-hide="pauseHudAutoHide"
				@resume-auto-hide="resumeHudAutoHide">
				<template #tools>
					<PerformanceToolsButtons
						ref="toolsButtonsRef"
						:bpm="metronome.bpm.value"
						:time-signature="metronome.timeSignature.value"
						:is-metronome-running="metronome.isRunning.value"
						:current-beat="metronome.currentBeat.value"
						:accent-first-beat="metronome.accentFirstBeat.value"
						:sound-enabled="metronome.soundEnabled.value"
						:visual-flash-enabled="metronome.visualFlashEnabled.value"
						:song-tempo="currentSongTempo"
						:song-time-signature="currentSongTimeSignature"
						:active-pitch-class="pitchPipe.activePitchClass.value"
						:active-note-label="pitchPipe.activeNoteLabel.value"
						:active-frequency="pitchPipe.activeFrequency.value"
						:octave="pitchPipe.octave.value"
						:a4-hz="pitchPipe.a4Hz.value"
						:waveform="pitchPipe.waveform.value"
						:song-key-pitch-class="currentSongKeyPitchClass"
						:song-key-label="currentSongKeyLabel"
						@toggle-metronome="metronome.toggle()"
						@update:bpm="metronome.setBpm($event)"
						@adjust-bpm="metronome.adjustBpm($event)"
						@update:time-signature="metronome.setTimeSignature($event)"
						@tap-tempo="metronome.tapTempo()"
						@update:accent-first-beat="metronome.accentFirstBeat.value = $event"
						@update:sound-enabled="metronome.soundEnabled.value = $event"
						@update:visual-flash-enabled="metronome.visualFlashEnabled.value = $event"
						@sync-song-tempo="metronome.syncFromSong(currentSong)"
						@toggle-note="pitchPipe.toggleNote($event)"
						@stop-pitch="pitchPipe.stop()"
						@update:octave="pitchPipe.setOctave($event)"
						@update:a4-hz="pitchPipe.setA4Hz($event)"
						@update:waveform="pitchPipe.setWaveform($event)"
						@update:any-open="isToolsPopoverOpen = $event"
						@pause-auto-hide="pauseHudAutoHide"
						@resume-auto-hide="resumeHudAutoHide" />
				</template>
			</SetlistPerformanceTopBar>

			<div
				v-if="metronome.isRunning.value && metronome.visualFlashEnabled.value"
				:class="[$style.metronomePulseOverlay, isHudVisible && $style.metronomePulseBelowHud]"
				aria-hidden="true"
				data-test="performance-metronome-pulse">
				<span
					v-for="idx in metronomeBeatIndices"
					:key="idx"
					:class="[
						$style.pulseDot,
						idx === 0 && metronome.accentFirstBeat.value && $style.pulseDotAccent,
						metronome.currentBeat.value === idx && $style.pulseDotActive,
					]" />
			</div>

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
					:annotations="visibleAnnotations"
					:show-annotations="showAnnotations"
					:hide-toolbar="true"
					:show-external-button="false"
					:no-padding="true"
					:initial-page-layout="layoutChoice"
					:initial-double-page-turn-mode="doublePageTurnMode"
					:initial-zoom-mode="zoomChoice"
					:next-file-path="nextFilePath"
					:next-target-page="nextTargetPage"
					:next-page-order="nextPageOrder"
					:interleave-songs="interleaveSongs"
					:background-color="backgroundColor"
					:tight-crop="tightCrop"
					:score-color-mode="scoreColorMode"
					:score-brightness="scoreBrightness"
					:score-sepia="scoreSepia"
					:lock-zoom="isConcertMode"
					:file-id="currentFile?.fileId"
					:initial-crop-boxes="currentFile?.cropBoxes ?? null"
					:next-file-id="nextFile?.fileId"
					:next-initial-crop-boxes="nextFile?.cropBoxes ?? null" />
				<div v-else :class="$style.emptyFile">
					{{ t('sheetmusic', 'No score file attached to this song.') }}
				</div>

				<button
					v-if="(autoScroll.isActive.value || autoScroll.isPaused.value) && !isHudVisible"
					type="button"
					:class="$style.autoScrollPill"
					data-test="performance-autoscroll-pill"
					@click.stop="autoScroll.toggle()">
					<template v-if="autoScroll.countdownRemaining.value > 0">
						{{ t('sheetmusic', 'Auto-scroll in {sec}s…', { sec: autoScroll.countdownRemaining.value }) }}
					</template>
					<template v-else-if="autoScroll.isRunning.value">
						{{ t('sheetmusic', 'Auto-scrolling · Tap to pause') }}
					</template>
					<template v-else>
						{{ t('sheetmusic', 'Auto-scroll paused · Tap to resume') }}
					</template>
				</button>

				<button
					v-if="files.length > 0 && isHudVisible && !isConcertMode && !isSupplementalDrawerOpen"
					type="button"
					:class="$style.supplementalEdgeButton"
					:aria-label="t('sheetmusic', 'Open setlist notes')"
					:title="t('sheetmusic', 'Setlist Notes')"
					@click.stop="isSupplementalDrawerOpen = true"
					@mouseenter="pauseHudAutoHide"
					@mouseleave="resumeHudAutoHide">
					<ChevronLeftIcon :size="24" />
				</button>
			</div>

			<SetlistPerformancePageStrip
				:open="isPagePickerOpen"
				:songs="setlistStripSongs"
				:current-item-index="currentIndex"
				:current-page="previewerRef?.currentPage ?? 1"
				@select="onJumpToPage"
				@close="isPagePickerOpen = false"
				@pause-auto-hide="pauseHudAutoHide"
				@resume-auto-hide="resumeHudAutoHide" />

			<SetlistPerformanceBottomBar
				:visible="isHudVisible && !isConcertMode"
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
				:interleave-songs="interleaveSongs"
				:tight-crop="tightCrop"
				:score-color-mode="scoreColorMode"
				:score-brightness="scoreBrightness"
				:score-sepia="scoreSepia"
				:is-appearance-popover-open="isAppearancePopoverOpen"
				:auto-scroll-mode="autoScroll.mode.value"
				:auto-scroll-speed="autoScroll.speedPxPerSec.value"
				:auto-scroll-delay="autoScroll.startDelaySeconds.value"
				:auto-scroll-running="autoScroll.isRunning.value"
				:auto-scroll-paused="autoScroll.isPaused.value"
				:auto-scroll-countdown="autoScroll.countdownRemaining.value"
				:song-duration-seconds="currentSong?.duration ?? null"
				:is-auto-scroll-popover-open="isAutoScrollPopoverOpen"
				:is-page-picker-open="isPagePickerOpen"
				:is-drawer-open="isDrawerOpen"
				:show-setlist-controls="!isSingleSongMode"
				@prev-song="goToSong(currentIndex - 1, false)"
				@prev-page="handlePrevPage"
				@next-page="handleNextPage"
				@next-song="goToSong(currentIndex + 1, false)"
				@change-layout="onLayoutChange"
				@change-double-page-turn-mode="onDoublePageTurnModeChange"
				@toggle-zoom="onZoomToggle"
				@toggle-interleave="onToggleInterleave"
				@toggle-tight-crop="onUpdateTightCrop(!tightCrop)"
				@toggle-night-mode="onToggleNightMode"
				@update:score-color-mode="onUpdateScoreColorMode"
				@update:score-brightness="onUpdateScoreBrightness"
				@update:score-sepia="onUpdateScoreSepia"
				@update:appearance-popover-open="isAppearancePopoverOpen = $event"
				@toggle-auto-scroll="autoScroll.toggle()"
				@reset-auto-scroll-top="autoScroll.resetToTop()"
				@update:auto-scroll-mode="autoScroll.setMode($event)"
				@update:auto-scroll-speed="autoScroll.setSpeed($event)"
				@adjust-auto-scroll-speed="autoScroll.adjustSpeed($event)"
				@update:auto-scroll-delay="autoScroll.setStartDelay($event)"
				@update:auto-scroll-popover-open="isAutoScrollPopoverOpen = $event"
				@toggle-page-picker="isPagePickerOpen = !isPagePickerOpen"
				@toggle-drawer="toggleDrawer"
				@pause-auto-hide="pauseHudAutoHide"
				@resume-auto-hide="resumeHudAutoHide" />

			<SetlistPerformanceDrawer
				v-if="!isSingleSongMode"
				:open="isDrawerOpen"
				:setlist-name="setlistName"
				:items="items"
				:current-index="currentIndex"
				@close="toggleDrawer"
				@select="goToSong($event, false)" />

			<SetlistPerformanceSettingsPanel
				:open="isSettingsOpen"
				:setlist-name="setlistName"
				:show-setlist-scope="Boolean(setlistId && setlistId > 0)"
				:is-setlist-override="isSetlistOverride"
				:background-color="backgroundColor"
				:tight-crop="tightCrop"
				:score-color-mode="scoreColorMode"
				:score-brightness="scoreBrightness"
				:score-sepia="scoreSepia"
				@close="toggleSettingsPanel"
				@update:is-setlist-override="toggleSetlistOverride"
				@update:background-color="onUpdateBackgroundColor"
				@update:tight-crop="onUpdateTightCrop"
				@update:score-color-mode="onUpdateScoreColorMode"
				@update:score-brightness="onUpdateScoreBrightness"
				@update:score-sepia="onUpdateScoreSepia"
				@save-as-library-default="saveCurrentAsLibraryDefault" />

			<SetlistPerformanceSupplementalDrawer
				:open="isSupplementalDrawerOpen"
				:library-id="libraryId"
				:setlist-id="setlistId ?? 0"
				:files="files"
				@close="isSupplementalDrawerOpen = false" />
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

.nightChrome {
	color-scheme: dark;
	--color-main-background: #141416;
	--color-background-hover: #222226;
	--color-background-dark: #26262b;
	--color-main-text: #e8e8ec;
	--color-text-maxcontrast: #a4a4b0;
	--color-border: #2e2e35;
	--color-border-dark: #44444e;
	--color-primary-light: rgba(0, 130, 201, 0.25);
}

.stage {
	position: relative;
	z-index: 1;
	isolation: isolate;
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

button[type='button'].autoScrollPill {
	position: absolute;
	bottom: 18px;
	left: 50%;
	transform: translateX(-50%);
	z-index: 26;
	margin: 0;
	padding: 6px 14px;
	border: 1px solid var(--color-primary-element, #0082c9);
	border-radius: 999px;
	background: var(--color-main-background, #ffffff);
	color: var(--color-main-text, #222222);
	font-size: 0.8rem;
	font-weight: 600;
	opacity: 0.88;
	box-shadow: 0 3px 12px rgba(0, 0, 0, 0.22);
	cursor: pointer;
}

.supplementalEdgeButton {
	position: absolute;
	right: 0;
	top: 50%;
	transform: translateY(-50%);
	z-index: 20;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 38px;
	height: 72px;
	border: 1px solid var(--color-border);
	border-right: none;
	border-radius: 12px 0 0 12px;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	box-shadow: -2px 2px 10px rgba(0, 0, 0, 0.2);
	cursor: pointer;
	opacity: 0.92;
	transition: opacity 0.15s ease, background-color 0.15s ease;
}

.supplementalEdgeButton:hover {
	opacity: 1;
	background-color: var(--color-background-hover);
}

.metronomePulseOverlay {
	position: fixed;
	top: 12px;
	left: 50%;
	transform: translateX(-50%);
	z-index: 25;
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 6px 12px;
	border-radius: 999px;
	background: rgba(0, 0, 0, 0.55);
	pointer-events: none;
	transition: top 0.15s ease;
}

.metronomePulseBelowHud {
	top: 64px;
}

.pulseDot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: rgba(255, 255, 255, 0.35);
	transition: transform 0.08s ease, background-color 0.08s ease;
}

.pulseDotAccent {
	width: 12px;
	height: 12px;
	border: 2px solid #ffffff;
	box-sizing: border-box;
}

.pulseDotActive {
	background: var(--color-primary-element, #0082c9);
	transform: scale(1.3);
}
</style>
