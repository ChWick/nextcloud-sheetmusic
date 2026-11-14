<script setup lang="ts">
import type { SongFile } from '../../types/song'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateFilePath } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist'
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url'
import { downsampleImageBuffer, imageBufferToImageData, imageDataToImageBuffer } from '../song-pdf/scan-enhance-engine'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import EjectIcon from 'vue-material-design-icons/Eject.vue'
import FileIcon from 'vue-material-design-icons/File.vue'
import FormatListNumbered from 'vue-material-design-icons/FormatListNumbered.vue'
import MusicIcon from 'vue-material-design-icons/Music.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { getCacheBustedFileUrl, getFileName } from '../../utils/file'

GlobalWorkerOptions.workerSrc = pdfWorker

const props = defineProps<{
	file: SongFile
	disabled?: boolean
}>()

const emit = defineEmits<{
	openExternal: [file: SongFile]
	detach: [file: SongFile]
	delete: [file: SongFile]
	editPageOrder: [file: SongFile]
}>()

const thumbnailContainer = ref<HTMLElement>()
const isPdf = computed(() => props.file.path.toLowerCase().endsWith('.pdf'))
const isImage = computed(() => {
	const path = props.file.path.toLowerCase()
	return path.endsWith('.png') || path.endsWith('.jpg') || path.endsWith('.jpeg') || path.endsWith('.webp') || path.endsWith('.gif') || path.endsWith('.svg') || path.endsWith('.bmp')
})
const isAudio = computed(() => {
	const path = props.file.path.toLowerCase()
	return path.endsWith('.mp3') || path.endsWith('.wav') || path.endsWith('.ogg') || path.endsWith('.flac') || path.endsWith('.mid') || path.endsWith('.midi')
})

async function loadPdfThumbnail() {
	if (!isPdf.value || !thumbnailContainer.value) {
		return
	}

	thumbnailContainer.value.replaceChildren()

	try {
		const targetUrl = getCacheBustedFileUrl(props.file.path, props.file.id)
		const response = await axios.get(targetUrl, {
			responseType: 'arraybuffer',
		})

		const pdfData = response.data instanceof ArrayBuffer ? new Uint8Array(response.data) : response.data
		const wasmUrl = typeof generateFilePath === 'function' ? generateFilePath('sheetmusic', 'js', 'pdfjs/wasm/') : new URL('pdfjs-dist/wasm/', import.meta.url).href
		const cMapUrl = typeof generateFilePath === 'function' ? generateFilePath('sheetmusic', 'js', 'pdfjs/cmaps/') : new URL('pdfjs-dist/cmaps/', import.meta.url).href
		const standardFontDataUrl = typeof generateFilePath === 'function' ? generateFilePath('sheetmusic', 'js', 'pdfjs/standard_fonts/') : new URL('pdfjs-dist/standard_fonts/', import.meta.url).href

		const pdf = await getDocument({
			data: pdfData,
			cMapUrl,
			cMapPacked: true,
			standardFontDataUrl,
			wasmUrl,
		}).promise

		if (pdf.numPages < 1) {
			return
		}

		const page = await pdf.getPage(1)
		const unscaledViewport = page.getViewport({ scale: 1.0 })
		// Supersample at 6x (264px) and area-average downsample to 88px (2x HiDPI for 44px CSS width)
		const renderScale = 264 / Math.max(1, unscaledViewport.width)
		const viewport = page.getViewport({ scale: renderScale })
		const vpScaled = Math.abs(viewport.width - unscaledViewport.width * renderScale) < 2
		const renderW = vpScaled ? Math.max(1, Math.round(viewport.width)) : Math.max(1, Math.round(unscaledViewport.width * renderScale))
		const renderH = vpScaled ? Math.max(1, Math.round(viewport.height)) : Math.max(1, Math.round(unscaledViewport.height * renderScale))

		const thumbW = 88
		const thumbH = Math.max(1, Math.round(renderH * (thumbW / renderW)))

		const srcCanvas = document.createElement('canvas')
		srcCanvas.width = renderW
		srcCanvas.height = renderH

		const canvas = document.createElement('canvas')
		canvas.width = thumbW
		canvas.height = thumbH
		canvas.className = 'sheetmusic-file-thumb'

		const srcContext = srcCanvas.getContext('2d')
		const dstContext = canvas.getContext('2d')
		if (srcContext && dstContext) {
			srcContext.imageSmoothingEnabled = true
			srcContext.imageSmoothingQuality = 'high'
			srcContext.fillStyle = '#ffffff'
			srcContext.fillRect(0, 0, srcCanvas.width, srcCanvas.height)
			await page.render({
				canvasContext: srcContext,
				viewport,
				background: 'rgb(255, 255, 255)',
			}).promise

			const imgData = srcContext.getImageData(0, 0, srcCanvas.width, srcCanvas.height)
			const downsampled = downsampleImageBuffer(imageDataToImageBuffer(imgData), thumbW, thumbH)
			dstContext.putImageData(imageBufferToImageData(downsampled), 0, 0)

			if (thumbnailContainer.value) {
				thumbnailContainer.value.replaceChildren(canvas)
			}
		}
	} catch {
		// If thumbnail rendering fails, fallback icon stays visible
	}
}

