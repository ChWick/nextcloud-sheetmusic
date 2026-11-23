<script setup lang="ts">
import { computed, ref } from 'vue'
import AnnotatableFilePreview from './AnnotatableFilePreview.vue'
import SongViewerFileToolbar from '../song-viewer/SongViewerFileToolbar.vue'
import type { AnnotationEditorContext } from '../../composables/useAnnotationEditor'
import type { SheetAnnotation } from '../../types/annotation'
import type { Bookmark, SongFile } from '../../types/song'

const props = withDefaults(defineProps<{
	file: Pick<SongFile, 'id' | 'path'> & { fileSize?: number | null }
	files?: SongFile[]
	filePath: string
	fileName?: string
	isPdf: boolean
	pageOrder?: string | null
	targetPage?: number
	previewKey?: string | number
	annotations?: SheetAnnotation[]
	annotationContext: AnnotationEditorContext
	canWrite?: boolean
	isFullscreen?: boolean
	bookmarks?: Bookmark[]
	activeBookmarkPage?: number
	showPlay?: boolean
	showBookmarks?: boolean
	showModifyPdf?: boolean
	showVersions?: boolean
	showExternal?: boolean
	showBack?: boolean
	backLabel?: string
}>(), {
	files: () => [],
	fileName: undefined,
	pageOrder: undefined,
	targetPage: undefined,
	previewKey: undefined,
	annotations: () => [],
	canWrite: true,
	isFullscreen: false,
	bookmarks: () => [],
	activeBookmarkPage: undefined,
	showPlay: true,
	showBookmarks: true,
	showModifyPdf: true,
	showVersions: true,
	showExternal: true,
	showBack: false,
	backLabel: undefined,
})

const emit = defineEmits<{
	back: []
	'update:annotations': [annotations: SheetAnnotation[]]
	'update:isEditing': [editing: boolean]
	'select-file': [fileId: string]
	play: []
	'open-external': []
	'modify-pdf': []
	'merge-pdfs': []
	'open-versions': []
	'toggle-fullscreen': []
	'jump-to-bookmark': [pageNum: number]
	'edit-bookmarks': []
}>()

const previewRef = ref<InstanceType<typeof AnnotatableFilePreview> | null>(null)
const isEditingAnnotations = ref(false)

function onEditingChange(editing: boolean) {
	isEditingAnnotations.value = editing
	emit('update:isEditing', editing)
}

const currentPage = computed(() => previewRef.value?.currentPage ?? 1)

function exitEditMode() {
	previewRef.value?.exitEditMode()
}

defineExpose({
	currentPage,
	exitEditMode,
})
</script>

<template>
	<div :class="$style.content">
		<SongViewerFileToolbar
			:file="file"
			:files="files"
			:is-pdf="isPdf"
			:current-page="previewRef?.currentPage ?? 1"
			:total-pages="previewRef?.totalPages ?? 1"
			:file-size-bytes="previewRef?.fileSizeBytes ?? null"
			:page-resolutions="previewRef?.pageResolutions ?? []"
			:show-annotations="previewRef?.showAnnotationsState ?? true"
			:is-editing-annotations="isEditingAnnotations"
			:is-fullscreen="isFullscreen"
			:can-write="canWrite"
			:bookmarks="bookmarks"
			:active-bookmark-page="activeBookmarkPage"
			:show-play="showPlay"
			:show-bookmarks="showBookmarks"
			:show-modify-pdf="showModifyPdf"
			:show-versions="showVersions"
			:show-external="showExternal"
			:show-back="showBack"
			:back-label="backLabel"
			@back="emit('back')"
			@select-file="emit('select-file', $event)"
			@play="emit('play')"
			@open-external="emit('open-external')"
			@modify-pdf="emit('modify-pdf')"
			@merge-pdfs="emit('merge-pdfs')"
			@open-versions="emit('open-versions')"
			@toggle-annotations="previewRef?.toggleAnnotations()"
			@toggle-edit-annotations="previewRef?.toggleEditMode()"
			@toggle-fullscreen="emit('toggle-fullscreen')"
			@jump-to-bookmark="emit('jump-to-bookmark', $event)"
			@edit-bookmarks="emit('edit-bookmarks')" />

		<AnnotatableFilePreview
			v-if="filePath"
			ref="previewRef"
			:preview-key="previewKey"
			:file-path="filePath"
			:file-name="fileName"
			:page-order="pageOrder"
			:target-page="targetPage"
			:show-external-button="false"
			:annotations="annotations"
			:annotation-context="annotationContext"
			:can-annotate="isPdf && canWrite !== false"
			@update:annotations="emit('update:annotations', $event)"
			@update:is-editing="onEditingChange" />
		<slot v-else name="missing-file" />
	</div>
</template>

<style module>
.content {
	display: flex;
	flex-direction: column;
	width: 100%;
	height: 100%;
	flex: 1;
	min-height: 0;
	overflow: hidden;
}
</style>
