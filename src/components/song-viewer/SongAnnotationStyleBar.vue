<script setup lang="ts">
import type { EditorTool, EditorToolStyle } from '../../composables/useAnnotationEditor'
import type { LineStyle, SheetAnnotation, TextAlign } from '../../types/annotation'
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import FormatAlignCenter from 'vue-material-design-icons/FormatAlignCenter.vue'
import FormatAlignLeft from 'vue-material-design-icons/FormatAlignLeft.vue'
import FormatAlignRight from 'vue-material-design-icons/FormatAlignRight.vue'
import SongAnnotationColorPicker, { type ColorSwatch } from './SongAnnotationColorPicker.vue'
import SongAnnotationLogSelect from './SongAnnotationLogSelect.vue'
import ViewportPopover from '../ViewportPopover.vue'

const props = withDefaults(
	defineProps<{
		activeTool: EditorTool
		toolStyle: EditorToolStyle
		selectedAnnotation?: SheetAnnotation | null
		isShapeActive: boolean
	}>(),
	{
		selectedAnnotation: null,
	},
)

const emit = defineEmits<{
	'update:toolStyle': [style: EditorToolStyle]
	'update-selected-style': [patch: Partial<SheetAnnotation['style']>]
	'update-selected-data': [patch: Partial<SheetAnnotation['data']>]
}>()

const showLineStyleMenu = ref(false)

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

const isHighlightContext = computed(() =>
	props.selectedAnnotation ? props.selectedAnnotation.type === 'highlight' : props.activeTool === 'highlight',
)
const isStampContext = computed(() =>
	props.selectedAnnotation ? props.selectedAnnotation.type === 'stamp' : props.activeTool === 'stamp',
)

const showStrokeWidth = computed(() => {
	const t = props.selectedAnnotation?.type
	return props.activeTool === 'drawing' || props.activeTool === 'highlight' || props.isShapeActive
		|| t === 'drawing' || t === 'highlight' || t === 'shape'
})

const showLineStyle = computed(() => {
	const t = props.selectedAnnotation?.type
	return props.activeTool === 'drawing' || props.isShapeActive || t === 'drawing' || t === 'shape'
})

const showFontSize = computed(() => {
	const t = props.selectedAnnotation?.type
	return props.activeTool === 'text' || props.activeTool === 'stamp' || props.activeTool === 'link'
		|| t === 'text' || t === 'stamp' || t === 'link'
})

const showTextAlign = computed(() => props.activeTool === 'text' || props.selectedAnnotation?.type === 'text')
const showSelectedText = computed(() => props.selectedAnnotation?.type === 'text' || props.selectedAnnotation?.type === 'link')

const activeColor = computed(() => {
	if (props.selectedAnnotation) {
		return props.selectedAnnotation.style?.stroke || props.selectedAnnotation.style?.color || props.toolStyle.color
	}
	return isHighlightContext.value ? props.toolStyle.highlightColor : props.toolStyle.color
})

const activeOpacity = computed(() => {
	const fallback = isHighlightContext.value ? props.toolStyle.highlightOpacity : props.toolStyle.opacity
	return props.selectedAnnotation?.style?.opacity ?? fallback
})

const currentSwatches = computed(() => (isHighlightContext.value ? HIGHLIGHT_SWATCHES : COLOR_SWATCHES))
const strokeWidthPresets = computed(() => (isHighlightContext.value ? [10, 18, 28, 38] : [1.5, 2.5, 4, 6.5]))

const currentStrokeWidth = computed(() =>
	props.selectedAnnotation?.style?.strokeWidth ?? (isHighlightContext.value ? props.toolStyle.highlightWidth : props.toolStyle.strokeWidth),
)

const lineStyleOptions = computed(() => [
	{ value: 'solid' as LineStyle, label: t('sheetmusic', 'Solid') },
	{ value: 'dashed' as LineStyle, label: t('sheetmusic', 'Dashed') },
	{ value: 'dotted' as LineStyle, label: t('sheetmusic', 'Dotted') },
])

