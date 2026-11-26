<script setup lang="ts">
import type { EditorTool, EditorToolStyle } from '../../composables/useAnnotationEditor'
import type { LineStyle, SheetAnnotation, TextAlign } from '../../types/annotation'
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import FormatAlignCenter from 'vue-material-design-icons/FormatAlignCenter.vue'
import FormatAlignLeft from 'vue-material-design-icons/FormatAlignLeft.vue'
import FormatAlignRight from 'vue-material-design-icons/FormatAlignRight.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import ViewportPopover from '../ViewportPopover.vue'
import SongAnnotationColorPicker, { type ColorSwatch } from './SongAnnotationColorPicker.vue'
import SongAnnotationLineStylePicker from './SongAnnotationLineStylePicker.vue'
import SongAnnotationLogSelect from './SongAnnotationLogSelect.vue'
import { LINK_LABEL_PRESETS, type LinkMarkerStyle } from '../../utils/annotationLinks'

const props = withDefaults(
	defineProps<{
		activeTool: EditorTool
		toolStyle: EditorToolStyle
		selectedAnnotation?: SheetAnnotation | null
		selectedAnnotations?: SheetAnnotation[]
		isShapeActive: boolean
	}>(),
	{
		selectedAnnotation: null,
		selectedAnnotations: () => [],
	},
)

const emit = defineEmits<{
	'update:toolStyle': [style: EditorToolStyle]
	'update-selected-style': [patch: Partial<SheetAnnotation['style']>]
	'update-selected-data': [patch: Partial<SheetAnnotation['data']>]
	'pick-selected-link-target': []
	'open-selected-link-picker': []
}>()

const COLOR_SWATCHES: ColorSwatch[] = [
	{ color: '#000000', label: 'Black' },
	{ color: '#e53935', label: 'Red' },
	{ color: '#1e88e5', label: 'Blue' },
	{ color: '#43a047', label: 'Green' },
	{ color: '#fb8c00', label: 'Orange' },
	{ color: '#8e24aa', label: 'Purple' },
	{ color: '#6d4c41', label: 'Brown' },
	{ color: '#546e7a', label: 'Slate' },
]

const HIGHLIGHT_SWATCHES: ColorSwatch[] = [
	{ color: '#ffeb3b', label: 'Yellow' },
	{ color: '#76ff03', label: 'Lime' },
	{ color: '#40c4ff', label: 'Cyan' },
	{ color: '#ff80ab', label: 'Pink' },
	{ color: '#ffd180', label: 'Peach' },
	{ color: '#b388ff', label: 'Lavender' },
	{ color: '#80cbc4', label: 'Teal' },
	{ color: '#ff8a65', label: 'Coral' },
]

const effectiveSelection = computed<SheetAnnotation[]>(() => {
	if (props.selectedAnnotations && props.selectedAnnotations.length > 0) {
		return props.selectedAnnotations
	}
	return props.selectedAnnotation ? [props.selectedAnnotation] : []
})

const hasSelection = computed(() => effectiveSelection.value.length > 0)
const singleSelected = computed(() => (effectiveSelection.value.length === 1 ? effectiveSelection.value[0] : null))
const primarySelected = computed(() => effectiveSelection.value[0] ?? null)

const isHighlightContext = computed(() =>
	hasSelection.value
		? effectiveSelection.value.every((a) => a.type === 'highlight')
		: props.activeTool === 'highlight',
)
const isStampContext = computed(() =>
	hasSelection.value
		? effectiveSelection.value.some((a) => a.type === 'stamp')
		: props.activeTool === 'stamp' || props.activeTool === 'handwriting',
)

const hasStrokeSelected = computed(() =>
	effectiveSelection.value.some((a) => a.type === 'drawing' || a.type === 'highlight' || a.type === 'shape'),
)
const hasLineStyleSelected = computed(() =>
	effectiveSelection.value.some((a) => a.type === 'drawing' || a.type === 'shape'),
)
const hasFontSizeSelected = computed(() =>
	effectiveSelection.value.some((a) => a.type === 'text' || a.type === 'stamp' || a.type === 'link'),
)
const hasTextSelected = computed(() =>
	effectiveSelection.value.some((a) => a.type === 'text'),
)

const showStrokeWidth = computed(() =>
	props.activeTool === 'drawing' || props.activeTool === 'highlight' || props.isShapeActive || hasStrokeSelected.value,
)

const showLineStyle = computed(() =>
	props.activeTool === 'drawing' || props.isShapeActive || hasLineStyleSelected.value,
)

