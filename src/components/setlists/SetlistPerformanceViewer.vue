<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateFilePath } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import EyeOffOutlineIcon from 'vue-material-design-icons/EyeOffOutline.vue'
import EyeOutlineIcon from 'vue-material-design-icons/EyeOutline.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'
import FullscreenIcon from 'vue-material-design-icons/Fullscreen.vue'
import FullscreenExitIcon from 'vue-material-design-icons/FullscreenExit.vue'
import SkipNextIcon from 'vue-material-design-icons/SkipNext.vue'
import SkipPreviousIcon from 'vue-material-design-icons/SkipPrevious.vue'
import FilePreviewer from '../FilePreviewer.vue'
import { fetchSongAnnotations } from '../../api/annotations'
import type { SheetAnnotation } from '../../types/annotation'
import type { SetlistItem, Song, SongFile } from '../../types/song'
import { defaultFileBinaryCache } from '../../utils/file-cache'
import { getFileVersionToken, getVersionedFileUrl } from '../../utils/file-version'
import type { DoublePageTurnMode } from '../../utils/page-turn'

const props = withDefaults(defineProps<{
	setlistName: string
	items: SetlistItem[]
	songs: Song[]
	libraryId: number
	initialIndex?: number
}>(), {
	initialIndex: 0,
})

const emit = defineEmits<{
	close: []
}>()

const rootRef = ref<HTMLElement | null>(null)
const previewerRef = ref<InstanceType<typeof FilePreviewer> | null>(null)

const currentIndex = ref(Math.min(Math.max(0, props.initialIndex), Math.max(0, props.items.length - 1)))
const startAtEnd = ref(false)
const isHudVisible = ref(true)
const isDrawerOpen = ref(false)
const isFullscreen = ref(false)
const showAnnotations = ref(false)

type PerformanceLayoutChoice = 'auto' | 'single' | 'double' | 'continuous'
const layoutChoice = ref<PerformanceLayoutChoice>('auto')
const doublePageTurnMode = ref<DoublePageTurnMode>('half')
const zoomChoice = ref<'fit-page' | 'fit-width'>('fit-page')

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

const effectiveLayout = computed(() => previewerRef.value?.pageLayout ?? 'single')
const currentPageDisplay = computed(() => previewerRef.value?.currentPageDisplay ?? '1')
const totalPages = computed(() => previewerRef.value?.totalPages ?? 1)
const hasPrevSong = computed(() => currentIndex.value > 0)
const hasNextSong = computed(() => currentIndex.value < props.items.length - 1)

