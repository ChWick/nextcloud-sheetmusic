<script setup lang="ts">
import type { EditorTool, EditorToolStyle } from '../../composables/useAnnotationEditor'
import type { LineStyle, SheetAnnotation, TextAlign } from '../../types/annotation'
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import FormatAlignCenter from 'vue-material-design-icons/FormatAlignCenter.vue'
import FormatAlignLeft from 'vue-material-design-icons/FormatAlignLeft.vue'
import FormatAlignRight from 'vue-material-design-icons/FormatAlignRight.vue'
import SongAnnotationColorPicker, { type ColorSwatch } from './SongAnnotationColorPicker.vue'
import { clampNcSelectDropdown } from '../../composables/useViewportPopoverClamp'

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

const isWidthSliderDragging = ref(false)
const widthSelectRef = ref<InstanceType<typeof NcSelect> | null>(null)
const lineStyleSelectRef = ref<InstanceType<typeof NcSelect> | null>(null)
const heightSelectRef = ref<InstanceType<typeof NcSelect> | null>(null)
const sizeSelectRef = ref<InstanceType<typeof NcSelect> | null>(null)

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

const activeColor = computed(() => {
	if (props.selectedAnnotation) {
		return props.selectedAnnotation.style?.stroke || props.selectedAnnotation.style?.color || props.toolStyle.color
	}
	return props.activeTool === 'highlight' ? props.toolStyle.highlightColor : props.toolStyle.color
})

const activeOpacity = computed(() => {
	if (props.selectedAnnotation) {
		return props.selectedAnnotation.style?.opacity ?? (props.selectedAnnotation.type === 'highlight' ? props.toolStyle.highlightOpacity : props.toolStyle.opacity)
	}
	return props.activeTool === 'highlight' ? props.toolStyle.highlightOpacity : props.toolStyle.opacity
})

const currentSwatches = computed(() =>
	props.activeTool === 'highlight' || props.selectedAnnotation?.type === 'highlight'
		? HIGHLIGHT_SWATCHES
		: COLOR_SWATCHES,
)

const strokeWidthOptions = computed(() => {
	const isHighlight = props.activeTool === 'highlight' || props.selectedAnnotation?.type === 'highlight'
	const presets = isHighlight ? [10, 18, 28, 38] : [1.5, 2.5, 4, 6.5]
	return presets.map((w) => ({ value: w, label: String(w) }))
})

const currentStrokeWidthOption = computed(() => {
	const isHighlight = props.activeTool === 'highlight' || props.selectedAnnotation?.type === 'highlight'
	const val = props.selectedAnnotation?.style?.strokeWidth ?? (isHighlight ? props.toolStyle.highlightWidth : props.toolStyle.strokeWidth)
	return strokeWidthOptions.value.find((o) => o.value === val) ?? { value: val, label: String(val) }
})

const currentWidthSliderPos = computed(() => {
	const w = Math.max(1, Math.min(64, Number(currentStrokeWidthOption.value.value) || 1))
	return Math.log2(w)
})

const lineStyleOptions = computed(() => [
	{ value: 'solid' as LineStyle, label: t('sheetmusic', 'Solid') },
	{ value: 'dashed' as LineStyle, label: t('sheetmusic', 'Dashed') },
	{ value: 'dotted' as LineStyle, label: t('sheetmusic', 'Dotted') },
])

const currentLineStyleOption = computed(() => {
	const val = props.selectedAnnotation?.style?.lineStyle ?? props.toolStyle.lineStyle
	return lineStyleOptions.value.find((o) => o.value === val) ?? lineStyleOptions.value[0]
})

const fontSizeOptions = computed(() => {
	const isStamp = props.activeTool === 'stamp' || props.selectedAnnotation?.type === 'stamp'
	const presets = isStamp ? [26, 36, 48, 64] : [16, 22, 30, 42]
	const current = props.selectedAnnotation?.style?.fontSize ?? (isStamp ? props.toolStyle.stampSize : props.toolStyle.fontSize)
	const list = current && !presets.includes(current)
		? [...presets, current].sort((a, b) => a - b)
		: presets
	return list.map((sz) => ({ value: sz, label: String(sz) }))
})

