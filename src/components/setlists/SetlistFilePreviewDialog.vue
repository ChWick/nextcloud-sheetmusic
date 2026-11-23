<template>
	<Teleport to="body">
		<div
			:class="[$style.backdrop, isFullscreen && $style.backdropFullscreen]"
			role="dialog"
			aria-modal="true"
			:aria-label="file.filename"
			@click.self="handleClose">
			<FullscreenShell :active="isFullscreen">
				<div :class="[$style.panel, isFullscreen && $style.panelFullscreen]">
					<FileViewerContent
						ref="viewerRef"
						:file="toolbarFile"
						:file-path="fileUrl"
						:file-name="file.filename"
						:is-pdf="isPdf"
						:annotations="annotations"
						:annotation-context="annotationContext"
						:can-write="canWrite"
						:is-fullscreen="isFullscreen"
						:show-play="false"
						:show-bookmarks="false"
						:show-modify-pdf="false"
						:show-versions="false"
						:show-external="false"
						:show-back="true"
						:back-label="t('sheetmusic', 'Close preview')"
						@back="handleClose"
						@update:annotations="annotations = $event"
						@toggle-fullscreen="toggleFullscreen" />
				</div>
			</FullscreenShell>
		</div>
	</Teleport>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import FullscreenShell from '../FullscreenShell.vue'
import FileViewerContent from '../file-preview/FileViewerContent.vue'
import { useFullscreenBody } from '../../composables/useFullscreenBody'
import { useSetlistFileAnnotations } from '../../composables/useSetlistFileAnnotations'
import type { SetlistFile } from '../../types/song'

const props = withDefaults(defineProps<{
	libraryId: number
	setlistId: number
	file: SetlistFile
	canWrite?: boolean
}>(), {
	canWrite: true,
})

const emit = defineEmits<{
	close: []
}>()

const viewerRef = ref<InstanceType<typeof FileViewerContent> | null>(null)
const { isFullscreen, toggleFullscreen } = useFullscreenBody()

const { annotations, isPdf, fileUrl } = useSetlistFileAnnotations(() => ({
	libraryId: props.libraryId,
	setlistId: props.setlistId,
	file: props.file,
}))

const toolbarFile = computed(() => ({
	id: props.file.id,
	path: props.file.filename,
	fileSize: props.file.fileSize,
}))

const annotationContext = computed(() => ({
	libraryId: props.libraryId,
	setlistId: props.setlistId,
	setlistFileId: props.file.id,
	fileId: props.file.ocFileId,
}))

watch(() => props.file.id, () => {
	viewerRef.value?.exitEditMode()
})

function handleClose() {
	viewerRef.value?.exitEditMode()
	if (isFullscreen.value) {
		toggleFullscreen()
	}
	emit('close')
}

function onKeydown(event: KeyboardEvent) {
	if (event.key === 'Escape' && !event.defaultPrevented) {
		handleClose()
	}
}

onMounted(() => {
	window.addEventListener('keydown', onKeydown)
})

onUnmounted(() => {
	window.removeEventListener('keydown', onKeydown)
})
</script>

<style module>
.backdrop {
	position: fixed;
	inset: 0;
	z-index: 10000;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 16px;
	background-color: rgba(0, 0, 0, 0.5);
	box-sizing: border-box;
}

.backdropFullscreen {
	padding: 0;
	z-index: 100000;
}

.panel {
	width: min(1280px, 100%);
	height: 100%;
	display: flex;
	flex-direction: column;
	padding: 8px;
	background-color: var(--color-main-background);
	border-radius: var(--border-radius-large, 8px);
	box-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
	overflow: hidden;
	box-sizing: border-box;
}

.panelFullscreen {
	width: 100%;
	height: 100%;
	border-radius: 0;
	box-shadow: none;
}

@media (max-width: 640px) {
	.backdrop {
		padding: 0;
	}

	.panel {
		width: 100%;
		border-radius: 0;
	}
}
</style>
