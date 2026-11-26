<script setup lang="ts">
import type { EditorTool, EditorToolStyle } from '../../composables/useAnnotationEditor'
import { isShapeTool } from '../../composables/useAnnotationEditor'
import type { AnnotationLayer, SheetAnnotation } from '../../types/annotation'
import { DEFAULT_LAYER_ID, createDefaultAnnotationLayers } from '../../utils/annotationLayers'
import { MUSIC_FONT_OPTIONS, type MusicFontId } from '../../utils/musicFont'
import { computed, onMounted, onUnmounted, ref, type Component } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import ResponsiveNcButton from '../ResponsiveNcButton.vue'
import ViewportPopover from '../ViewportPopover.vue'
import Check from 'vue-material-design-icons/Check.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import CursorDefaultOutline from 'vue-material-design-icons/CursorDefaultOutline.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import DeleteSweepOutline from 'vue-material-design-icons/DeleteSweepOutline.vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import DrawPen from 'vue-material-design-icons/DrawPen.vue'
import Eraser from 'vue-material-design-icons/Eraser.vue'
import FormatText from 'vue-material-design-icons/FormatText.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import Marker from 'vue-material-design-icons/Marker.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Redo from 'vue-material-design-icons/Redo.vue'
import SelectDrag from 'vue-material-design-icons/SelectDrag.vue'
import Undo from 'vue-material-design-icons/Undo.vue'
import SongAnnotationLayerPicker from './SongAnnotationLayerPicker.vue'
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
		selectedAnnotations?: SheetAnnotation[]
		canUndo?: boolean
		canRedo?: boolean
		isSaving?: boolean
		currentPage?: number
		musicFont?: MusicFontId
		layers?: AnnotationLayer[]
		activeLayerId?: number
	}>(),
	{
		selectedAnnotation: null,
		selectedAnnotations: () => [],
		canUndo: false,
		canRedo: false,
		isSaving: false,
		currentPage: 1,
		musicFont: 'bravura',
		layers: createDefaultAnnotationLayers,
		activeLayerId: DEFAULT_LAYER_ID,
	},
)

const emit = defineEmits<{
	'update:activeTool': [tool: EditorTool]
	'update:toolStyle': [style: EditorToolStyle]
	'update:musicFont': [font: MusicFontId]
	'update:activeLayerId': [id: number]
	'add-layer': [name: string]
	'rename-layer': [id: number, name: string]
	'toggle-layer-visibility': [id: number]
	'reorder-layer': [id: number, direction: 'up' | 'down']
	'delete-layer': [id: number]
	'update-selected-layers': [layerIds: number[]]
	'toggle-selected-layer': [layerId: number]
	'update-selected-style': [patch: Partial<SheetAnnotation['style']>]
	'update-selected-data': [patch: Partial<SheetAnnotation['data']>]
	'pick-selected-link-target': []
	'open-selected-link-picker': []
	undo: []
	redo: []
	'delete-selected': []
	'clear-page': []
	done: []
}>()

const showShapeMenu = ref(false)
const showStampMenu = ref(false)
const showStylusDialog = ref(false)
const showOverflowMenu = ref(false)
const toolbarRef = ref<HTMLElement | null>(null)
const toolbarWidth = ref(0)
const { hasCustomCalibration, isTouchDrawingBlocked } = useStylusSettings()

let resizeObserver: ResizeObserver | null = null

function updateToolbarWidth() {
	const w = toolbarRef.value?.getBoundingClientRect?.().width ?? 0
	if (w > 0) {
		toolbarWidth.value = w
	}
}

onMounted(() => {
	updateToolbarWidth()
	if (toolbarRef.value && typeof ResizeObserver !== 'undefined') {
		resizeObserver = new ResizeObserver(() => updateToolbarWidth())
		resizeObserver.observe(toolbarRef.value)
	}
})

onUnmounted(() => {
	resizeObserver?.disconnect()
	resizeObserver = null
})

// Progressive single-row overflow tiers based on toolbar container width.
// When unmeasured (width === 0, e.g. SSR/tests), keep all controls inline.
const overflowSecondary = computed(() => toolbarWidth.value > 0 && toolbarWidth.value < 1320)
const overflowLayers = computed(() => toolbarWidth.value > 0 && toolbarWidth.value < 1060)
const overflowStyleBar = computed(() => toolbarWidth.value > 0 && toolbarWidth.value < 880)
const hasOverflowItems = computed(() => overflowSecondary.value || overflowLayers.value || overflowStyleBar.value)

const PRIMARY_DRAW_TOOLS: Array<{ id: EditorTool; label: string; icon: Component }> = [
	{ id: 'select', label: t('sheetmusic', 'Select / Move / Resize (V)'), icon: CursorDefaultOutline },
	{ id: 'box-select', label: t('sheetmusic', 'Box Select / Multi-Select (R)'), icon: SelectDrag },
	{ id: 'drawing', label: t('sheetmusic', 'Freehand Pen (P)'), icon: Pencil },
	{ id: 'highlight', label: t('sheetmusic', 'Highlighter (H)'), icon: Marker },
]

