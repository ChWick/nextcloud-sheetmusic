<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import MagnifyPlus from 'vue-material-design-icons/MagnifyPlus.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import DragVertical from 'vue-material-design-icons/DragVertical.vue'

const props = withDefaults(
	defineProps<{
		pageNum: number
		sequenceIndex?: number | null
		sequenceTotal?: number | null
		thumbnailUrl?: string
		occurrenceCount?: number
		cardWidth?: number
		disabled?: boolean
		showRemove?: boolean
		draggable?: boolean
		isDragging?: boolean
		dropIndicator?: 'left' | 'right' | null
	}>(),
	{
		sequenceIndex: null,
		sequenceTotal: null,
		thumbnailUrl: '',
		occurrenceCount: 0,
		cardWidth: 220,
		disabled: false,
		showRemove: false,
		draggable: false,
		isDragging: false,
		dropIndicator: null,
	},
)

const emit = defineEmits<{
	append: [pageNum: number]
	remove: [sequenceIndex: number]
	zoom: [pageNum: number]
	dragstart: [event: DragEvent]
	dragend: [event: DragEvent]
	dragover: [event: DragEvent]
	dragleave: [event: DragEvent]
	drop: [event: DragEvent]
}>()

const isSequenceMode = computed(() => props.sequenceIndex !== null)
const isInOrder = computed(() => props.occurrenceCount > 0)
const cardStyle = computed(() => ({
	width: `${props.cardWidth}px`,
	minWidth: `${Math.min(props.cardWidth, 140)}px`,
}))
</script>

<template>
	<div
		:class="[
			$style.card,
			isInOrder && $style.cardInOrder,
			disabled && $style.cardDisabled,
			isDragging && $style.cardDragging,
			draggable && !disabled && $style.cardDraggable,
			dropIndicator === 'left' && $style.dropIndicatorLeft,
			dropIndicator === 'right' && $style.dropIndicatorRight,
		]"
		:style="cardStyle"
		:draggable="draggable && !disabled"
		:data-test="isSequenceMode ? `seq-card-${sequenceIndex}-${pageNum}` : `page-card-${pageNum}`"
		@dragstart="emit('dragstart', $event)"
		@dragend="emit('dragend', $event)"
		@dragover="emit('dragover', $event)"
		@dragleave="emit('dragleave', $event)"
		@drop="emit('drop', $event)">
		<!-- Header with drag handle, page number / sequence position -->
		<div :class="$style.cardHeader">
			<div :class="$style.titleArea">
				<span
					v-if="draggable && !disabled"
					:class="$style.dragHandle"
					:title="t('sheetmusic', 'Drag to reorder or add to page order')"
					aria-hidden="true"
					data-test="card-drag-handle">
					<DragVertical :size="16" />
				</span>
				<span v-if="isSequenceMode" :class="$style.sequenceBadge" data-test="sequence-badge">
					#{{ (sequenceIndex ?? 0) + 1 }}
				</span>
				<span :class="$style.pageNumber">
					{{ t('sheetmusic', 'Page {p}', { p: pageNum }) }}
				</span>
			</div>

			<span
				:class="[
					$style.badge,
					isInOrder ? $style.badgeActive : $style.badgeMuted,
				]"
				:title="isInOrder
					? t('sheetmusic', 'Included {count} time in page order | Included {count} times in page order', { count: occurrenceCount }, occurrenceCount)
					: t('sheetmusic', 'Not included in page order')">
				{{ isInOrder ? `${occurrenceCount}×` : t('sheetmusic', 'Unused') }}
			</span>
		</div>

		<!-- Page Preview Area with Zoom Button -->
		<div :class="$style.previewWrapper">
			<img
				v-if="thumbnailUrl"
				:src="thumbnailUrl"
				:alt="t('sheetmusic', 'Page {p} preview', { p: pageNum })"
				:class="$style.previewImage"
				loading="lazy"
				data-test="page-thumbnail-img" />
			<div v-else :class="$style.placeholder">
				<span :class="$style.placeholderText">{{ t('sheetmusic', 'Page {p}', { p: pageNum }) }}</span>
			</div>

			<!-- Zoom overlay button -->
			<button
				type="button"
				:class="$style.zoomBtn"
				:title="t('sheetmusic', 'Zoom in on page {p}', { p: pageNum })"
				:aria-label="t('sheetmusic', 'Zoom in on page {p}', { p: pageNum })"
				data-test="card-zoom-btn"
				@click.stop="emit('zoom', pageNum)">
				<MagnifyPlus :size="18" />
			</button>
		</div>

		<!-- Card Actions Footer -->
		<div :class="$style.cardFooter">
			<template v-if="isSequenceMode && showRemove">
				<button
					type="button"
					:class="$style.removeBtn"
					:disabled="disabled"
					:title="t('sheetmusic', 'Remove position #{pos} from page order', { pos: (sequenceIndex ?? 0) + 1 })"
					:aria-label="t('sheetmusic', 'Remove position #{pos} from page order', { pos: (sequenceIndex ?? 0) + 1 })"
					data-test="card-remove-btn"
					@click="emit('remove', sequenceIndex ?? 0)">
					<DeleteOutline :size="16" />
					<span>{{ t('sheetmusic', 'Remove') }}</span>
				</button>
				<button
					type="button"
					:class="$style.addBtn"
					:disabled="disabled"
					:title="t('sheetmusic', 'Duplicate page {p}', { p: pageNum })"
					:aria-label="t('sheetmusic', 'Duplicate page {p}', { p: pageNum })"
					data-test="card-add-btn"
					@click="emit('append', pageNum)">
					<Plus :size="16" />
					<span>{{ t('sheetmusic', 'Copy') }}</span>
				</button>
			</template>

			<template v-else>
				<button
					type="button"
					:class="$style.addBtn"
					:disabled="disabled"
					:title="t('sheetmusic', 'Add page {p} to order', { p: pageNum })"
					:aria-label="t('sheetmusic', 'Add page {p} to order', { p: pageNum })"
					data-test="card-add-btn"
					@click="emit('append', pageNum)">
					<Plus :size="16" />
					<span>{{ t('sheetmusic', 'Add to Order') }}</span>
				</button>
			</template>
		</div>
	</div>