const currentFontSizeOption = computed(() => {
	const isStamp = props.activeTool === 'stamp' || props.selectedAnnotation?.type === 'stamp'
	const val = props.selectedAnnotation?.style?.fontSize ?? (isStamp ? props.toolStyle.stampSize : props.toolStyle.fontSize)
	return fontSizeOptions.value.find((o) => o.value === val) ?? { value: val, label: String(val) }
})

const isHeightAdjustableShape = computed(() => {
	const selShape = props.selectedAnnotation?.type === 'shape' ? props.selectedAnnotation.data?.shapeType : null
	const active = selShape ?? props.activeTool
	return active === 'crescendo' || active === 'decrescendo' || active === 'slur' || active === 'bracket'
})

const currentShapeHeight = computed(() => {
	const sel = props.selectedAnnotation
	if (sel?.type === 'shape') {
		if (sel.data?.shapeType === 'slur') {
			return Math.abs(sel.data?.curvature ?? props.toolStyle.shapeHeight ?? 18)
		}
		return Math.abs(sel.data?.aperture ?? props.toolStyle.shapeHeight ?? 18)
	}
	return props.toolStyle.shapeHeight ?? 18
})

const shapeHeightOptions = computed(() => {
	const presets = [10, 18, 28, 45, 65]
	const current = Math.round(currentShapeHeight.value)
	const list = current && !presets.includes(current)
		? [...presets, current].sort((a, b) => a - b)
		: presets
	return list.map((h) => ({ value: h, label: String(h) }))
})

const currentShapeHeightOption = computed(() => {
	const val = Math.round(currentShapeHeight.value)
	return shapeHeightOptions.value.find((o) => o.value === val) ?? { value: val, label: String(val) }
})

function widthDropdownShouldOpen({ noDrop, open }: { noDrop: boolean; open: boolean }): boolean {
	return (open || isWidthSliderDragging.value) && !noDrop
}

function onWidthSliderStart() {
	isWidthSliderDragging.value = true
	const finish = () => {
		window.removeEventListener('pointerup', finish)
		window.removeEventListener('mouseup', finish)
		isWidthSliderDragging.value = false
		const input = widthSelectRef.value?.$el?.querySelector?.('input') as HTMLInputElement | null
		input?.focus()
	}
	window.addEventListener('pointerup', finish, { once: true })
	window.addEventListener('mouseup', finish, { once: true })
}

function onWidthSliderInput(pos: number) {
	const raw = Math.pow(2, Math.max(0, Math.min(6, pos)))
	const rounded = raw < 4 ? Math.round(raw * 2) / 2 : Math.round(raw)
	updateStrokeWidth(Math.max(1, Math.min(64, rounded)))
}

function pickColor(color: string) {
	if (props.selectedAnnotation) {
		if (props.selectedAnnotation.type === 'text' || props.selectedAnnotation.type === 'stamp') {
			emit('update-selected-style', { color })
		} else {
			emit('update-selected-style', { stroke: color })
		}
		return
	}
	if (props.activeTool === 'highlight') {
		emit('update:toolStyle', { ...props.toolStyle, highlightColor: color })
	} else {
		emit('update:toolStyle', { ...props.toolStyle, color })
	}
}

function updateOpacity(opacity: number) {
	if (props.selectedAnnotation) {
		emit('update-selected-style', { opacity })
		return
	}
	if (props.activeTool === 'highlight') {
		emit('update:toolStyle', { ...props.toolStyle, highlightOpacity: opacity })
	} else {
		emit('update:toolStyle', { ...props.toolStyle, opacity })
	}
}

function updateStrokeWidth(val: number) {
	if (props.selectedAnnotation && props.selectedAnnotation.type !== 'text' && props.selectedAnnotation.type !== 'stamp') {
		emit('update-selected-style', { strokeWidth: val })
		return
	}
	if (props.activeTool === 'highlight') {
		emit('update:toolStyle', { ...props.toolStyle, highlightWidth: val })
	} else {
		emit('update:toolStyle', { ...props.toolStyle, strokeWidth: val })
	}
}

