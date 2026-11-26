<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import FilePreviewer from '../FilePreviewer.vue'
import SongAnnotationToolbar from '../song-viewer/SongAnnotationToolbar.vue'
import { type AnnotationEditorContext, useAnnotationEditor } from '../../composables/useAnnotationEditor'
import { useAnnotationLayers } from '../../composables/useAnnotationLayers'
import type { SheetAnnotation } from '../../types/annotation'
import type { Bookmark } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import { roundNorm } from '../../utils/annotationTransform'
import type { LinkTargetPickRequest, LinkTargetPickResult } from '../../utils/annotationLinks'
import {
	getCachedFileMusicFont,
	loadFileMusicFont,
	persistFileMusicFont,
	type MusicFontId,
} from '../../utils/musicFont'

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
	bookmarks?: Bookmark[]
}>(), {
	fileName: undefined,
	pageOrder: undefined,
	targetPage: undefined,
	showExternalButton: false,
	previewKey: undefined,
	annotations: () => [],
	canAnnotate: true,
	bookmarks: () => [],
})

const emit = defineEmits<{
	'update:annotations': [annotations: SheetAnnotation[]]
	'update:isEditing': [editing: boolean]
}>()

const filePreviewerRef = ref<InstanceType<typeof FilePreviewer> | null>(null)
const localAnnotations = ref<SheetAnnotation[]>([...props.annotations])
const musicFont = ref<MusicFontId>(getCachedFileMusicFont(props.annotationContext.fileId))

watch(
	() => props.annotationContext.fileId,
	(fileId) => {
		musicFont.value = getCachedFileMusicFont(fileId)
		void loadFileMusicFont(fileId).then((loaded) => {
			if (props.annotationContext.fileId === fileId) {
				musicFont.value = loaded
			}
		})
	},
	{ immediate: true },
)

function updateMusicFont(font: MusicFontId) {
	musicFont.value = font
	void persistFileMusicFont(props.annotationContext.fileId, font)
}

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
	selectedUuids,
	selectedAnnotation,
	selectedAnnotations,
	isSaving,
	canUndo,
	canRedo,
	enterEditMode: rawEnterEditMode,
	exitEditMode: rawExitEditMode,
	setTool,
	selectAnnotation,
	selectAnnotations,
	createAnnotation,
	updateAnnotation,
	batchUpdateAnnotations,
	updateSelectedStyle,
	updateSelectedData,
	deleteAnnotation,
	deleteSelected,
	clearPage,
	undo,
	redo,
} = useAnnotationEditor(localAnnotations, () => props.annotationContext)

const {
	layers: annotationLayers,
	activeLayerId,
	visibleAnnotations,
	addLayer: handleAddLayer,
	renameLayer: handleRenameLayer,
	toggleLayerVisibility: handleToggleLayerVisibility,
	reorderLayer: handleReorderLayer,
	deleteLayer: handleDeleteLayer,
	updateSelectedLayers: handleUpdateSelectedLayers,
	toggleSelectedLayer: handleToggleSelectedLayer,
	createAnnotationOnActiveLayer: handleCreateAnnotation,
} = useAnnotationLayers({
	fileId: () => props.annotationContext.fileId,
	annotations: localAnnotations,
	selectedAnnotation,
	selectedAnnotations,
	onClearSelection: () => selectAnnotation(null),
	onSelectAnnotations: selectAnnotations,
	onCreateAnnotation: createAnnotation,
	onUpdateAnnotation: updateAnnotation,
	onBatchUpdateAnnotations: batchUpdateAnnotations,
})

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
const linkTargetPick = ref<LinkTargetPickRequest | null>(null)

function handleStartPickLinkTarget(req: LinkTargetPickRequest) {
	linkTargetPick.value = req
}

function handlePickSelectedLinkTarget() {
	const sel = selectedAnnotation.value
	if (!sel || sel.type !== 'link') return
	linkTargetPick.value = {
		mode: 'edit',
		existing: sel,
	}
}

function cancelLinkTargetPick() {
	linkTargetPick.value = null
}