// Preload the next song's PDF into the shared binary cache
function preloadNextSong() {
	const nextItem = props.items[currentIndex.value + 1]
	if (!nextItem) return
	const nextSong = songsById.value.get(nextItem.songId) ?? null
	const nextFile = pickPrimaryFile(nextSong)
	const nextPath = buildVersionedPath(nextFile)
	if (!nextPath || !nextPath.toLowerCase().split('?')[0].endsWith('.pdf')) return
	if (defaultFileBinaryCache.has(nextPath)) return

	const url = generateFilePath('files', 'ajax', 'download.php') + `?files=${encodeURIComponent(nextPath)}`
	axios.get(url, { responseType: 'arraybuffer' })
		.then((resp) => {
			if (resp.data) {
				defaultFileBinaryCache.set(nextPath, new Uint8Array(resp.data))
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
}

function onDoublePageTurnModeChange(mode: DoublePageTurnMode) {
	doublePageTurnMode.value = mode
	previewerRef.value?.setDoublePageTurnMode(mode)
}

function onZoomToggle() {
	const next = zoomChoice.value === 'fit-page' ? 'fit-width' : 'fit-page'
	zoomChoice.value = next
	previewerRef.value?.setZoomMode(next)
}

function onToggleAnnotations() {
	showAnnotations.value = !showAnnotations.value
	previewerRef.value?.toggleAnnotations()
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

// Touch swipe + tap zone handling
let touchStartX = 0
let touchStartY = 0
let touchStartTime = 0

function onStageTouchStart(event: TouchEvent) {
	if (event.touches.length !== 1) return
	touchStartX = event.touches[0].clientX
	touchStartY = event.touches[0].clientY
	touchStartTime = Date.now()
}

function onStageTouchEnd(event: TouchEvent) {
	if (effectiveLayout.value === 'continuous') return
	if (event.changedTouches.length !== 1) return
	const dx = event.changedTouches[0].clientX - touchStartX
	const dy = event.changedTouches[0].clientY - touchStartY
	const elapsed = Date.now() - touchStartTime

	if (elapsed < 500 && Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.2) {
		if (dx < 0) {
			handleNextPage()
		} else {
			handlePrevPage()
		}
	}
}

function onStageClick(event: MouseEvent) {
	if (effectiveLayout.value === 'continuous') return
	const target = event.currentTarget as HTMLElement | null
	if (!target) return
	const rect = target.getBoundingClientRect()
	const relX = (event.clientX - rect.left) / Math.max(1, rect.width)
	if (relX < 0.25) {
		handlePrevPage()
	} else if (relX > 0.75) {
		handleNextPage()
	} else {
		isHudVisible.value = !isHudVisible.value
	}
}

// Keyboard & Bluetooth page-turner pedal support
function handleKeyDown(event: KeyboardEvent) {
	const tag = (event.target as HTMLElement | null)?.tagName
	if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return

	switch (event.key) {
	case 'ArrowRight':
	case 'PageDown':
	case ' ':
	case 'Enter':
		event.preventDefault()
		handleNextPage()
		break
	case 'ArrowLeft':
	case 'PageUp':
	case 'Backspace':
		event.preventDefault()
		handlePrevPage()
		break
	case 'Escape':
		event.preventDefault()
		if (isDrawerOpen.value) {
			isDrawerOpen.value = false
		} else {
			emit('close')
		}
		break
	}
}

// Screen Wake Lock
let wakeLockSentinel: { release: () => Promise<void> } | null = null

async function requestWakeLock() {
	try {
		const nav = navigator as Navigator & { wakeLock?: { request: (type: 'screen') => Promise<{ release: () => Promise<void> }> } }
		if (nav.wakeLock) {
			wakeLockSentinel = await nav.wakeLock.request('screen')
		}
	} catch {
		// Wake lock not supported or denied
	}
}

function handleVisibilityChange() {
	if (document.visibilityState === 'visible') {
		requestWakeLock()
	}
}

onMounted(() => {
	window.addEventListener('keydown', handleKeyDown)
	document.addEventListener('fullscreenchange', handleFullscreenChange)
	document.addEventListener('visibilitychange', handleVisibilityChange)
	requestWakeLock()
})

onUnmounted(() => {
	window.removeEventListener('keydown', handleKeyDown)
	document.removeEventListener('fullscreenchange', handleFullscreenChange)
	document.removeEventListener('visibilitychange', handleVisibilityChange)
	if (wakeLockSentinel) {
		wakeLockSentinel.release().catch(() => {})
		wakeLockSentinel = null
	}
	if (document.fullscreenElement && document.exitFullscreen) {
		document.exitFullscreen().catch(() => {})
	}
})
</script>

<template>
	<div ref="rootRef" :class="$style.overlay" role="dialog" aria-modal="true" :aria-label="setlistName">
		<!-- Top HUD -->
		<header v-show="isHudVisible" :class="$style.topBar">
			<div :class="$style.topLeft">
				<NcButton
					variant="tertiary"
					:title="t('sheetmusic', 'Exit performance mode')"
					:aria-label="t('sheetmusic', 'Exit performance mode')"
					@click="emit('close')">
					<template #icon><ArrowLeftIcon :size="20" /></template>
					{{ t('sheetmusic', 'Exit') }}
				</NcButton>

				<div :class="$style.titleBlock">
					<div :class="$style.songTitleRow">
						<span :class="$style.itemCounter">{{ currentIndex + 1 }} / {{ items.length }}</span>
						<strong :class="$style.songTitle">
							{{ currentSong?.title || currentItem?.songTitle || t('sheetmusic', 'Unknown Song') }}
						</strong>
						<span v-if="currentItem?.bookmarkName" :class="$style.bookmarkBadge">
							{{ currentItem.bookmarkName }}
						</span>
					</div>
					<span :class="$style.setlistSubtitle">{{ setlistName }}</span>
				</div>
			</div>

			<div :class="$style.topRight">
				<NcButton
					variant="tertiary"
					:title="t('sheetmusic', 'Setlist items')"
					:aria-label="t('sheetmusic', 'Setlist items')"
					@click="isDrawerOpen = !isDrawerOpen">
					<template #icon><FormatListBulletedIcon :size="20" /></template>
				</NcButton>
				<NcButton
					:variant="showAnnotations ? 'primary' : 'tertiary'"
					:title="t('sheetmusic', 'Toggle annotations')"
					:aria-label="t('sheetmusic', 'Toggle annotations')"
					@click="onToggleAnnotations">
					<template #icon>
						<EyeOutlineIcon v-if="showAnnotations" :size="20" />
						<EyeOffOutlineIcon v-else :size="20" />
					</template>
				</NcButton>
				<NcButton
					variant="tertiary"
					:title="isFullscreen ? t('sheetmusic', 'Exit fullscreen') : t('sheetmusic', 'Fullscreen')"
					:aria-label="isFullscreen ? t('sheetmusic', 'Exit fullscreen') : t('sheetmusic', 'Fullscreen')"
					@click="toggleFullscreen">
					<template #icon>
						<FullscreenExitIcon v-if="isFullscreen" :size="20" />
						<FullscreenIcon v-else :size="20" />
					</template>
				</NcButton>
			</div>
		</header>

		<!-- Main Stage -->
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
				initial-page-layout="auto"
				:initial-double-page-turn-mode="doublePageTurnMode"
				:initial-zoom-mode="zoomChoice" />
			<div v-else :class="$style.emptyFile">
				{{ t('sheetmusic', 'No score file attached to this song.') }}
			</div>
		</div>

		<!-- Bottom HUD -->
		<footer v-show="isHudVisible" :class="$style.bottomBar">
			<div :class="$style.navGroup">
				<NcButton
					variant="secondary"
					size="small"
					:disabled="!hasPrevSong"
					:title="t('sheetmusic', 'Previous song')"
					:aria-label="t('sheetmusic', 'Previous song')"
					@click="goToSong(currentIndex - 1, false)">
					<template #icon><SkipPreviousIcon :size="20" /></template>
				</NcButton>
				<NcButton
					variant="secondary"
					size="small"
					:disabled="!hasPrevSong && !(previewerRef?.canGoPrev)"
					:title="t('sheetmusic', 'Previous page')"
					:aria-label="t('sheetmusic', 'Previous page')"
					@click="handlePrevPage">
					<template #icon><ChevronLeftIcon :size="20" /></template>
				</NcButton>
				<span :class="$style.pageStatus">
					{{ t('sheetmusic', 'Page {current} of {total}', { current: currentPageDisplay, total: totalPages || 1 }, { escape: false }) }}
				</span>
				<NcButton
					variant="secondary"
					size="small"
					:disabled="!hasNextSong && !(previewerRef?.canGoNext)"
					:title="t('sheetmusic', 'Next page')"
					:aria-label="t('sheetmusic', 'Next page')"
					@click="handleNextPage">
					<template #icon><ChevronRightIcon :size="20" /></template>
				</NcButton>
				<NcButton
					variant="secondary"
					size="small"
					:disabled="!hasNextSong"
					:title="t('sheetmusic', 'Next song')"
					:aria-label="t('sheetmusic', 'Next song')"
					@click="goToSong(currentIndex + 1, false)">
					<template #icon><SkipNextIcon :size="20" /></template>
				</NcButton>
			</div>

			<div :class="$style.modeGroup">
				<select
					:value="layoutChoice"
					:aria-label="t('sheetmusic', 'Page layout')"
					:class="$style.selectControl"
					@change="onLayoutChange(($event.target as HTMLSelectElement).value as PerformanceLayoutChoice)">
					<option value="auto">
						{{ t('sheetmusic', 'Auto (Tablet)') }}
					</option>
					<option value="single">
						{{ t('sheetmusic', 'Single page') }}
					</option>
					<option value="double">
						{{ t('sheetmusic', 'Two pages') }}
					</option>
					<option value="continuous">
						{{ t('sheetmusic', 'Scroll') }}
					</option>
				</select>

				<select
					v-if="effectiveLayout === 'double'"
					:value="doublePageTurnMode"
					:aria-label="t('sheetmusic', 'Page turning mode')"
					:class="$style.selectControl"
					@change="onDoublePageTurnModeChange(($event.target as HTMLSelectElement).value as DoublePageTurnMode)">
					<option value="half">
						{{ t('sheetmusic', '1/2 → 3/2 → 3/4') }}
					</option>
					<option value="step">
						{{ t('sheetmusic', '1/2 → 2/3 → 3/4') }}
					</option>
					<option value="spread">
						{{ t('sheetmusic', '1/2 → 3/4') }}
					</option>
				</select>

				<NcButton
					variant="secondary"
					size="small"
					@click="onZoomToggle">
					{{ zoomChoice === 'fit-page' ? t('sheetmusic', 'Fit Page') : t('sheetmusic', 'Fit Width') }}
				</NcButton>
			</div>
		</footer>

		<!-- Slide-out Setlist Drawer -->
		<aside v-if="isDrawerOpen" :class="$style.drawer" :aria-label="t('sheetmusic', 'Setlist items')">
			<div :class="$style.drawerHeader">
				<strong>{{ setlistName }}</strong>
				<NcButton variant="tertiary" size="small" @click="isDrawerOpen = false">
					{{ t('sheetmusic', 'Close') }}
				</NcButton>
			</div>
			<ul :class="$style.drawerList">
				<li
					v-for="(item, idx) in items"
					:key="item.id ?? `${item.songId}-${idx}`"
					:class="[$style.drawerItem, idx === currentIndex && $style.drawerItemActive]"
					@click="goToSong(idx, false)">
					<span :class="$style.drawerIndex">{{ idx + 1 }}.</span>
					<div :class="$style.drawerInfo">
						<span :class="$style.drawerTitle">{{ item.songTitle || t('sheetmusic', 'Unknown Song') }}</span>
						<span v-if="item.bookmarkName" :class="$style.drawerBookmark">{{ item.bookmarkName }}</span>
					</div>
				</li>
			</ul>
		</aside>
	</div>
</template>

<style module>
.overlay {
	position: fixed;
	inset: 0;
	z-index: 10000;
	display: flex;
	flex-direction: column;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.topBar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 16px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-main-background);
}

.topLeft {
	display: flex;
	align-items: center;
	gap: 12px;
	min-width: 0;
}

.titleBlock {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.songTitleRow {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}

.itemCounter {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.songTitle {
	font-size: 1rem;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.bookmarkBadge {
	font-size: 0.78rem;
	padding: 2px 8px;
	border-radius: 999px;
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
	white-space: nowrap;
}

.setlistSubtitle {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.topRight {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-shrink: 0;
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

.bottomBar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 16px;
	border-top: 1px solid var(--color-border);
	background-color: var(--color-main-background);
}

.navGroup {
	display: flex;
	align-items: center;
	gap: 6px;
}

.pageStatus {
	font-size: 0.9rem;
	font-weight: 500;
	padding: 0 8px;
	white-space: nowrap;
}

.modeGroup {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.selectControl {
	height: 32px;
	padding: 0 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.85rem;
}

.drawer {
	position: fixed;
	top: 0;
	right: 0;
	bottom: 0;
	width: min(340px, 85vw);
	z-index: 10001;
	display: flex;
	flex-direction: column;
	background-color: var(--color-main-background);
	border-left: 1px solid var(--color-border);
	box-shadow: -4px 0 16px rgba(0, 0, 0, 0.2);
}

.drawerHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
}

.drawerList {
	list-style: none;
	margin: 0;
	padding: 8px;
	overflow-y: auto;
	flex: 1;
}

.drawerItem {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	padding: 10px 12px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.drawerItem:hover {
	background-color: var(--color-background-hover);
}

.drawerItemActive {
	background-color: var(--color-primary-light);
	font-weight: 600;
}

.drawerIndex {
	color: var(--color-text-maxcontrast);
	min-width: 22px;
}

.drawerInfo {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.drawerTitle {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.drawerBookmark {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}
</style>