const showFontSize = computed(() =>
	props.activeTool === 'text' || props.activeTool === 'stamp' || props.activeTool === 'handwriting' || props.activeTool === 'link'
	|| hasFontSizeSelected.value,
)

const showTextAlign = computed(() => props.activeTool === 'text' || hasTextSelected.value)
const showSelectedText = computed(() => singleSelected.value?.type === 'text')
const isLinkEditOpen = ref(false)

const activeColor = computed(() => {
	const sel = primarySelected.value
	if (sel) {
		return sel.style?.stroke || sel.style?.color || props.toolStyle.color
	}
	return isHighlightContext.value ? props.toolStyle.highlightColor : props.toolStyle.color
})

const activeOpacity = computed(() => {
	const fallback = isHighlightContext.value ? props.toolStyle.highlightOpacity : props.toolStyle.opacity
	return primarySelected.value?.style?.opacity ?? fallback
})

const currentSwatches = computed(() => (isHighlightContext.value ? HIGHLIGHT_SWATCHES : COLOR_SWATCHES))
const strokeWidthPresets = computed(() => (isHighlightContext.value ? [10, 18, 28, 38] : [1.5, 2.5, 4, 6.5]))

const currentStrokeWidth = computed(() => {
	const strokeItem = effectiveSelection.value.find((a) => a.type === 'drawing' || a.type === 'highlight' || a.type === 'shape')
	return strokeItem?.style?.strokeWidth ?? (isHighlightContext.value ? props.toolStyle.highlightWidth : props.toolStyle.strokeWidth)
})

const currentLineStyle = computed<LineStyle>(() => {
	const lineItem = effectiveSelection.value.find((a) => a.type === 'drawing' || a.type === 'shape')
	return lineItem?.style?.lineStyle ?? props.toolStyle.lineStyle
})

const fontSizePresets = computed(() => (isStampContext.value ? [26, 36, 48, 64] : [16, 22, 30, 42]))

const currentFontSize = computed(() => {
	const fontItem = effectiveSelection.value.find((a) => a.type === 'text' || a.type === 'stamp' || a.type === 'link')
	return fontItem?.style?.fontSize ?? (isStampContext.value ? props.toolStyle.stampSize : props.toolStyle.fontSize)
})

function isHeightAdjustableType(shapeType?: string | null): boolean {
	return shapeType === 'crescendo' || shapeType === 'decrescendo' || shapeType === 'slur' || shapeType === 'bracket' || shapeType === 'bracket-up'
}

const firstHeightAdjustableSelected = computed(() =>
	effectiveSelection.value.find((a) => a.type === 'shape' && isHeightAdjustableType(a.data?.shapeType)) ?? null,
)

const isHeightAdjustableShape = computed(() => {
	if (firstHeightAdjustableSelected.value) return true
	return isHeightAdjustableType(props.activeTool)
})

const currentShapeHeight = computed(() => {
	const sel = firstHeightAdjustableSelected.value
	if (sel?.type === 'shape') {
		const raw = sel.data?.shapeType === 'slur' ? sel.data?.curvature : sel.data?.aperture
		return Math.abs(raw ?? props.toolStyle.shapeHeight ?? 18)
	}
	return props.toolStyle.shapeHeight ?? 18
})

const shapeHeightPresets = [10, 18, 28, 45, 65]

function patchToolStyle(patch: Partial<EditorToolStyle>) {
	emit('update:toolStyle', { ...props.toolStyle, ...patch })
}

function pickColor(color: string) {
	if (singleSelected.value) {
		const isFillText = singleSelected.value.type === 'text' || singleSelected.value.type === 'stamp'
		emit('update-selected-style', isFillText ? { color } : { stroke: color })
		return
	}
	if (hasSelection.value) {
		emit('update-selected-style', { color })
		return
	}
	patchToolStyle(isHighlightContext.value ? { highlightColor: color } : { color })
}

function updateOpacity(opacity: number) {
	if (hasSelection.value) {
		emit('update-selected-style', { opacity })
		return
	}
	patchToolStyle(isHighlightContext.value ? { highlightOpacity: opacity } : { opacity })
}

function updateStrokeWidth(val: number) {
	if (hasStrokeSelected.value) {
		emit('update-selected-style', { strokeWidth: val })
		return
	}
	patchToolStyle(isHighlightContext.value ? { highlightWidth: val } : { strokeWidth: val })
}