function updateLineStyle(lineStyle: LineStyle) {
	if (props.selectedAnnotation && (props.selectedAnnotation.type === 'drawing' || props.selectedAnnotation.type === 'shape')) {
		emit('update-selected-style', { lineStyle })
		return
	}
	emit('update:toolStyle', { ...props.toolStyle, lineStyle })
}

function updateFontSize(fontSize: number) {
	if (
		props.selectedAnnotation &&
		(props.selectedAnnotation.type === 'text' || props.selectedAnnotation.type === 'stamp' || props.selectedAnnotation.type === 'link')
	) {
		emit('update-selected-style', { fontSize })
		return
	}
	if (props.activeTool === 'stamp') {
		emit('update:toolStyle', { ...props.toolStyle, stampSize: fontSize })
	} else {
		emit('update:toolStyle', { ...props.toolStyle, fontSize })
	}
}

function updateTextAlign(textAlign: TextAlign) {
	if (props.selectedAnnotation && props.selectedAnnotation.type === 'text') {
		emit('update-selected-style', { textAlign })
		return
	}
	emit('update:toolStyle', { ...props.toolStyle, textAlign })
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
	emit('update:toolStyle', { ...props.toolStyle, shapeHeight: height })
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
		<div
			v-if="activeTool === 'drawing' || activeTool === 'highlight' || isShapeActive || (selectedAnnotation && (selectedAnnotation.type === 'drawing' || selectedAnnotation.type === 'highlight' || selectedAnnotation.type === 'shape'))"
			:class="$style.styleGroup">
			<NcSelect
				ref="widthSelectRef"
				:model-value="currentStrokeWidthOption"
				:options="strokeWidthOptions"
				:clearable="false"
				:searchable="false"
				:append-to-body="false"
				:dropdown-should-open="widthDropdownShouldOpen"
				:input-label="t('sheetmusic', 'Width')"
				label="label"
				track-by="value"
				:class="$style.ncSelectSmall"
				data-test="annotation-width-select"
				@open="clampNcSelectDropdown(widthSelectRef)"
				@update:model-value="$event && updateStrokeWidth($event.value)">
				<template #list-footer>
					<div
						:class="$style.sliderFooter"
						@mousedown.stop="onWidthSliderStart"
						@pointerdown.stop="onWidthSliderStart"
						@click.stop>
						<input
							type="range"
							min="0"
							max="6"
							step="0.05"
							:value="currentWidthSliderPos"
							:title="t('sheetmusic', 'Custom width')"
							:aria-label="t('sheetmusic', 'Custom width')"
							:class="$style.widthSlider"
							data-test="annotation-width-slider"
							@input="onWidthSliderInput(Number(($event.target as HTMLInputElement).value))" />
					</div>
				</template>
			</NcSelect>
		</div>

		<!-- Line Style (for Pen & Shapes) -->
		<div
			v-if="activeTool === 'drawing' || isShapeActive || (selectedAnnotation && (selectedAnnotation.type === 'drawing' || selectedAnnotation.type === 'shape'))"
			:class="$style.styleGroup">
			<NcSelect
				ref="lineStyleSelectRef"
				:model-value="currentLineStyleOption"
				:options="lineStyleOptions"
				:clearable="false"
				:searchable="false"
				:append-to-body="false"
				:input-label="t('sheetmusic', 'Line')"
				label="label"
				track-by="value"
				:class="$style.ncSelectWide"
				data-test="annotation-line-style-select"
				@open="clampNcSelectDropdown(lineStyleSelectRef)"
				@update:model-value="$event && updateLineStyle($event.value)">
				<template #selected-option="{ label, value }">
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
								:stroke-dasharray="value === 'dashed' ? '5, 3' : value === 'dotted' ? '1, 4' : undefined" />
						</svg>
						<span>{{ label }}</span>
					</span>
				</template>
				<template #option="{ label, value }">
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
								:stroke-dasharray="value === 'dashed' ? '5, 3' : value === 'dotted' ? '1, 4' : undefined" />
						</svg>
						<span>{{ label }}</span>
					</span>
				</template>
			</NcSelect>
		</div>

		<!-- Shape Height (for Hairpins, Slurs, Brackets) -->
		<div
			v-if="isHeightAdjustableShape"
			:class="$style.styleGroup"
			data-test="shape-height-control">
			<NcSelect
				ref="heightSelectRef"
				:model-value="currentShapeHeightOption"
				:options="shapeHeightOptions"
				:clearable="false"
				:searchable="false"
				:append-to-body="false"
				:input-label="t('sheetmusic', 'Height')"
				label="label"
				track-by="value"
				:class="$style.ncSelectCompact"
				data-test="shape-height-select"
				@open="clampNcSelectDropdown(heightSelectRef)"
				@update:model-value="$event && updateShapeHeight($event.value)" />
		</div>

		<!-- Font / Stamp / Link Size -->
		<div
			v-if="activeTool === 'text' || activeTool === 'stamp' || activeTool === 'link' || selectedAnnotation?.type === 'text' || selectedAnnotation?.type === 'stamp' || selectedAnnotation?.type === 'link'"
			:class="$style.styleGroup">
			<NcSelect
				ref="sizeSelectRef"
				:model-value="currentFontSizeOption"
				:options="fontSizeOptions"
				:clearable="false"
				:searchable="false"
				:append-to-body="false"
				:input-label="t('sheetmusic', 'Size')"
				label="label"
				track-by="value"
				:class="$style.ncSelectCompact"
				data-test="annotation-size-select"
				@open="clampNcSelectDropdown(sizeSelectRef)"
				@update:model-value="$event && updateFontSize($event.value)" />
		</div>

		<!-- Text Align -->
		<div
			v-if="activeTool === 'text' || selectedAnnotation?.type === 'text'"
			:class="$style.styleGroup">
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
		<div
			v-if="selectedAnnotation?.type === 'text' || selectedAnnotation?.type === 'link'"
			:class="$style.styleGroup">
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

.styleGroup .ncSelectSmall:global(.nc-select.v-select.select),
.styleGroup .ncSelectCompact:global(.nc-select.v-select.select) {
	width: 84px;
	min-width: 84px;
	max-width: 84px;
}

.styleGroup .ncSelectWide:global(.nc-select.v-select.select) {
	width: 116px;
	min-width: 116px;
	max-width: 116px;
}

.styleGroup .ncSelectSmall:global(.nc-select.v-select.select) :global(.vs__search),
.styleGroup .ncSelectCompact:global(.nc-select.v-select.select) :global(.vs__search),
.styleGroup .ncSelectWide:global(.nc-select.v-select.select) :global(.vs__search) {
	--input-padding-end: 26px;
	--input-padding-start: 10px;
}

.styleGroup .ncSelectSmall:global(.nc-select.v-select.select.vs--single) :global(.vs__selected),
.styleGroup .ncSelectCompact:global(.nc-select.v-select.select.vs--single) :global(.vs__selected),
.styleGroup .ncSelectWide:global(.nc-select.v-select.select.vs--single) :global(.vs__selected) {
	padding-inline: 10px 26px;
}

.styleGroup .ncSelectSmall:global(.nc-select.v-select.select) :global(.vs__actions),
.styleGroup .ncSelectCompact:global(.nc-select.v-select.select) :global(.vs__actions),
.styleGroup .ncSelectWide:global(.nc-select.v-select.select) :global(.vs__actions) {
	--vs-actions-padding: 0 4px 0 0;
}

.ncSelectSmall :global(.vs__dropdown-menu) {
	width: max-content;
	min-width: 0;
}

.ncSelectCompact :global(.vs__dropdown-menu) {
	width: max-content;
	min-width: 72px;
}

.ncSelectWide :global(.vs__dropdown-menu) {
	width: max-content;
	min-width: 124px;
}

.sliderFooter {
	display: flex;
	align-items: center;
	padding: 6px 10px;
	border-top: 1px solid var(--color-border);
}

.widthSlider {
	width: 132px;
	min-width: 132px;
	accent-color: var(--color-primary-element, #0082c9);
	cursor: pointer;
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
