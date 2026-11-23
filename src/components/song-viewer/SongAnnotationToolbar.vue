<script setup lang="ts">
import type { EditorTool, EditorToolStyle } from '../../composables/useAnnotationEditor'
import { isShapeTool } from '../../composables/useAnnotationEditor'
import type { SheetAnnotation } from '../../types/annotation'
import { computed, ref, type Component } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import ResponsiveNcButton from '../ResponsiveNcButton.vue'
import Check from 'vue-material-design-icons/Check.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import CursorDefaultOutline from 'vue-material-design-icons/CursorDefaultOutline.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import DeleteSweepOutline from 'vue-material-design-icons/DeleteSweepOutline.vue'
import Eraser from 'vue-material-design-icons/Eraser.vue'
import FormatText from 'vue-material-design-icons/FormatText.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import Marker from 'vue-material-design-icons/Marker.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Redo from 'vue-material-design-icons/Redo.vue'
import Undo from 'vue-material-design-icons/Undo.vue'
import SongAnnotationShapePicker from './SongAnnotationShapePicker.vue'
import SongAnnotationStampPicker from './SongAnnotationStampPicker.vue'
import SongAnnotationStyleBar from './SongAnnotationStyleBar.vue'
import SongAnnotationStylusDialog from './SongAnnotationStylusDialog.vue'
import { useStylusSettings } from '../../composables/useStylusSettings'

const props = withDefaults(
	defineProps<{
		activeTool: EditorTool
		toolStyle: EditorToolStyle
		selectedAnnotation?: SheetAnnotation | null
		canUndo?: boolean
		canRedo?: boolean
		isSaving?: boolean
		currentPage?: number
	}>(),
	{
		selectedAnnotation: null,
		canUndo: false,
		canRedo: false,
		isSaving: false,
		currentPage: 1,
	},
)

const emit = defineEmits<{
	'update:activeTool': [tool: EditorTool]
	'update:toolStyle': [style: EditorToolStyle]
	'update-selected-style': [patch: Partial<SheetAnnotation['style']>]
	'update-selected-data': [patch: Partial<SheetAnnotation['data']>]
	undo: []
	redo: []
	'delete-selected': []
	'clear-page': []
	done: []
}>()

const showShapeMenu = ref(false)
const showStampMenu = ref(false)
const showStylusDialog = ref(false)
const { hasCustomCalibration, isTouchDrawingBlocked } = useStylusSettings()

const PRIMARY_DRAW_TOOLS: Array<{ id: EditorTool; label: string; icon: Component }> = [
	{ id: 'select', label: t('sheetmusic', 'Select / Move / Resize (V)'), icon: CursorDefaultOutline },
	{ id: 'drawing', label: t('sheetmusic', 'Freehand Pen (P)'), icon: Pencil },
	{ id: 'highlight', label: t('sheetmusic', 'Highlighter (H)'), icon: Marker },
]

const TRAILING_TOOLS: Array<{ id: EditorTool; label: string; icon: Component }> = [
	{ id: 'link', label: t('sheetmusic', 'Page Jump Link (L)'), icon: LinkVariant },
	{ id: 'eraser', label: t('sheetmusic', 'Eraser (E)'), icon: Eraser },
]

const actionButtons = computed(() => [
	{
		id: 'undo',
		variant: 'secondary' as const,
		disabled: !props.canUndo,
		label: t('sheetmusic', 'Undo (Ctrl+Z)'),
		testId: 'annotation-undo',
		icon: Undo,
		onClick: () => emit('undo'),
	},
	{
		id: 'redo',
		variant: 'secondary' as const,
		disabled: !props.canRedo,
		label: t('sheetmusic', 'Redo (Ctrl+Y)'),
		testId: 'annotation-redo',
		icon: Redo,
		onClick: () => emit('redo'),
	},
	...(props.selectedAnnotation
		? [{
			id: 'delete-selected',
			variant: 'secondary' as const,
			disabled: false,
			label: t('sheetmusic', 'Delete selected annotation (Del)'),
			testId: 'annotation-delete-selected',
			icon: DeleteOutline,
			onClick: () => emit('delete-selected'),
		}]
		: []),
	{
		id: 'clear-page',
		variant: 'secondary' as const,
		disabled: false,
		label: t('sheetmusic', 'Clear annotations on page {page}', { page: props.currentPage }),
		testId: 'annotation-clear-page',
		icon: DeleteSweepOutline,
		onClick: () => emit('clear-page'),
	},
	{
		id: 'done',
		variant: 'primary' as const,
		disabled: false,
		label: t('sheetmusic', 'Done'),
		testId: 'annotation-done',
		icon: Check,
		onClick: () => emit('done'),
	},
])

const isShapeActive = computed(() => isShapeTool(props.activeTool))

function selectTool(tool: EditorTool) {
	showShapeMenu.value = false
	if (tool !== 'stamp') {
		showStampMenu.value = false
	}
	emit('update:activeTool', tool)
}