function lineDashArray(style: LineStyle): string | undefined {
	return style === 'dashed' ? '5, 3' : style === 'dotted' ? '1, 4' : undefined
}

const currentLineStyleOption = computed(() => {
	const val = props.selectedAnnotation?.style?.lineStyle ?? props.toolStyle.lineStyle
	return lineStyleOptions.value.find((o) => o.value === val) ?? lineStyleOptions.value[0]
})

const fontSizePresets = computed(() => (isStampContext.value ? [26, 36, 48, 64] : [16, 22, 30, 42]))

const currentFontSize = computed(() =>
	props.selectedAnnotation?.style?.fontSize ?? (isStampContext.value ? props.toolStyle.stampSize : props.toolStyle.fontSize),
)

const isHeightAdjustableShape = computed(() => {
	const selShape = props.selectedAnnotation?.type === 'shape' ? props.selectedAnnotation.data?.shapeType : null
	const active = selShape ?? props.activeTool
	return active === 'crescendo' || active === 'decrescendo' || active === 'slur' || active === 'bracket'
})

const currentShapeHeight = computed(() => {
	const sel = props.selectedAnnotation
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
	if (props.selectedAnnotation) {
		const isFillText = props.selectedAnnotation.type === 'text' || props.selectedAnnotation.type === 'stamp'
		emit('update-selected-style', isFillText ? { color } : { stroke: color })
		return
	}
	patchToolStyle(isHighlightContext.value ? { highlightColor: color } : { color })
}

function updateOpacity(opacity: number) {
	if (props.selectedAnnotation) {
		emit('update-selected-style', { opacity })
		return
	}
	patchToolStyle(isHighlightContext.value ? { highlightOpacity: opacity } : { opacity })
}

function updateStrokeWidth(val: number) {
	if (props.selectedAnnotation && props.selectedAnnotation.type !== 'text' && props.selectedAnnotation.type !== 'stamp') {
		emit('update-selected-style', { strokeWidth: val })
		return
	}
	patchToolStyle(isHighlightContext.value ? { highlightWidth: val } : { strokeWidth: val })
}

function updateLineStyle(lineStyle: LineStyle) {
	showLineStyleMenu.value = false
	if (props.selectedAnnotation && (props.selectedAnnotation.type === 'drawing' || props.selectedAnnotation.type === 'shape')) {
		emit('update-selected-style', { lineStyle })
		return
	}
	patchToolStyle({ lineStyle })
}

function updateFontSize(fontSize: number) {
	const t = props.selectedAnnotation?.type
	if (t === 'text' || t === 'stamp' || t === 'link') {
		emit('update-selected-style', { fontSize })
		return
	}
	patchToolStyle(isStampContext.value ? { stampSize: fontSize } : { fontSize })
}

function updateTextAlign(textAlign: TextAlign) {
	if (props.selectedAnnotation?.type === 'text') {
		emit('update-selected-style', { textAlign })
		return
	}
	patchToolStyle({ textAlign })
}

const selectedTextValue = computed(() => {
	const sel = props.selectedAnnotation
	if (!sel) return ''
	if (sel.type === 'link') return sel.data?.label ?? ''
	if (sel.type === 'text') return sel.data?.text ?? ''
	return ''
})

function commitSelectedText(rawValue: string) {
	const sel = props.selectedAnnotation
	if (!sel) return
	const trimmed = rawValue.trim()
	if (!trimmed) return
	if (sel.type === 'link' && trimmed !== (sel.data?.label ?? '')) {
		emit('update-selected-data', { label: trimmed })
	} else if (sel.type === 'text' && trimmed !== (sel.data?.text ?? '')) {
		emit('update-selected-data', { text: trimmed })
	}
}

function updateShapeHeight(height: number) {
	const sel = props.selectedAnnotation
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
			<ViewportPopover
				v-model:open="showLineStyleMenu"
				:pane-class="$style.lineMenuPane">
				<template #trigger="{ toggle }">
					<button
						type="button"
						:class="[$style.selectTriggerBtn, showLineStyleMenu && $style.selectTriggerOpen]"
						:title="t('sheetmusic', 'Line')"
						:aria-label="`${t('sheetmusic', 'Line')}: ${currentLineStyleOption.label}`"
						:aria-expanded="showLineStyleMenu"
						data-test="annotation-line-style-select"
						@click="toggle">
						<span :class="$style.lineOption">
							<svg width="22" height="10" viewBox="0 0 22 10" aria-hidden="true">
								<line
									x1="1"
									y1="5"
									x2="21"
									y2="5"
									stroke="currentColor"
									stroke-width="2"
									stroke-linecap="round"
									:stroke-dasharray="lineDashArray(currentLineStyleOption.value)" />
							</svg>
							<span>{{ currentLineStyleOption.label }}</span>
						</span>
						<ChevronDown :size="16" :class="$style.chevron" />
					</button>
				</template>
				<button
					v-for="opt in lineStyleOptions"
					:key="opt.value"
					type="button"
					:class="[$style.lineMenuItem, currentLineStyleOption.value === opt.value && $style.lineMenuItemActive]"
					:data-test="`annotation-line-style-option-${opt.value}`"
					@click="updateLineStyle(opt.value)">
					<span :class="$style.lineOption">
						<svg width="22" height="10" viewBox="0 0 22 10" aria-hidden="true">
							<line
								x1="1"
								y1="5"
								x2="21"
								y2="5"
								stroke="currentColor"
								stroke-width="2"
								stroke-linecap="round"
								:stroke-dasharray="lineDashArray(opt.value)" />
						</svg>
						<span>{{ opt.label }}</span>
					</span>
				</button>
			</ViewportPopover>
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

		<!-- Selected Text / Link Label -->
		<div v-if="showSelectedText" :class="$style.styleGroup">
			<span :class="$style.styleLabel">
				{{ selectedAnnotation.type === 'link' ? t('sheetmusic', 'Label') : t('sheetmusic', 'Text') }}
			</span>
			<input
				type="text"
				:value="selectedTextValue"
				:placeholder="selectedAnnotation.type === 'link' ? t('sheetmusic', 'Link label...') : t('sheetmusic', 'Annotation text...')"
				:aria-label="selectedAnnotation.type === 'link' ? t('sheetmusic', 'Link label') : t('sheetmusic', 'Annotation text')"
				:class="$style.textEditInput"
				data-test="selected-annotation-text-input"
				@change="commitSelectedText(($event.target as HTMLInputElement).value)"
				@keydown.enter.prevent="($event.target as HTMLInputElement).blur()" />
		</div>
	</div>
</template>

<style module>
.styleRow {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	position: relative;
	z-index: 10;
}

.styleGroup {
	display: flex;
	align-items: center;
	gap: 5px;
}

.styleLabel {
	font-size: 0.78rem;
	color: var(--color-text-maxcontrast);
	margin-right: 2px;
}

.chipBtn {
	display: inline-flex;
	align-items: center;
	gap: 3px;
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

.selectTriggerBtn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	min-height: var(--default-clickable-area, 44px);
	padding: 4px 8px 4px 10px;
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.82rem;
	cursor: pointer;
	white-space: nowrap;
}

.selectTriggerBtn:hover {
	background: var(--color-background-hover);
}

.selectTriggerOpen {
	border-color: var(--color-primary-element, #0082c9);
}

.chevron {
	display: inline-flex;
	color: var(--color-text-maxcontrast);
}

.lineMenuPane {
	min-width: 132px;
	padding: 4px;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.lineMenuItem {
	display: flex;
	align-items: center;
	width: 100%;
	padding: 6px 10px;
	border: none;
	border-radius: 4px;
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.85rem;
	text-align: left;
	cursor: pointer;
}

.lineMenuItem:hover {
	background: var(--color-background-hover);
}

.lineMenuItemActive {
	background: var(--color-primary-light, #e6f4fa);
	color: var(--color-primary-element, #0082c9);
	font-weight: 600;
}

.lineOption {
	display: inline-flex;
	align-items: center;
	gap: 6px;
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
</style>