const effectiveSelectedAnnotations = computed<SheetAnnotation[]>(() => {
	if (props.selectedAnnotations && props.selectedAnnotations.length > 0) {
		return props.selectedAnnotations
	}
	return props.selectedAnnotation ? [props.selectedAnnotation] : []
})

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
	{
		id: 'delete-selected',
		variant: 'secondary' as const,
		disabled: effectiveSelectedAnnotations.value.length === 0,
		label: effectiveSelectedAnnotations.value.length > 1
			? t('sheetmusic', 'Delete {count} selected annotations (Del)', { count: effectiveSelectedAnnotations.value.length })
			: t('sheetmusic', 'Delete selected annotation (Del)'),
		testId: 'annotation-delete-selected',
		icon: DeleteOutline,
		onClick: () => emit('delete-selected'),
	},
	...(!overflowSecondary.value
		? [{
			id: 'clear-page',
			variant: 'secondary' as const,
			disabled: false,
			label: t('sheetmusic', 'Clear annotations on page {page}', { page: props.currentPage }),
			testId: 'annotation-clear-page',
			icon: DeleteSweepOutline,
			onClick: () => emit('clear-page'),
		}]
		: []),
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
	<div ref="toolbarRef" :class="$style.annotationToolbar" data-test="annotation-editor-toolbar">
		<!-- Single non-wrapping row so toolbar height never shifts the PDF during editing/dragging -->
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
					:music-font="musicFont"
					@toggle="toggleStampMenu"
					@select="pickStamp" />

				<button
					type="button"
					:class="[$style.toolBtn, activeTool === 'handwriting' && $style.active]"
					:title="t('sheetmusic', 'Handwrite Symbols & Fingerings (W)')"
					:aria-label="t('sheetmusic', 'Handwrite Symbols & Fingerings (W)')"
					data-test="tool-handwriting"
					@click="selectTool('handwriting')">
					<DrawPen :size="18" />
				</button>

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
				v-if="!overflowStyleBar"
				:active-tool="activeTool"
				:tool-style="toolStyle"
				:selected-annotation="selectedAnnotation"
				:selected-annotations="effectiveSelectedAnnotations"
				:is-shape-active="isShapeActive"
				@update:tool-style="emit('update:toolStyle', $event)"
				@update-selected-style="emit('update-selected-style', $event)"
				@update-selected-data="emit('update-selected-data', $event)"
				@pick-selected-link-target="emit('pick-selected-link-target')"
				@open-selected-link-picker="emit('open-selected-link-picker')" />

			<div :class="$style.actionGroup">
				<SongAnnotationLayerPicker
					v-if="!overflowLayers"
					:layers="layers"
					:active-layer-id="activeLayerId"
					:selected-annotation="selectedAnnotation"
					:selected-annotations="effectiveSelectedAnnotations"
					@update:active-layer-id="emit('update:activeLayerId', $event)"
					@add-layer="emit('add-layer', $event)"
					@rename-layer="(id, name) => emit('rename-layer', id, name)"
					@toggle-visibility="emit('toggle-layer-visibility', $event)"
					@reorder-layer="(id, dir) => emit('reorder-layer', id, dir)"
					@delete-layer="emit('delete-layer', $event)"
					@update-selected-layers="emit('update-selected-layers', $event)"
					@toggle-selected-layer="emit('toggle-selected-layer', $event)" />

				<select
					v-if="!overflowSecondary"
					:value="musicFont"
					:class="$style.fontSelect"
					:title="t('sheetmusic', 'Music symbol font for this PDF')"
					:aria-label="t('sheetmusic', 'Music symbol font for this PDF')"
					data-test="annotation-music-font-select"
					@change="emit('update:musicFont', ($event.target as HTMLSelectElement).value as MusicFontId)">
					<option
						v-for="opt in MUSIC_FONT_OPTIONS"
						:key="opt.id"
						:value="opt.id">
						{{ opt.label }}
					</option>
				</select>

				<button
					v-if="!overflowSecondary"
					type="button"
					:class="[$style.toolBtn, (hasCustomCalibration || isTouchDrawingBlocked) && $style.active]"
					:title="t('sheetmusic', 'Stylus & Pressure Calibration')"
					:aria-label="t('sheetmusic', 'Stylus & Pressure Calibration')"
					data-test="annotation-stylus-settings"
					@click="showStylusDialog = true">
					<CogOutline :size="18" />
				</button>

				<ViewportPopover
					v-if="hasOverflowItems"
					v-model:open="showOverflowMenu"
					pane-test-id="annotation-toolbar-overflow-menu"
					:pane-class="$style.overflowPane"
					:gap="4">
					<template #trigger="{ toggle }">
						<button
							type="button"
							:class="[$style.toolBtn, showOverflowMenu && $style.active]"
							:title="t('sheetmusic', 'More annotation tools')"
							:aria-label="t('sheetmusic', 'More annotation tools')"
							:aria-expanded="showOverflowMenu"
							data-test="annotation-toolbar-overflow"
							@click="toggle">
							<DotsHorizontal :size="18" />
						</button>
					</template>

					<div v-if="overflowStyleBar" :class="$style.overflowSection">
						<SongAnnotationStyleBar
							:active-tool="activeTool"
							:tool-style="toolStyle"
							:selected-annotation="selectedAnnotation"
							:selected-annotations="effectiveSelectedAnnotations"
							:is-shape-active="isShapeActive"
							@update:tool-style="emit('update:toolStyle', $event)"
							@update-selected-style="emit('update-selected-style', $event)"
							@update-selected-data="emit('update-selected-data', $event)"
				@pick-selected-link-target="emit('pick-selected-link-target')"
				@open-selected-link-picker="emit('open-selected-link-picker')" />
					</div>

					<div v-if="overflowLayers" :class="$style.overflowRow">
						<SongAnnotationLayerPicker
							:layers="layers"
							:active-layer-id="activeLayerId"
							:selected-annotation="selectedAnnotation"
							:selected-annotations="effectiveSelectedAnnotations"
							@update:active-layer-id="emit('update:activeLayerId', $event)"
							@add-layer="emit('add-layer', $event)"
							@rename-layer="(id, name) => emit('rename-layer', id, name)"
							@toggle-visibility="emit('toggle-layer-visibility', $event)"
							@reorder-layer="(id, dir) => emit('reorder-layer', id, dir)"
							@delete-layer="emit('delete-layer', $event)"
							@update-selected-layers="emit('update-selected-layers', $event)"
							@toggle-selected-layer="emit('toggle-selected-layer', $event)" />
					</div>

					<div v-if="overflowSecondary" :class="$style.overflowRow">
						<select
							:value="musicFont"
							:class="[$style.fontSelect, $style.overflowSelect]"
							:title="t('sheetmusic', 'Music symbol font for this PDF')"
							:aria-label="t('sheetmusic', 'Music symbol font for this PDF')"
							data-test="annotation-music-font-select"
							@change="emit('update:musicFont', ($event.target as HTMLSelectElement).value as MusicFontId)">
							<option
								v-for="opt in MUSIC_FONT_OPTIONS"
								:key="opt.id"
								:value="opt.id">
								{{ opt.label }}
							</option>
						</select>
					</div>

					<div v-if="overflowSecondary" :class="$style.overflowActions">
						<button
							type="button"
							:class="[$style.overflowActionBtn, (hasCustomCalibration || isTouchDrawingBlocked) && $style.active]"
							data-test="annotation-stylus-settings"
							@click="showOverflowMenu = false; showStylusDialog = true">
							<CogOutline :size="18" />
							<span>{{ t('sheetmusic', 'Stylus & Pressure Calibration') }}</span>
						</button>
						<button
							type="button"
							:class="$style.overflowActionBtn"
							data-test="annotation-clear-page"
							@click="showOverflowMenu = false; emit('clear-page')">
							<DeleteSweepOutline :size="18" />
							<span>{{ t('sheetmusic', 'Clear annotations on page {page}', { page: currentPage }) }}</span>
						</button>
					</div>
				</ViewportPopover>

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

		<span v-if="isSaving" :class="$style.savingBadge" aria-live="polite">{{ t('sheetmusic', 'Saving...') }}</span>

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
	flex-direction: row;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	flex-wrap: nowrap;
	min-height: var(--default-clickable-area, 44px);
	position: relative;
	z-index: 40;
}