</template>

<style module>
.card {
	position: relative;
	display: flex;
	flex-direction: column;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background, #fff);
	overflow: visible;
	box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
	transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease, opacity 0.15s ease;
	box-sizing: border-box;
}

.card:hover {
	transform: translateY(-2px);
	box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
	border-color: var(--color-primary-element);
}

.cardDraggable {
	cursor: grab;
}

.cardDraggable:active {
	cursor: grabbing;
}

.cardDragging {
	opacity: 0.45;
	border-style: dashed;
	border-color: var(--color-primary-element);
	transform: scale(0.97);
}

.dropIndicatorLeft::before {
	content: '';
	position: absolute;
	top: -4px;
	bottom: -4px;
	left: -6px;
	width: 4px;
	border-radius: 2px;
	background-color: var(--color-primary-element, #0082c9);
	box-shadow: 0 0 6px var(--color-primary-element, #0082c9);
	z-index: 20;
}

.dropIndicatorRight::after {
	content: '';
	position: absolute;
	top: -4px;
	bottom: -4px;
	right: -6px;
	width: 4px;
	border-radius: 2px;
	background-color: var(--color-primary-element, #0082c9);
	box-shadow: 0 0 6px var(--color-primary-element, #0082c9);
	z-index: 20;
}

.cardInOrder {
	border-color: var(--color-primary-element, #0082c9);
}

.cardDisabled {
	opacity: 0.6;
	pointer-events: none;
}

.cardHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 10px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
	border-top-left-radius: inherit;
	border-top-right-radius: inherit;
}

.titleArea {
	display: flex;
	align-items: center;
	gap: 6px;
}

.dragHandle {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	color: var(--color-text-maxcontrast);
	opacity: 0.7;
	cursor: grab;
	margin-right: -2px;
}

.dragHandle:hover {
	opacity: 1;
	color: var(--color-primary-element);
}

.sequenceBadge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	padding: 1px 6px;
	border-radius: var(--border-radius-pill, 10px);
	background: var(--color-primary-element, #0082c9);
	color: #fff;
	font-weight: 700;
	font-size: 0.78rem;
}

.pageNumber {
	font-weight: 600;
	font-size: 0.85rem;
	color: var(--color-main-text);
}

.badge {
	font-size: 0.75rem;
	font-weight: 600;
	padding: 2px 6px;
	border-radius: var(--border-radius-pill, 10px);
}

.badgeActive {
	background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.15));
	color: var(--color-primary-element, #0082c9);
}

.badgeMuted {
	background-color: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
}

.previewWrapper {
	position: relative;
	width: 100%;
	aspect-ratio: 0.707; /* A4 aspect ratio */
	background-color: #fff;
	display: flex;
	align-items: center;
	justify-content: center;
	overflow: hidden;
}

.previewImage {
	width: 100%;
	height: 100%;
	object-fit: contain;
	display: block;
}

.placeholder {
	width: 100%;
	height: 100%;
	display: flex;
	align-items: center;
	justify-content: center;
	background-color: var(--color-background-hover);
}

.placeholderText {
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.zoomBtn {
	position: absolute;
	top: 8px;
	right: 8px;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	border-radius: 50%;
	border: 1px solid var(--color-border);
	background-color: rgba(255, 255, 255, 0.9);
	color: var(--color-main-text);
	cursor: pointer;
	box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
	transition: background-color 0.15s ease, transform 0.15s ease;
	opacity: 0.85;
}

.card:hover .zoomBtn {
	opacity: 1;
}

.zoomBtn:hover {
	background-color: #fff;
	transform: scale(1.1);
	color: var(--color-primary-element);
}

.cardFooter {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 6px 8px;
	border-top: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
	border-bottom-left-radius: inherit;
	border-bottom-right-radius: inherit;
}

.addBtn,
.removeBtn {
	flex: 1;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 4px;
	padding: 6px 8px;
	border-radius: var(--border-radius, 4px);
	border: 1px solid var(--color-border);
	font-size: 0.78rem;
	font-weight: 600;
	cursor: pointer;
	transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}

.addBtn {
	background-color: var(--color-main-background, #fff);
	color: var(--color-primary-element);
}

.addBtn:hover:not(:disabled) {
	background-color: var(--color-primary-element);
	border-color: var(--color-primary-element);
	color: var(--color-primary-element-text, #fff);
}

.removeBtn {
	background-color: var(--color-main-background, #fff);
	color: var(--color-error, #e74c3c);
}

.removeBtn:hover:not(:disabled) {
	background-color: var(--color-error, #e74c3c);
	border-color: var(--color-error, #e74c3c);
	color: #fff;
}
</style>