function updateLineStyle(lineStyle: LineStyle) {
	if (hasLineStyleSelected.value) {
		emit('update-selected-style', { lineStyle })
		return
	}
	patchToolStyle({ lineStyle })
}

function updateFontSize(fontSize: number) {
	if (hasFontSizeSelected.value) {
		emit('update-selected-style', { fontSize })
		return
	}
	patchToolStyle(isStampContext.value ? { stampSize: fontSize } : { fontSize })
}

function updateTextAlign(textAlign: TextAlign) {
	if (hasTextSelected.value) {
		emit('update-selected-style', { textAlign })
		return
	}
	patchToolStyle({ textAlign })
}

const selectedTextValue = computed(() => {
	const sel = singleSelected.value
	if (!sel) return ''
	if (sel.type === 'link') return sel.data?.label ?? ''
	if (sel.type === 'text') return sel.data?.text ?? ''
	return ''
})

function commitSelectedText(rawValue: string) {
	const sel = singleSelected.value
	if (!sel) return
	const trimmed = rawValue.trim()
	if (!trimmed) return
	if (sel.type === 'link' && trimmed !== (sel.data?.label ?? '')) {
		emit('update-selected-data', { label: trimmed })
	} else if (sel.type === 'text' && trimmed !== (sel.data?.text ?? '')) {
		emit('update-selected-data', { text: trimmed })
	}
}

const isSingleLinkSelected = computed(() => singleSelected.value?.type === 'link')

const selectedLinkPage = computed(() => {
	const sel = singleSelected.value
	if (sel?.type !== 'link') return 1
	return (sel.data?.targetPage ?? 0) + 1
})

const hasSelectedLinkSpot = computed(() => {
	const sel = singleSelected.value
	return sel?.type === 'link'
		&& typeof sel.data?.targetX === 'number'
		&& typeof sel.data?.targetY === 'number'
})

function commitSelectedLinkPage(rawValue: string) {
	const sel = singleSelected.value
	if (sel?.type !== 'link') return
	const parsed = Number.parseInt(rawValue, 10)
	if (!Number.isFinite(parsed) || parsed < 1) return
	const nextZeroIndexed = parsed - 1
	if (nextZeroIndexed !== (sel.data?.targetPage ?? 0)) {
		emit('update-selected-data', { targetPage: nextZeroIndexed, targetX: undefined, targetY: undefined })
	}
}

const selectedLinkStyle = computed<LinkMarkerStyle>(() =>
	singleSelected.value?.data?.linkStyle === 'circle' ? 'circle' : 'box',
)

function commitSelectedLinkStyle(style: LinkMarkerStyle) {
	const sel = singleSelected.value
	if (sel?.type !== 'link') return
	emit('update-selected-data', { linkStyle: style })
}

function startPickSelectedLinkTarget() {
	isLinkEditOpen.value = false
	emit('pick-selected-link-target')
}

function openSelectedLinkPicker() {
	isLinkEditOpen.value = false
	emit('open-selected-link-picker')
}

function updateShapeHeight(height: number) {
	const sel = firstHeightAdjustableSelected.value
	if (sel?.type === 'shape') {
		if (sel.data?.shapeType === 'slur') {
			const sign = (sel.data?.curvature ?? -1) < 0 ? -1 : 1
			emit('update-selected-data', { curvature: sign * height })
		} else if (sel.data?.shapeType === 'bracket') {
			const sign = (sel.data?.aperture ?? 1) < 0 ? -1 : 1
			emit('update-selected-data', { aperture: sign * height })
		} else {
			emit('update-selected-data', { aperture: height })
		}
		return
	}
	patchToolStyle({ shapeHeight: height })
}
</script>