onMounted(() => {
	nextTick(() => loadPdfThumbnail())
})

watch(() => props.file.path, () => {
	nextTick(() => loadPdfThumbnail())
})
</script>

<template>
	<li :class="$style.fileItem">
		<div :class="$style.previewBadge">
			<div v-if="isPdf" ref="thumbnailContainer" :class="$style.thumbSlot">
				<FileIcon :size="24" :class="$style.fallbackIcon" />
			</div>
			<div v-else-if="isImage" :class="$style.thumbSlot">
				<img :src="getCacheBustedFileUrl(file.path, file.id)" :alt="getFileName(file.path)" :class="$style.imgThumb" />
			</div>
			<div v-else-if="isAudio" :class="$style.thumbSlot">
				<MusicIcon :size="24" :class="$style.fallbackIcon" />
			</div>
			<div v-else :class="$style.thumbSlot">
				<FileIcon :size="24" :class="$style.fallbackIcon" />
			</div>
		</div>
		<div :class="$style.fileInfo">
			<span :class="$style.fileName">{{ getFileName(file.path) }}</span>
			<span :class="$style.filePathText">{{ file.path }}</span>
			<div v-if="isPdf" :class="$style.pageOrderBadge" :title="t('sheetmusic', 'MobileSheets Page Order')">
				<FormatListNumbered :size="14" aria-hidden="true" />
				<span :class="$style.pageOrderText">
					{{ file.pageOrder ? t('sheetmusic', 'Pages: {order}', { order: file.pageOrder }, { escape: false }) : (file.pageCount && file.pageCount > 1 ? t('sheetmusic', 'Pages: 1-{count}', { count: file.pageCount }, { escape: false }) : t('sheetmusic', '1 page')) }}
				</span>
			</div>
		</div>
		<div :class="$style.fileActions">
			<NcButton
				type="button"
				variant="secondary"
				:aria-label="t('sheetmusic', 'Open {file} in external viewer', { file: getFileName(file.path) }, { escape: false })"
				:title="t('sheetmusic', 'Open in external viewer')"
				@click="emit('openExternal', file)">
				<template #icon>
					<OpenInNewIcon :size="20" />
				</template>
			</NcButton>
			<NcButton
				type="button"
				variant="tertiary"
				:disabled="disabled"
				:aria-label="t('sheetmusic', 'Detach file {file} from song', { file: getFileName(file.path) }, { escape: false })"
				:title="t('sheetmusic', 'Detach file from song (keep file in storage)')"
				@click="emit('detach', file)">
				<template #icon>
					<EjectIcon :size="20" />
				</template>
			</NcButton>
			<NcButton
				type="button"
				variant="error"
				:disabled="disabled"
				:aria-label="t('sheetmusic', 'Delete file {file}', { file: getFileName(file.path) }, { escape: false })"
				:title="t('sheetmusic', 'Delete file from storage')"
				@click="emit('delete', file)">
				<template #icon>
					<DeleteIcon :size="20" />
				</template>
			</NcButton>
			<NcButton
				v-if="isPdf"
				type="button"
				variant="secondary"
				:disabled="disabled"
				:aria-label="t('sheetmusic', 'Edit page order for {file}', { file: getFileName(file.path) }, { escape: false })"
				:title="t('sheetmusic', 'Edit page order')"
				@click="emit('editPageOrder', file)">
				<template #icon>
					<FormatListNumbered :size="20" />
				</template>
			</NcButton>
		</div>
	</li>
</template>

<style module>
.fileItem {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	padding: 8px 12px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	min-width: 0;
	box-sizing: border-box;
}

.previewBadge {
	flex-shrink: 0;
	width: 44px;
	height: 56px;
	display: flex;
	align-items: center;
	justify-content: center;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	overflow: hidden;
}

.thumbSlot {
	width: 100%;
	height: 100%;
	display: flex;
	align-items: center;
	justify-content: center;
}

.thumbSlot :global(.sheetmusic-file-thumb),
.imgThumb {
	display: block;
	max-width: 100%;
	max-height: 100%;
	object-fit: contain;
	background-color: #ffffff;
}

.fallbackIcon {
	color: var(--color-text-maxcontrast);
}

.fileInfo {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1 1 0;
	min-width: 0;
	overflow: hidden;
}

.fileName {
	font-weight: 600;
	font-size: 0.95rem;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.filePathText {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.pageOrderBadge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	margin-top: 2px;
	color: var(--color-primary-element);
	font-size: 0.75rem;
	font-weight: 500;
}

.pageOrderText {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.fileActions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-shrink: 0;
}
</style>