function toggleShapeMenu() {
	showStampMenu.value = false
	showShapeMenu.value = !showShapeMenu.value
}

function toggleStampMenu() {
	showShapeMenu.value = false
	if (props.activeTool !== 'stamp') {
		emit('update:activeTool', 'stamp')
		showStampMenu.value = true
	} else {
		showStampMenu.value = !showStampMenu.value
	}
}

function pickStamp(symbol: string) {
	emit('update:toolStyle', { ...props.toolStyle, stampSymbol: symbol })
	emit('update:activeTool', 'stamp')
	showStampMenu.value = false
}
</script>

<template>
	<div :class="$style.annotationToolbar" data-test="annotation-editor-toolbar">
		<!-- Row 1: Primary Tools & History -->
		<div :class="$style.row">
			<div :class="$style.toolGroup">
				<button
					v-for="tool in PRIMARY_DRAW_TOOLS"
					:key="tool.id"
					type="button"
					:class="[$style.toolBtn, activeTool === tool.id && $style.active]"
					:title="tool.label"
					:aria-label="tool.label"
					:data-test="`tool-${tool.id}`"
					@click="selectTool(tool.id)">
					<component :is="tool.icon" :size="18" />
				</button>

				<SongAnnotationShapePicker
					v-model:open="showShapeMenu"
					:active-tool="activeTool"
					@toggle="toggleShapeMenu"
					@select="selectTool" />

				<button
					type="button"
					:class="[$style.toolBtn, activeTool === 'text' && $style.active]"
					:title="t('sheetmusic', 'Text Box (T)')"
					:aria-label="t('sheetmusic', 'Text Box (T)')"
					data-test="tool-text"
					@click="selectTool('text')">
					<FormatText :size="18" />
				</button>

				<SongAnnotationStampPicker
					:active="activeTool === 'stamp'"
					:open="showStampMenu"
					:stamp-symbol="toolStyle.stampSymbol"
					@toggle="toggleStampMenu"
					@select="pickStamp" />

				<button
					v-for="tool in TRAILING_TOOLS"
					:key="tool.id"
					type="button"
					:class="[$style.toolBtn, activeTool === tool.id && $style.active]"
					:title="tool.label"
					:aria-label="tool.label"
					:data-test="`tool-${tool.id}`"
					@click="selectTool(tool.id)">
					<component :is="tool.icon" :size="18" />
				</button>
			</div>

			<SongAnnotationStyleBar
				:active-tool="activeTool"
				:tool-style="toolStyle"
				:selected-annotation="selectedAnnotation"
				:is-shape-active="isShapeActive"
				@update:tool-style="emit('update:toolStyle', $event)"
				@update-selected-style="emit('update-selected-style', $event)"
				@update-selected-data="emit('update-selected-data', $event)" />

			<div :class="$style.actionGroup">
				<span v-if="isSaving" :class="$style.savingBadge">{{ t('sheetmusic', 'Saving...') }}</span>

				<button
					type="button"
					:class="[$style.toolBtn, (hasCustomCalibration || isTouchDrawingBlocked) && $style.active]"
					:title="t('sheetmusic', 'Stylus & Pressure Calibration')"
					:aria-label="t('sheetmusic', 'Stylus & Pressure Calibration')"
					data-test="annotation-stylus-settings"
					@click="showStylusDialog = true">
					<CogOutline :size="18" />
				</button>

				<ResponsiveNcButton
					v-for="btn in actionButtons"
					:key="btn.id"
					icon-only
					:variant="btn.variant"
					:disabled="btn.disabled"
					:label="btn.label"
					:data-test="btn.testId"
					@click="btn.onClick">
					<template #icon><component :is="btn.icon" :size="20" /></template>
				</ResponsiveNcButton>
			</div>
		</div>

		<SongAnnotationStylusDialog
			v-if="showStylusDialog"
			@close="showStylusDialog = false" />
	</div>
</template>

<style module>
.annotationToolbar {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 8px 12px;
	background: var(--color-main-background);
	border-bottom: 1px solid var(--color-border);
	position: relative;
	z-index: 30;
}

.row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	flex-wrap: wrap;
	position: relative;
	z-index: 40;
}

.toolGroup,
.actionGroup {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-wrap: wrap;
}

.toolBtn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 5px;
	min-width: var(--default-clickable-area, 44px);
	min-height: var(--default-clickable-area, 44px);
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.85rem;
	cursor: pointer;
	transition: background-color 0.12s, border-color 0.12s;
}

.toolBtn:hover {
	background: var(--color-background-hover);
}

.toolBtn.active {
	background: var(--color-primary-light, #e6f4fa);
	border-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element, #0082c9);
	font-weight: 600;
}

.savingBadge {
	font-size: 0.78rem;
	color: var(--color-text-maxcontrast);
	margin-right: 4px;
}
</style>
