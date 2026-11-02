<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import MagnifyPlus from 'vue-material-design-icons/MagnifyPlus.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

const props = withDefaults(
	defineProps<{
		pageNum: number
		thumbnailUrl?: string
		occurrenceCount?: number
		cardWidth?: number
		disabled?: boolean
	}>(),
	{
		thumbnailUrl: '',
		occurrenceCount: 0,
		cardWidth: 220,
		disabled: false,
	},
)

const emit = defineEmits<{
	append: [pageNum: number]
	zoom: [pageNum: number]
}>()

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
		]"
		:style="cardStyle">
		<!-- Header with page number & occurrence badge -->
		<div :class="$style.cardHeader">
			<span :class="$style.pageNumber">
				{{ t('sheetmusic', 'Page {p}', { p: pageNum }) }}
			</span>
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
				loading="lazy" />
			<div v-else :class="$style.placeholder">
				<span :class="$style.placeholderText">{{ t('sheetmusic', 'Page {p}', { p: pageNum }) }}</span>
			</div>

			<!-- Zoom overlay button -->
			<button
				type="button"
				:class="$style.zoomBtn"
				:title="t('sheetmusic', 'Zoom in on page {p}', { p: pageNum })"
				:aria-label="t('sheetmusic', 'Zoom in on page {p}', { p: pageNum })"
				@click.stop="emit('zoom', pageNum)">
				<MagnifyPlus :size="18" />
			</button>
		</div>

		<!-- Card Actions Footer -->
		<div :class="$style.cardFooter">
			<button
				type="button"
				:class="$style.addBtn"
				:disabled="disabled"
				:title="t('sheetmusic', 'Add page {p} to order', { p: pageNum })"
				:aria-label="t('sheetmusic', 'Add page {p} to order', { p: pageNum })"
				@click="emit('append', pageNum)">
				<Plus :size="16" />
				<span>{{ t('sheetmusic', 'Add to Order') }}</span>
			</button>
		</div>
	</div>
</template>

<style module>
.card {
	display: flex;
	flex-direction: column;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	overflow: hidden;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
	transition: box-shadow 0.2s ease, border-color 0.2s ease, transform 0.15s ease;
	box-sizing: border-box;
}

.card:hover {
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
	border-color: var(--color-primary-element);
}

.cardInOrder {
	border-color: var(--color-primary-element-light, #0082c9);
}

.cardDisabled {
	opacity: 0.6;
	pointer-events: none;
}

.cardHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 6px 10px;
	background-color: var(--color-background-hover);
	border-bottom: 1px solid var(--color-border);
}

.pageNumber {
	font-weight: 600;
	font-size: 0.85rem;
	color: var(--color-main-text);
}

.badge {
	font-size: 0.72rem;
	padding: 1px 6px;
	border-radius: var(--border-radius-pill, 10px);
	font-weight: 600;
}

.badgeActive {
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.badgeMuted {
	background-color: var(--color-background-darker, rgba(0, 0, 0, 0.06));
	color: var(--color-text-maxcontrast);
}

.previewWrapper {
	position: relative;
	width: 100%;
	aspect-ratio: 0.707; /* Standard A4 sheet aspect ratio */
	background-color: #f7f7f7;
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
	user-select: none;
}

.placeholder {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 100%;
	height: 100%;
	background-color: var(--color-background-hover);
}

.placeholderText {
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	opacity: 0.5;
}

.zoomBtn {
	position: absolute;
	top: 8px;
	right: 8px;
	width: 32px;
	height: 32px;
	border-radius: 50%;
	background: rgba(255, 255, 255, 0.92);
	border: 1px solid rgba(0, 0, 0, 0.15);
	color: #222;
	display: flex;
	align-items: center;
	justify-content: center;
	cursor: pointer;
	box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
	transition: background-color 0.15s ease, transform 0.15s ease;
	opacity: 0.85;
}

.zoomBtn:hover {
	background: #fff;
	opacity: 1;
	transform: scale(1.1);
	color: var(--color-primary-element);
}

.cardFooter {
	padding: 6px;
	background-color: var(--color-main-background);
	border-top: 1px solid var(--color-border);
}

.addBtn {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 4px;
	width: 100%;
	padding: 5px 8px;
	font-size: 0.8rem;
	font-weight: 500;
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	color: var(--color-main-text);
	cursor: pointer;
	transition: background-color 0.15s ease, border-color 0.15s ease;
}

.addBtn:hover:not(:disabled) {
	background-color: var(--color-primary-light, #e0f2fe);
	border-color: var(--color-primary-element);
	color: var(--color-primary-element);
}

.addBtn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}
</style>
