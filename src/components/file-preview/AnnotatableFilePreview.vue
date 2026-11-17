<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import PencilOutlineIcon from 'vue-material-design-icons/PencilOutline.vue'
import FilePreviewer from '../FilePreviewer.vue'
import SongAnnotationToolbar from '../song-viewer/SongAnnotationToolbar.vue'
import { type AnnotationEditorContext, useAnnotationEditor } from '../../composables/useAnnotationEditor'
import type { SheetAnnotation } from '../../types/annotation'

const props = withDefaults(defineProps<{
	filePath: string
	fileName?: string
	pageOrder?: string | null
	targetPage?: number
	showExternalButton?: boolean
	previewKey?: string | number
	annotations?: SheetAnnotation[]
	annotationContext: AnnotationEditorContext
	canAnnotate?: boolean
	showAnnotateButton?: boolean
	isFullscreen?: boolean
}>(), {
	fileName: undefined,
	pageOrder: undefined,
	targetPage: undefined,
	showExternalButton: false,
	previewKey: undefined,
	annotations: () => [],
	canAnnotate: true,
	showAnnotateButton: false,
	isFullscreen: false,
})

const emit = defineEmits<{
	'update:annotations': [annotations: SheetAnnotation[]]
	'update:isEditing': [editing: boolean]
	'toggle-fullscreen': []
}>()

const filePreviewerRef = ref<InstanceType<typeof FilePreviewer> | null>(null)
const localAnnotations = ref<SheetAnnotation[]>([...props.annotations])

watch(() => props.annotations, (next) => {
	if (next !== localAnnotations.value) {
		localAnnotations.value = [...next]
	}
})

watch(localAnnotations, (next) => {
	emit('update:annotations', next)
})

const {
	isEditing,
	activeTool,
	toolStyle,
	selectedUuid,
	selectedAnnotation,
	isSaving,
	canUndo,
	canRedo,
	enterEditMode: rawEnterEditMode,
	exitEditMode: rawExitEditMode,
	setTool,
	selectAnnotation,
	createAnnotation,
	updateAnnotation,
	updateSelectedStyle,
	updateSelectedData,
	deleteAnnotation,
	deleteSelected,
	clearPage,
	undo,
	redo,
} = useAnnotationEditor(localAnnotations, () => props.annotationContext)

watch(isEditing, (editing) => {
	emit('update:isEditing', editing)
})

function enterEditMode() {
	if (!props.canAnnotate) return
	if (filePreviewerRef.value && !filePreviewerRef.value.showAnnotationsState) {
		filePreviewerRef.value.toggleAnnotations()
	}
	rawEnterEditMode()
}

function exitEditMode() {
	rawExitEditMode()
}

function toggleEditMode() {
	if (isEditing.value) {
		exitEditMode()
	} else {
		enterEditMode()
	}
}

const currentPage = computed(() => filePreviewerRef.value?.currentPage ?? 1)
const totalPages = computed(() => filePreviewerRef.value?.totalPages ?? 1)
const fileSizeBytes = computed(() => filePreviewerRef.value?.fileSizeBytes ?? null)
const pageResolutions = computed(() => filePreviewerRef.value?.pageResolutions ?? [])
const showAnnotationsState = computed(() => filePreviewerRef.value?.showAnnotationsState ?? true)

function toggleAnnotations() {
	filePreviewerRef.value?.toggleAnnotations()
}

defineExpose({
	currentPage,
	totalPages,
	fileSizeBytes,
	pageResolutions,
	showAnnotationsState,
	isEditing,
	enterEditMode,
	exitEditMode,
	toggleEditMode,
	toggleAnnotations,
})
</script>

<template>
	<div :class="$style.container">
		<div
			v-if="showAnnotateButton && canAnnotate && !isEditing"
			:class="$style.actionsBar">
			<NcButton
				size="small"
				variant="secondary"
				@click="enterEditMode">
				<template #icon>
					<PencilOutlineIcon :size="16" />
				</template>
				{{ t('sheetmusic', 'Annotate') }}
			</NcButton>
		</div>

		<SongAnnotationToolbar
			v-if="canAnnotate && isEditing"
			:active-tool="activeTool"
			:tool-style="toolStyle"
			:selected-annotation="selectedAnnotation"
			:can-undo="canUndo"
			:can-redo="canRedo"
			:is-saving="isSaving"
			:current-page="currentPage"
			:is-fullscreen="isFullscreen"
			@update:active-tool="setTool"
			@update:tool-style="toolStyle = $event"
			@update-selected-style="updateSelectedStyle"
			@update-selected-data="updateSelectedData"
			@undo="undo"
			@redo="redo"
			@delete-selected="deleteSelected"
			@clear-page="clearPage(currentPage - 1)"
			@toggle-fullscreen="emit('toggle-fullscreen')"
			@done="exitEditMode" />

		<slot name="betweenToolbarAndPreview" :current-page="currentPage" />

		<FilePreviewer
			ref="filePreviewerRef"
			:key="previewKey"
			:file-path="filePath"
			:file-name="fileName"
			:page-order="pageOrder"
			:target-page="targetPage"
			:show-external-button="showExternalButton"
			:annotations="localAnnotations"
			:editable-annotations="canAnnotate && isEditing"
			:active-annotation-tool="activeTool"
			:annotation-tool-style="toolStyle"
			:selected-annotation-uuid="selectedUuid"
			@select-annotation="selectAnnotation"
			@create-annotation="createAnnotation"
			@update-annotation="updateAnnotation"
			@delete-annotation="deleteAnnotation" />
	</div>
</template>

<style module>
.container {
	display: flex;
	flex-direction: column;
	flex: 1;
	min-height: 0;
	overflow: hidden;
}

.actionsBar {
	display: flex;
	justify-content: flex-end;
	padding: 6px 12px;
	border-bottom: 1px solid var(--color-border);
	flex-shrink: 0;
}
</style>