<template>
	<div :class="$style.styleRow">
		<SongAnnotationColorPicker
			:model-value="activeColor"
			:opacity="activeOpacity"
			:swatches="currentSwatches"
			@update:model-value="pickColor"
			@update:opacity="updateOpacity" />

		<!-- Stroke Width (for Pen, Highlighter, Shapes) -->
		<div v-if="showStrokeWidth" :class="$style.styleGroup">
			<SongAnnotationLogSelect
				:model-value="currentStrokeWidth"
				:presets="strokeWidthPresets"
				:min-value="1"
				:max-value="64"
				:allow-half-step="true"
				:input-label="t('sheetmusic', 'Width')"
				:slider-label="t('sheetmusic', 'Custom width')"
				select-test-id="annotation-width-select"
				slider-test-id="annotation-width-slider"
				@update:model-value="updateStrokeWidth" />
		</div>

		<!-- Line Style (for Pen & Shapes) -->
		<div v-if="showLineStyle" :class="$style.styleGroup">
			<SongAnnotationLineStylePicker
				:model-value="currentLineStyle"
				@update:model-value="updateLineStyle" />
		</div>

		<!-- Shape Height (for Hairpins, Slurs, Brackets) -->
		<div
			v-if="isHeightAdjustableShape"
			:class="$style.styleGroup"
			data-test="shape-height-control">
			<SongAnnotationLogSelect
				:model-value="Math.round(currentShapeHeight)"
				:presets="shapeHeightPresets"
				:min-value="4"
				:max-value="128"
				:input-label="t('sheetmusic', 'Height')"
				:slider-label="t('sheetmusic', 'Custom height')"
				select-test-id="shape-height-select"
				slider-test-id="shape-height-slider"
				@update:model-value="updateShapeHeight" />
		</div>

		<!-- Font / Stamp / Link Size -->
		<div v-if="showFontSize" :class="$style.styleGroup">
			<SongAnnotationLogSelect
				:model-value="currentFontSize"
				:presets="fontSizePresets"
				:min-value="8"
				:max-value="128"
				:input-label="t('sheetmusic', 'Size')"
				:slider-label="t('sheetmusic', 'Custom size')"
				select-test-id="annotation-size-select"
				slider-test-id="annotation-size-slider"
				@update:model-value="updateFontSize" />
		</div>

		<!-- Text Align -->
		<div v-if="showTextAlign" :class="$style.styleGroup">
			<span :class="$style.styleLabel">{{ t('sheetmusic', 'Align') }}</span>
			<button
				type="button"
				:class="[$style.chipBtn, (selectedAnnotation?.style?.textAlign ?? toolStyle.textAlign) === 'left' && $style.active]"
				@click="updateTextAlign('left')">
				<FormatAlignLeft :size="15" />
			</button>
			<button
				type="button"
				:class="[$style.chipBtn, (selectedAnnotation?.style?.textAlign ?? toolStyle.textAlign) === 'center' && $style.active]"
				@click="updateTextAlign('center')">
				<FormatAlignCenter :size="15" />
			</button>
			<button
				type="button"
				:class="[$style.chipBtn, (selectedAnnotation?.style?.textAlign ?? toolStyle.textAlign) === 'right' && $style.active]"
				@click="updateTextAlign('right')">
				<FormatAlignRight :size="15" />
			</button>
		</div>

		<!-- Selected Text -->
		<div v-if="showSelectedText && singleSelected" :class="$style.styleGroup">
			<span :class="$style.styleLabel">{{ t('sheetmusic', 'Text') }}</span>
			<input
				type="text"
				:value="selectedTextValue"
				:placeholder="t('sheetmusic', 'Annotation text...')"
				:aria-label="t('sheetmusic', 'Annotation text')"
				:class="$style.textEditInput"
				data-test="selected-annotation-text-input"
				@change="commitSelectedText(($event.target as HTMLInputElement).value)"
				@keydown.enter.prevent="($event.target as HTMLInputElement).blur()" />
		</div>

		<!-- Selected Link Editor Popover -->
		<ViewportPopover
			v-if="isSingleLinkSelected"
			v-model:open="isLinkEditOpen"
			pane-test-id="selected-link-edit-popover"
			:pane-class="$style.linkEditPopover"
			:gap="6">
			<template #trigger="{ toggle }">
				<button
					type="button"
					:class="[$style.chipBtn, isLinkEditOpen && $style.active]"
					:title="t('sheetmusic', 'Edit link target and label')"
					:aria-label="t('sheetmusic', 'Edit link')"
					data-test="selected-link-edit-button"
					@click="toggle">
					<LinkVariant :size="14" />
					<span>{{ t('sheetmusic', 'p. {page}', { page: selectedLinkPage }) }}</span>
				</button>
			</template>

			<div :class="$style.linkPopoverBody">
				<label :class="$style.linkPopoverRow">
					<span>{{ t('sheetmusic', 'Target page') }}</span>
					<input
						type="number"
						min="1"
						:value="selectedLinkPage"
						:aria-label="t('sheetmusic', 'Link target page')"
						:class="$style.popoverInput"
						data-test="selected-link-page-input"
						@change="commitSelectedLinkPage(($event.target as HTMLInputElement).value)">
				</label>
				<label :class="$style.linkPopoverRow">
					<span>{{ t('sheetmusic', 'Button label') }}</span>
					<input
						type="text"
						:value="selectedTextValue"
						:placeholder="t('sheetmusic', 'e.g. To Coda')"
						:aria-label="t('sheetmusic', 'Link label')"
						:class="$style.popoverInput"
						data-test="selected-annotation-text-input"
						@change="commitSelectedText(($event.target as HTMLInputElement).value)">
				</label>
				<div :class="$style.linkPresetRow">
					<button
						v-for="preset in LINK_LABEL_PRESETS"
						:key="preset"
						type="button"
						:class="[$style.miniChip, selectedTextValue === preset && $style.miniChipActive]"
						@click="commitSelectedText(preset)">
						{{ preset }}
					</button>
				</div>
				<div :class="$style.linkPopoverRow">
					<span>{{ t('sheetmusic', 'Style') }}</span>
					<div :class="$style.linkPresetRow">
						<button
							type="button"
							:class="[$style.miniChip, selectedLinkStyle === 'box' && $style.miniChipActive]"
							data-test="selected-link-style-box"
							@click="commitSelectedLinkStyle('box')">
							{{ t('sheetmusic', 'Box') }}
						</button>
						<button
							type="button"
							:class="[$style.miniChip, selectedLinkStyle === 'circle' && $style.miniChipActive]"
							data-test="selected-link-style-circle"
							@click="commitSelectedLinkStyle('circle')">
							{{ t('sheetmusic', 'Circle') }}
						</button>
					</div>
				</div>
				<div :class="$style.pickBtnRow">
					<button
						type="button"
						:class="$style.pickSpotBtn"
						data-test="selected-link-pick-target"
						@click="startPickSelectedLinkTarget">
						{{ hasSelectedLinkSpot
							? t('sheetmusic', 'Repick…')
							: t('sheetmusic', 'Pick spot…') }}
					</button>
					<button
						type="button"
						:class="$style.pickSpotBtn"
						data-test="selected-link-open-picker"
						@click="openSelectedLinkPicker">
						{{ t('sheetmusic', 'Side-by-side…') }}
					</button>
				</div>
			</div>
		</ViewportPopover>
	</div>
