<script setup lang="ts">
import type { SheetAnnotation } from '../../types/annotation'
import type { Bookmark } from '../../types/song'
import { LINK_LABEL_PRESETS, type LinkMarkerStyle } from '../../utils/annotationLinks'
import { nextTick, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'

export interface PendingTextEdit {
	mode: 'create' | 'edit'
	x: number
	y: number
	width: number
	height: number
	text: string
	existing?: SheetAnnotation
}

export interface PendingLinkEdit {
	mode?: 'create' | 'edit'
	x: number
	y: number
	width: number
	height: number
	targetPageOneIndexed: number
	targetX?: number
	targetY?: number
	label: string
	linkStyle: LinkMarkerStyle
	createReverse: boolean
	existing?: SheetAnnotation
}

const props = withDefaults(defineProps<{
	pendingText: PendingTextEdit | null
	pendingLink: PendingLinkEdit | null
	totalPages: number
	bookmarks?: Bookmark[]
}>(), {
	bookmarks: () => [],
})

const emit = defineEmits<{
	'update:pendingTextText': [text: string]
	'update:pendingLinkPage': [page: number]
	'update:pendingLinkLabel': [label: string]
	'update:pendingLinkStyle': [style: LinkMarkerStyle]
	'update:pendingLinkReverse': [enabled: boolean]
	'pick-link-target': []
	'open-link-picker': []
	'commit-text': []
	'cancel-text': []
	'commit-link': []
	'cancel-link': []
}>()

function applyBookmarkTarget(event: Event) {
	const value = (event.target as HTMLSelectElement).value
	if (!value) return
	const idx = Number.parseInt(value, 10)
	const bm = props.bookmarks[idx]
	if (!bm) return
	emit('update:pendingLinkPage', bm.pageNum)
	emit('update:pendingLinkLabel', bm.name)
}

const textInputRef = ref<HTMLTextAreaElement | null>(null)
const anchorRef = ref<HTMLElement | null>(null)
const textPopoverRef = ref<HTMLElement | null>(null)
const linkPopoverRef = ref<HTMLElement | null>(null)
const fixedPopoverStyle = ref<Record<string, string> | null>(null)

function clampedPositionStyle(x: number, y: number, minWidthPx = 210, estHeightPx = 180) {
	return {
		left: `clamp(8px, ${x * 100}%, max(8px, calc(100% - ${minWidthPx + 16}px)))`,
		top: `clamp(8px, ${y * 100}%, max(8px, calc(100% - ${estHeightPx + 16}px)))`,
		minWidth: `min(${minWidthPx}px, calc(100% - 16px))`,
	}
}

function updateFixedPopoverPosition(
	x: number,
	y: number,
	minWidthPx: number,
	estHeightPx: number,
	popoverEl: HTMLElement | null,
) {
	const hostRect = anchorRef.value?.parentElement?.getBoundingClientRect()
	if (!hostRect || (hostRect.width === 0 && hostRect.height === 0) || typeof window === 'undefined') {
		fixedPopoverStyle.value = null
		return
	}
	const popWidth = Math.max(minWidthPx, popoverEl?.offsetWidth ?? minWidthPx)
	const popHeight = Math.max(120, popoverEl?.offsetHeight ?? estHeightPx)
	const margin = 8
	const rawLeft = hostRect.left + x * hostRect.width
	const rawTop = hostRect.top + y * hostRect.height
	const left = Math.max(margin, Math.min(rawLeft, window.innerWidth - popWidth - margin))
	const top = Math.max(margin, Math.min(rawTop, window.innerHeight - popHeight - margin))

	fixedPopoverStyle.value = {
		position: 'fixed',
		left: `${Math.round(left)}px`,
		top: `${Math.round(top)}px`,
		minWidth: `min(${minWidthPx}px, calc(100vw - 16px))`,
		maxHeight: 'calc(100vh - 16px)',
		overflowY: 'auto',
		zIndex: '100010',
	}
}

watch(
	() => props.pendingText,
	(val) => {
		if (!val) {
			if (!props.pendingLink) fixedPopoverStyle.value = null
			return
		}
		const minW = Math.max(180, val.width * 100)
		updateFixedPopoverPosition(val.x, val.y, minW, 160, null)
		nextTick(() => {
			updateFixedPopoverPosition(val.x, val.y, minW, 160, textPopoverRef.value)
			textInputRef.value?.focus()
		})
	},
	{ immediate: true },
)

watch(
	() => props.pendingLink,
	(val) => {
		if (!val) {
			if (!props.pendingText) fixedPopoverStyle.value = null
			return
		}
		updateFixedPopoverPosition(val.x, val.y, 250, 290, null)
		nextTick(() => {
			updateFixedPopoverPosition(val.x, val.y, 250, 290, linkPopoverRef.value)
		})
	},
	{ immediate: true },
)
</script>

<template>
	<span ref="anchorRef" class="sheetmusic-inline-anchor" aria-hidden="true" />

	<!-- Inline Text Editor Popover -->
	<Teleport to="body">
		<div
			v-if="pendingText"
			ref="textPopoverRef"
			class="sheetmusic-inline-popover"
			:style="fixedPopoverStyle ?? clampedPositionStyle(pendingText.x, pendingText.y, Math.max(180, pendingText.width * 100))"
			@pointerdown.stop>
			<textarea
				ref="textInputRef"
				:value="pendingText.text"
				rows="3"
				:placeholder="t('sheetmusic', 'Enter annotation text...')"
				class="sheetmusic-inline-textarea"
				data-test="annotation-text-input"
				@input="emit('update:pendingTextText', ($event.target as HTMLTextAreaElement).value)"
				@keydown.ctrl.enter.prevent="emit('commit-text')"
				@keydown.meta.enter.prevent="emit('commit-text')"
				@keydown.esc.prevent="emit('cancel-text')" />
			<div class="sheetmusic-inline-actions">
				<button type="button" class="sheetmusic-inline-btn" @click="emit('cancel-text')">
					{{ t('sheetmusic', 'Cancel') }}
				</button>
				<button type="button" class="sheetmusic-inline-btn primary" data-test="annotation-text-save" @click="emit('commit-text')">
					{{ t('sheetmusic', 'Save') }}
				</button>
			</div>
		</div>
	</Teleport>

	<!-- Inline Link Target Popover -->
	<Teleport to="body">
		<div
			v-if="pendingLink"
			ref="linkPopoverRef"
			class="sheetmusic-inline-popover"
			:style="fixedPopoverStyle ?? clampedPositionStyle(pendingLink.x, pendingLink.y, 250, 290)"
			data-test="annotation-link-popover"
			@pointerdown.stop>
		<div class="sheetmusic-inline-title">{{ t('sheetmusic', 'Page jump link') }}</div>
		<div class="sheetmusic-inline-fields">
			<label>
				<span>{{ t('sheetmusic', 'Target page') }}</span>
				<input
					:value="pendingLink.targetPageOneIndexed"
					type="number"
					min="1"
					:max="totalPages"
					class="sheetmusic-inline-input"
					data-test="annotation-link-page-input"
					@input="emit('update:pendingLinkPage', Number(($event.target as HTMLInputElement).value))" />
			</label>
			<label v-if="bookmarks.length > 0">
				<span>{{ t('sheetmusic', 'Or bookmark') }}</span>
				<select
					class="sheetmusic-inline-input"
					data-test="annotation-link-bookmark-select"
					@change="applyBookmarkTarget">
					<option value="">{{ t('sheetmusic', 'Pick bookmark…') }}</option>
					<option
						v-for="(bm, idx) in bookmarks"
						:key="`${bm.name}-${bm.pageNum}-${idx}`"
						:value="String(idx)">
						{{ bm.name }} (p. {{ bm.pageNum }})
					</option>
				</select>
			</label>
			<label>
				<span>{{ t('sheetmusic', 'Button label') }}</span>
				<input
					:value="pendingLink.label"
					type="text"
					:placeholder="t('sheetmusic', 'e.g. To Coda')"
					class="sheetmusic-inline-input"
					data-test="annotation-link-label-input"
					@input="emit('update:pendingLinkLabel', ($event.target as HTMLInputElement).value)" />
			</label>
			<div class="sheetmusic-inline-presets" data-test="annotation-link-presets">
				<button
					v-for="preset in LINK_LABEL_PRESETS"
					:key="preset"
					type="button"
					class="sheetmusic-inline-chip"
					:class="{ active: pendingLink.label === preset }"
					:data-test="`annotation-link-preset-${preset.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`"
					@click="emit('update:pendingLinkLabel', preset)">
					{{ preset }}
				</button>
			</div>
			<div class="sheetmusic-inline-style-row">
				<span>{{ t('sheetmusic', 'Style') }}</span>
				<div class="sheetmusic-inline-style-btns">
					<button
						type="button"
						class="sheetmusic-inline-chip"
						:class="{ active: pendingLink.linkStyle !== 'circle' }"
						data-test="annotation-link-style-box"
						@click="emit('update:pendingLinkStyle', 'box')">
						{{ t('sheetmusic', 'Box') }}
					</button>
					<button
						type="button"
						class="sheetmusic-inline-chip"
						:class="{ active: pendingLink.linkStyle === 'circle' }"
						data-test="annotation-link-style-circle"
						@click="emit('update:pendingLinkStyle', 'circle')">
						{{ t('sheetmusic', 'Circle') }}
					</button>
				</div>
			</div>
			<label v-if="pendingLink.mode !== 'edit'" class="sheetmusic-inline-checkbox">
				<input
					type="checkbox"
					:checked="pendingLink.createReverse"
					data-test="annotation-link-reverse-checkbox"
					@change="emit('update:pendingLinkReverse', ($event.target as HTMLInputElement).checked)">
				<span>{{ t('sheetmusic', 'Create reverse link') }}</span>
			</label>
			<div class="sheetmusic-inline-pick-row">
				<button
					type="button"
					class="sheetmusic-inline-pick-btn"
					data-test="annotation-link-pick-target"
					@click="emit('pick-link-target')">
					{{ t('sheetmusic', 'Pick on page…') }}
				</button>
				<button
					type="button"
					class="sheetmusic-inline-pick-btn"
					data-test="annotation-link-open-picker"
					@click="emit('open-link-picker')">
					{{ t('sheetmusic', 'Side-by-side…') }}
				</button>
			</div>
		</div>
		<div class="sheetmusic-inline-actions">
			<button type="button" class="sheetmusic-inline-btn" @click="emit('cancel-link')">
				{{ t('sheetmusic', 'Cancel') }}
			</button>
			<button type="button" class="sheetmusic-inline-btn primary" data-test="annotation-link-save" @click="emit('commit-link')">
				{{ pendingLink.mode === 'edit' ? t('sheetmusic', 'Save Link') : t('sheetmusic', 'Add Link') }}
			</button>
		</div>
		</div>
	</Teleport>
</template>

<style scoped>
.sheetmusic-inline-anchor {
	display: none;
}
.sheetmusic-inline-popover {
	position: absolute;
	z-index: 20;
	background: var(--color-main-background, #ffffff);
	color: var(--color-main-text, #222222);
	border: 1px solid var(--color-border-dark, #cccccc);
	border-radius: 8px;
	box-shadow: 0 6px 20px rgba(0, 0, 0, 0.22);
	padding: 8px;
	min-width: min(210px, calc(100% - 16px));
	max-width: calc(100% - 16px);
	box-sizing: border-box;
	pointer-events: auto;
}

.sheetmusic-inline-title {
	font-size: 12px;
	font-weight: 600;
	margin-bottom: 6px;
}

.sheetmusic-inline-textarea {
	width: 100%;
	box-sizing: border-box;
	padding: 6px;
	border: 1px solid var(--color-border-dark, #cccccc);
	border-radius: 4px;
	font-size: 13px;
	resize: both;
	background: var(--color-main-background, #ffffff);
	color: var(--color-main-text, #222222);
}

.sheetmusic-inline-fields {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-bottom: 8px;
}

.sheetmusic-inline-fields label {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	font-size: 12px;
}

.sheetmusic-inline-input {
	width: 120px;
	padding: 4px 6px;
	border: 1px solid var(--color-border-dark, #cccccc);
	border-radius: 4px;
	font-size: 12px;
}

.sheetmusic-inline-actions {
	display: flex;
	justify-content: flex-end;
	gap: 6px;
	margin-top: 6px;
}

.sheetmusic-inline-btn {
	padding: 4px 10px;
	border-radius: 4px;
	border: 1px solid var(--color-border-dark, #cccccc);
	background: var(--color-background-hover, #f5f5f5);
	color: var(--color-main-text, #222222);
	font-size: 12px;
	cursor: pointer;
}

.sheetmusic-inline-btn.primary {
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #ffffff);
	border-color: var(--color-primary-element, #0082c9);
}

.sheetmusic-inline-presets {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-bottom: 8px;
}

.sheetmusic-inline-style-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	font-size: 12px;
}

.sheetmusic-inline-style-btns {
	display: inline-flex;
	gap: 4px;
}

.sheetmusic-inline-checkbox {
	display: flex;
	align-items: center;
	justify-content: flex-start !important;
	gap: 6px;
	font-size: 12px;
	cursor: pointer;
}

.sheetmusic-inline-pick-row {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 5px;
	margin-top: 2px;
}

.sheetmusic-inline-pick-btn {
	width: 100%;
	margin: 0;
	padding: 5px 6px;
	border: 1px dashed var(--color-primary-element, #0082c9);
	border-radius: 6px;
	background: var(--color-primary-light, #e6f4fa);
	color: var(--color-main-text, #222222);
	font-size: 11px;
	font-weight: 600;
	cursor: pointer;
	text-align: center;
}

.sheetmusic-inline-pick-btn:hover {
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #ffffff);
}

.sheetmusic-inline-chip {
	margin: 0;
	padding: 2px 7px;
	min-height: 24px;
	border: 1px solid var(--color-border-dark, #cccccc);
	border-radius: 999px;
	background: var(--color-background-hover, #f5f5f5);
	color: var(--color-main-text, #222222);
	font-size: 11px;
	cursor: pointer;
}

.sheetmusic-inline-chip.active {
	border-color: var(--color-primary-element, #0082c9);
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #ffffff);
	font-weight: 600;
}
</style>
