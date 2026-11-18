<script setup lang="ts">
import type { EditorTool, EditorToolStyle } from '../../composables/useAnnotationEditor'
import type { SheetAnnotation } from '../../types/annotation'
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import Check from 'vue-material-design-icons/Check.vue'
import CursorDefaultOutline from 'vue-material-design-icons/CursorDefaultOutline.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import DeleteSweepOutline from 'vue-material-design-icons/DeleteSweepOutline.vue'
import Eraser from 'vue-material-design-icons/Eraser.vue'
import FormatText from 'vue-material-design-icons/FormatText.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import Marker from 'vue-material-design-icons/Marker.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Redo from 'vue-material-design-icons/Redo.vue'
import ShapeOutline from 'vue-material-design-icons/ShapeOutline.vue'
import Undo from 'vue-material-design-icons/Undo.vue'
import SongAnnotationStampPicker from './SongAnnotationStampPicker.vue'
import SongAnnotationStyleBar from './SongAnnotationStyleBar.vue'

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

const SHAPE_TOOLS: Array<{ id: EditorTool; label: string; glyph: string }> = [
	{ id: 'line', label: 'Line', glyph: '╱' },
	{ id: 'arrow', label: 'Arrow', glyph: '↗' },
	{ id: 'crescendo', label: 'Crescendo (<)', glyph: '<' },
	{ id: 'decrescendo', label: 'Decrescendo (>)', glyph: '>' },
	{ id: 'slur', label: 'Slur / Tie', glyph: '⌒' },
	{ id: 'bracket', label: 'Bracket', glyph: '┌┐' },
	{ id: 'rect', label: 'Rectangle', glyph: '□' },
	{ id: 'ellipse', label: 'Circle / Ellipse', glyph: '○' },
]