function handleCompletePickLinkTarget(res: LinkTargetPickResult) {
	const req = linkTargetPick.value
	if (!req) return
	linkTargetPick.value = null

	const targetX = roundNorm(res.x)
	const targetY = roundNorm(res.y)

	if (req.mode === 'edit' && req.existing) {
		updateAnnotation(req.existing, {
			...req.existing,
			data: {
				...req.existing.data,
				targetPage: res.pageIndex,
				targetX,
				targetY,
			},
		})
		return
	}

	const label = req.label?.trim() || `→ p. ${res.pageIndex + 1}`
	handleCreateAnnotation({
		pageNumber: req.sourcePageIndex ?? Math.max(0, currentPage.value - 1),
		layerIndex: 0,
		type: 'link',
		x: roundNorm(req.x ?? 0.1),
		y: roundNorm(req.y ?? 0.1),
		width: roundNorm(req.width ?? 0.1),
		height: roundNorm(req.height ?? 0.06),
		style: {
			color: toolStyle.value.color || '#0052cc',
			opacity: 1,
			fontSize: toolStyle.value.fontSize,
		},
		data: {
			targetPage: res.pageIndex,
			targetX,
			targetY,
			label,
		},
	})
}

watch(isEditing, (editing) => {
	if (!editing) {
		linkTargetPick.value = null
	}
})

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
		<SongAnnotationToolbar
			v-if="canAnnotate && isEditing"
			:active-tool="activeTool"
			:tool-style="toolStyle"
			:selected-annotation="selectedAnnotation"
			:selected-annotations="selectedAnnotations"
			:can-undo="canUndo"
			:can-redo="canRedo"
			:is-saving="isSaving"
			:current-page="currentPage"
			:music-font="musicFont"
			:layers="annotationLayers"
			:active-layer-id="activeLayerId"
			@update:active-tool="setTool"
			@update:tool-style="toolStyle = $event"
			@update:music-font="updateMusicFont"
			@update:active-layer-id="activeLayerId = $event"
			@add-layer="handleAddLayer"
			@rename-layer="handleRenameLayer"
			@toggle-layer-visibility="handleToggleLayerVisibility"
			@reorder-layer="handleReorderLayer"
			@delete-layer="handleDeleteLayer"
			@update-selected-layers="handleUpdateSelectedLayers"
			@toggle-selected-layer="handleToggleSelectedLayer"
			@update-selected-style="updateSelectedStyle"
			@update-selected-data="updateSelectedData"
			@pick-selected-link-target="handlePickSelectedLinkTarget"
			@undo="undo"
			@redo="redo"
			@delete-selected="deleteSelected"
			@clear-page="clearPage(currentPage - 1)"
			@done="exitEditMode" />

		<div
			v-if="linkTargetPick"
			:class="$style.pickBanner"
			role="status"
			data-test="link-target-pick-banner">
			<span>{{ t('sheetmusic', 'Click the exact destination measure on any page') }}</span>
			<button
				type="button"
				:class="$style.pickCancelBtn"
				data-test="link-target-pick-cancel"
				@click="cancelLinkTargetPick">
				{{ t('sheetmusic', 'Cancel') }}
			</button>
		</div>

		<FilePreviewer
			ref="filePreviewerRef"
			:key="previewKey"
			:file-path="filePath"
			:file-name="fileName"
			:page-order="pageOrder"
			:target-page="targetPage"
			:show-external-button="showExternalButton"
			:annotations="visibleAnnotations"
			:editable-annotations="canAnnotate && isEditing"
			:active-annotation-tool="activeTool"
			:annotation-tool-style="toolStyle"
			:selected-annotation-uuid="selectedUuid"
			:selected-annotation-uuids="selectedUuids"
			:music-font="musicFont"
			:bookmarks="bookmarks"
			:picking-link-target="linkTargetPick !== null"
			@select-annotation="selectAnnotation"
			@select-annotations="selectAnnotations"
			@create-annotation="handleCreateAnnotation"
			@update-annotation="updateAnnotation"
			@batch-update-annotations="batchUpdateAnnotations"
			@delete-annotation="deleteAnnotation"
			@start-pick-link-target="handleStartPickLinkTarget"
			@complete-pick-link-target="handleCompletePickLinkTarget" />
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

.pickBanner {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	padding: 6px 12px;
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #ffffff);
	font-size: 0.85rem;
	font-weight: 600;
}

button[type='button'].pickCancelBtn {
	margin: 0;
	padding: 2px 10px;
	min-height: 26px;
	border: 1px solid rgba(255, 255, 255, 0.7);
	border-radius: 4px;
	background: transparent;
	color: inherit;
	font-size: 0.8rem;
	cursor: pointer;
}
</style>
