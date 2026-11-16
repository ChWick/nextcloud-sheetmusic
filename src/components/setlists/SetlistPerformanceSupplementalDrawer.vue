<template>
	<div v-if="open" :class="$style.backdrop" @click.self="emit('close')">
		<aside
			:class="$style.drawer"
			:style="drawerStyle"
			role="dialog"
			aria-modal="true"
			:aria-label="t('sheetmusic', 'Setlist Supplemental Files')">
			<div
				:class="$style.resizeHandle"
				:title="t('sheetmusic', 'Drag to resize')"
				role="separator"
				aria-orientation="vertical"
				@pointerdown="onResizeStart" />

			<div :class="$style.drawerHeader">
				<span :class="$style.drawerTitle">{{ activeFile?.filename ?? t('sheetmusic', 'Setlist Notes') }}</span>
				<button
					type="button"
					:class="$style.closeBtn"
					:aria-label="t('sheetmusic', 'Close')"
					@click="emit('close')">
					<CloseIcon :size="20" />
				</button>
			</div>

			<div v-if="files.length > 1" :class="$style.fileTabs" role="tablist">
				<button
					v-for="(file, idx) in files"
					:key="file.id"
					type="button"
					role="tab"
					:aria-selected="activeIndex === idx"
					:class="[$style.fileTab, activeIndex === idx && $style.fileTabActive]"
					@click="activeIndex = idx">
					{{ file.filename }}
				</button>
			</div>

			<div :class="$style.viewerBody">
				<FilePreviewer
					v-if="activeFile && activeFileUrl"
					:key="activeFile.id"
					:file-path="activeFileUrl"
					:file-name="activeFile.filename"
					:show-external-button="false"
					:no-padding="true" />
			</div>
		</aside>
	</div>
</template>

<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import FilePreviewer from '../FilePreviewer.vue'
import { getSetlistFileContentUrl } from '../../api/setlists'
import type { SetlistFile } from '../../types/song'

const props = defineProps<{
	open: boolean
	libraryId: number
	setlistId: number
	files: SetlistFile[]
}>()

const emit = defineEmits<{
	close: []
}>()

const activeIndex = ref(0)
const customWidthPx = ref<number | null>(null)
const isResizing = ref(false)

watch(() => props.files, (nextFiles) => {
	if (activeIndex.value >= nextFiles.length) {
		activeIndex.value = 0
	}
})

const activeFile = computed(() => props.files[activeIndex.value] ?? null)

const activeFileUrl = computed(() => {
	if (!activeFile.value || props.libraryId <= 0 || props.setlistId <= 0) {
		return ''
	}
	return getSetlistFileContentUrl(
		props.libraryId,
		props.setlistId,
		activeFile.value.id,
		activeFile.value.updatedAt,
	)
})

const drawerStyle = computed(() => {
	if (customWidthPx.value !== null) {
		return { width: `${customWidthPx.value}px`, maxWidth: '100vw' }
	}
	return undefined
})

function onResizeStart(event: PointerEvent) {
	event.preventDefault()
	isResizing.value = true
	window.addEventListener('pointermove', onResizeMove)
	window.addEventListener('pointerup', onResizeEnd)
	window.addEventListener('pointercancel', onResizeEnd)
}

function onResizeMove(event: PointerEvent) {
	if (!isResizing.value) return
	const viewportWidth = window.innerWidth
	const desiredWidth = viewportWidth - event.clientX
	const clamped = Math.min(viewportWidth, Math.max(280, desiredWidth))
	customWidthPx.value = Math.round(clamped)
}

function onResizeEnd() {
	isResizing.value = false
	window.removeEventListener('pointermove', onResizeMove)
	window.removeEventListener('pointerup', onResizeEnd)
	window.removeEventListener('pointercancel', onResizeEnd)
}

onUnmounted(() => {
	onResizeEnd()
})
</script>

<style module>
.backdrop {
	position: fixed;
	inset: 0;
	z-index: 100020;
	background-color: rgba(0, 0, 0, 0.45);
	display: flex;
	justify-content: flex-end;
}

.drawer {
	position: relative;
	width: min(680px, 65vw);
	max-width: 100vw;
	height: 100%;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	display: flex;
	flex-direction: column;
	box-shadow: -4px 0 20px rgba(0, 0, 0, 0.25);
}

@media (max-width: 640px) {
	.drawer {
		width: 100vw;
	}
}

.resizeHandle {
	position: absolute;
	left: 0;
	top: 0;
	bottom: 0;
	width: 10px;
	cursor: ew-resize;
	z-index: 5;
	touch-action: none;
	display: flex;
	align-items: center;
	justify-content: center;
}

.resizeHandle::after {
	content: '';
	width: 3px;
	height: 40px;
	border-radius: 2px;
	background-color: var(--color-border-dark, rgba(128, 128, 128, 0.45));
	transition: background-color 0.15s ease;
}

.resizeHandle:hover::after,
.resizeHandle:active::after {
	background-color: var(--color-primary-element);
}

.drawerHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 14px 12px 18px;
	border-bottom: 1px solid var(--color-border);
	flex-shrink: 0;
}

.drawerTitle {
	font-weight: 600;
	font-size: 1rem;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.closeBtn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 34px;
	height: 34px;
	border: none;
	border-radius: var(--border-radius-pill);
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
	flex-shrink: 0;
}

.closeBtn:hover {
	background-color: var(--color-background-hover);
}

.fileTabs {
	display: flex;
	gap: 6px;
	padding: 8px 14px 8px 18px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
	overflow-x: auto;
	flex-shrink: 0;
}

.fileTab {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 4px 12px;
	font-size: 0.85rem;
	cursor: pointer;
	white-space: nowrap;
}

.fileTabActive {
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
	font-weight: 600;
}

.viewerBody {
	flex: 1;
	min-height: 0;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}
</style>