const isShapeActive = computed(() => SHAPE_TOOLS.some((s) => s.id === props.activeTool))
const activeShapeOption = computed(() => SHAPE_TOOLS.find((s) => s.id === props.activeTool) ?? null)
const activeShapeLabel = computed(() =>
	activeShapeOption.value
		? `${activeShapeOption.value.label} (S)`
		: t('sheetmusic', 'Shapes & Hairpins (S)'),
)

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
					type="button"
					:class="[$style.toolBtn, activeTool === 'select' && $style.active]"
					:title="t('sheetmusic', 'Select / Move / Resize (V)')"
					:aria-label="t('sheetmusic', 'Select / Move / Resize (V)')"
					data-test="tool-select"
					@click="selectTool('select')">
					<CursorDefaultOutline :size="18" />
				</button>

				<button
					type="button"
					:class="[$style.toolBtn, activeTool === 'drawing' && $style.active]"
					:title="t('sheetmusic', 'Freehand Pen (P)')"
					:aria-label="t('sheetmusic', 'Freehand Pen (P)')"
					data-test="tool-drawing"
					@click="selectTool('drawing')">
					<Pencil :size="18" />
				</button>

				<button
					type="button"
					:class="[$style.toolBtn, activeTool === 'highlight' && $style.active]"
					:title="t('sheetmusic', 'Highlighter (H)')"
					:aria-label="t('sheetmusic', 'Highlighter (H)')"
					data-test="tool-highlight"
					@click="selectTool('highlight')">
					<Marker :size="18" />
				</button>

				<!-- Shapes Dropdown -->
				<div :class="$style.popoverWrap">
					<button
						type="button"
						:class="[$style.toolBtn, isShapeActive && $style.active]"
						:title="activeShapeLabel"
						:aria-label="activeShapeLabel"
						data-test="tool-shapes"
						@click="toggleShapeMenu">
						<span v-if="activeShapeOption" :class="$style.shapeGlyph">{{ activeShapeOption.glyph }}</span>
						<ShapeOutline v-else :size="18" />
					</button>
					<div v-if="showShapeMenu" :class="$style.dropdownMenu" data-test="shapes-menu">
						<button
							v-for="shape in SHAPE_TOOLS"
							:key="shape.id"
							type="button"
							:class="[$style.menuItem, activeTool === shape.id && $style.active]"
							:data-test="`tool-${shape.id}`"
							@click="selectTool(shape.id)">
							<span :class="$style.shapeGlyph">{{ shape.glyph }}</span>
							<span>{{ shape.label }}</span>
						</button>
					</div>
				</div>

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
					type="button"
					:class="[$style.toolBtn, activeTool === 'link' && $style.active]"
					:title="t('sheetmusic', 'Page Jump Link (L)')"
					:aria-label="t('sheetmusic', 'Page Jump Link (L)')"
					data-test="tool-link"
					@click="selectTool('link')">
					<LinkVariant :size="18" />
				</button>

				<button
					type="button"
					:class="[$style.toolBtn, activeTool === 'eraser' && $style.active]"
					:title="t('sheetmusic', 'Eraser (E)')"
					:aria-label="t('sheetmusic', 'Eraser (E)')"
					data-test="tool-eraser"
					@click="selectTool('eraser')">
					<Eraser :size="18" />
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

				<NcButton
					variant="secondary"
					:disabled="!canUndo"
					:title="t('sheetmusic', 'Undo (Ctrl+Z)')"
					:aria-label="t('sheetmusic', 'Undo (Ctrl+Z)')"
					data-test="annotation-undo"
					@click="emit('undo')">
					<template #icon><Undo :size="20" /></template>
				</NcButton>

				<NcButton
					variant="secondary"
					:disabled="!canRedo"
					:title="t('sheetmusic', 'Redo (Ctrl+Y)')"
					:aria-label="t('sheetmusic', 'Redo (Ctrl+Y)')"
					data-test="annotation-redo"
					@click="emit('redo')">
					<template #icon><Redo :size="20" /></template>
				</NcButton>

				<NcButton
					v-if="selectedAnnotation"
					variant="secondary"
					:title="t('sheetmusic', 'Delete selected annotation (Del)')"
					:aria-label="t('sheetmusic', 'Delete selected annotation (Del)')"
					data-test="annotation-delete-selected"
					@click="emit('delete-selected')">
					<template #icon><DeleteOutline :size="20" /></template>
				</NcButton>

				<NcButton
					variant="secondary"
					:title="t('sheetmusic', 'Clear annotations on page {page}', { page: currentPage })"
					:aria-label="t('sheetmusic', 'Clear annotations on page {page}', { page: currentPage })"
					data-test="annotation-clear-page"
					@click="emit('clear-page')">
					<template #icon><DeleteSweepOutline :size="20" /></template>
				</NcButton>

				<NcButton
					variant="primary"
					:title="t('sheetmusic', 'Done')"
					:aria-label="t('sheetmusic', 'Done')"
					data-test="annotation-done"
					@click="emit('done')">
					<template #icon><Check :size="20" /></template>
				</NcButton>
			</div>
		</div>
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

.popoverWrap {
	position: relative;
	display: inline-flex;
}

.dropdownMenu {
	position: absolute;
	top: calc(100% + 4px);
	left: 0;
	z-index: 200;
	min-width: 170px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-large, 8px);
	box-shadow: 0 6px 20px rgba(0, 0, 0, 0.18);
	padding: 4px;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.menuItem {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 10px;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	border-radius: 4px;
	font-size: 0.85rem;
	text-align: left;
	cursor: pointer;
}

.menuItem:hover {
	background: var(--color-background-hover);
}

.menuItem.active {
	background: var(--color-primary-light, #e6f4fa);
	color: var(--color-primary-element, #0082c9);
	font-weight: 600;
}

.shapeGlyph {
	width: 20px;
	text-align: center;
	font-weight: 600;
}

.savingBadge {
	font-size: 0.78rem;
	color: var(--color-text-maxcontrast);
	margin-right: 4px;
}
</style>