.toolGroup,
.actionGroup {
	display: flex;
	flex-direction: row;
	align-items: center;
	gap: 4px;
	flex-wrap: nowrap;
	flex-shrink: 0;
}

.actionGroup {
	margin-left: auto;
}

.overflowPane {
	min-width: 240px;
	max-width: min(360px, calc(100vw - 24px));
	padding: 8px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.overflowSection :global(div) {
	flex-wrap: wrap;
}

.overflowRow {
	display: flex;
	align-items: center;
	gap: 6px;
}

.overflowSelect {
	width: 100%;
	height: 36px;
}

.overflowActions {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding-top: 6px;
	border-top: 1px solid var(--color-border);
}

button[type='button'].overflowActionBtn {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
	min-height: 36px;
	margin: 0;
	padding: 6px 8px;
	border: none;
	border-radius: var(--border-radius, 4px);
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.84rem;
	text-align: left;
	cursor: pointer;
}

button[type='button'].overflowActionBtn:hover {
	background: var(--color-background-hover);
}

select.fontSelect {
	width: auto;
	height: var(--default-clickable-area, 44px);
	margin: 0;
	padding: 0 10px;
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.82rem;
	cursor: pointer;
}

button[type='button'].toolBtn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 5px;
	width: auto;
	min-width: var(--default-clickable-area, 44px);
	min-height: var(--default-clickable-area, 44px);
	margin: 0;
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
	position: absolute;
	right: 12px;
	bottom: 2px;
	z-index: 45;
	padding: 1px 6px;
	border-radius: 4px;
	background: var(--color-main-background);
	font-size: 0.72rem;
	line-height: 1.2;
	color: var(--color-text-maxcontrast);
	pointer-events: none;
}
</style>