</template>

<style module>
.styleRow {
	display: flex;
	flex-direction: row;
	align-items: center;
	gap: 6px;
	flex-wrap: nowrap;
	position: relative;
	z-index: 10;
	min-width: 0;
}

.styleGroup {
	display: inline-flex;
	flex-direction: row;
	align-items: center;
	gap: 5px;
	flex-shrink: 0;
}

.styleLabel {
	font-size: 0.78rem;
	color: var(--color-text-maxcontrast);
	margin-right: 2px;
}

button[type='button'].chipBtn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 3px;
	width: auto;
	min-width: 32px;
	min-height: 32px;
	margin: 0;
	padding: 2px 8px;
	font-size: 0.78rem;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.chipBtn:hover {
	background: var(--color-background-hover);
}

.chipBtn.active {
	background: var(--color-primary-light, #e6f4fa);
	border-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element, #0082c9);
	font-weight: 600;
}

.textEditInput {
	width: 140px;
	height: 28px;
	padding: 0 8px;
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.8rem;
}

.linkEditPopover {
	padding: 10px;
	width: 240px;
}

.linkPopoverBody {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.linkPopoverRow {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	font-size: 0.8rem;
}

.popoverInput {
	width: 120px;
	height: 28px;
	padding: 2px 6px;
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.8rem;
	box-sizing: border-box;
}

.linkPresetRow {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

button[type='button'].miniChip {
	margin: 0;
	padding: 2px 7px;
	min-height: 24px;
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: 999px;
	background: var(--color-background-hover);
	color: var(--color-main-text);
	font-size: 0.72rem;
	cursor: pointer;
}

button[type='button'].miniChipActive {
	border-color: var(--color-primary-element, #0082c9);
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #ffffff);
	font-weight: 600;
}

.pickBtnRow {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 5px;
}

button[type='button'].pickSpotBtn {
	width: 100%;
	margin: 0;
	padding: 5px 8px;
	border: 1px dashed var(--color-primary-element, #0082c9);
	border-radius: 6px;
	background: var(--color-primary-light, #e6f4fa);
	color: var(--color-main-text);
	font-size: 0.78rem;
	font-weight: 600;
	cursor: pointer;
}

button[type='button'].pickSpotBtn:hover {
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #ffffff);
}
</style>
