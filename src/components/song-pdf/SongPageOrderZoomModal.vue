<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

withDefaults(
	defineProps<{
		open: boolean
		pageNum: number
		totalPages: number
		highResUrl?: string
		thumbnailUrl?: string
		isLoadingHighRes?: boolean
	}>(),
	{
		highResUrl: '',
		thumbnailUrl: '',
		isLoadingHighRes: false,
	},
)

const emit = defineEmits<{
	'update:open': [value: boolean]
	'prev': []
	'next': []
	'append': [pageNum: number]
}>()
</script>

<template>
	<NcDialog
		v-if="open"
		:open="open"
		:name="t('sheetmusic', 'Page {p} of {total}', { p: pageNum, total: totalPages })"
		size="large"
		@update:open="emit('update:open', $event)">
		<template #actions>
			<NcButton
				type="button"
				variant="secondary"
				:disabled="pageNum <= 1"
				:title="t('sheetmusic', 'Previous page')"
				:aria-label="t('sheetmusic', 'Previous page')"
				@click="emit('prev')">
				<template #icon>
					<ChevronLeft :size="20" />
				</template>
				{{ t('sheetmusic', 'Previous') }}
			</NcButton>
			<NcButton
				type="button"
				variant="secondary"
				:disabled="pageNum >= totalPages"
				:title="t('sheetmusic', 'Next page')"
				:aria-label="t('sheetmusic', 'Next page')"
				@click="emit('next')">
				<template #icon>
					<ChevronRight :size="20" />
				</template>
				{{ t('sheetmusic', 'Next') }}
			</NcButton>
			<NcButton
				type="button"
				variant="primary"
				:title="t('sheetmusic', 'Add page {p} to order', { p: pageNum })"
				:aria-label="t('sheetmusic', 'Add page {p} to order', { p: pageNum })"
				@click="emit('append', pageNum)">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('sheetmusic', 'Add to Order') }}
			</NcButton>
			<NcButton
				type="button"
				variant="secondary"
				:title="t('sheetmusic', 'Close zoom')"
				:aria-label="t('sheetmusic', 'Close zoom')"
				@click="emit('update:open', false)">
				<template #icon>
					<Close :size="20" />
				</template>
				{{ t('sheetmusic', 'Close') }}
			</NcButton>
		</template>

		<div :class="$style.zoomContent" data-test="zoom-modal-content">
			<div :class="$style.imageContainer">
				<div v-if="isLoadingHighRes && !highResUrl" :class="$style.loadingBadge" data-test="loading-high-res">
					<NcLoadingIcon :size="16" />
					<span>{{ t('sheetmusic', 'Rendering high resolution…') }}</span>
				</div>

				<img
					v-if="highResUrl || thumbnailUrl"
					:src="highResUrl || thumbnailUrl"
					:alt="t('sheetmusic', 'Page {p} zoomed preview', { p: pageNum })"
					:class="$style.zoomedImage"
					data-test="zoom-image" />
				<div v-else :class="$style.placeholder">
					<span>{{ t('sheetmusic', 'Page {p}', { p: pageNum }) }}</span>
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<style module>
.zoomContent {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 12px 0;
	width: 100%;
	height: 75vh;
	box-sizing: border-box;
}

.imageContainer {
	position: relative;
	max-width: 100%;
	max-height: 100%;
	display: flex;
	align-items: center;
	justify-content: center;
	box-shadow: 0 4px 24px rgba(0, 0, 0, 0.18);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: #fff;
	overflow: auto;
}

.loadingBadge {
	position: absolute;
	top: 12px;
	right: 12px;
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 4px 10px;
	border-radius: var(--border-radius-pill, 16px);
	background: rgba(0, 0, 0, 0.7);
	color: #fff;
	font-size: 0.8rem;
	z-index: 10;
}

.zoomedImage {
	max-width: 100%;
	max-height: 73vh;
	object-fit: contain;
	display: block;
	image-rendering: high-quality;
}

.placeholder {
	width: 440px;
	height: 600px;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 1.5rem;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
	background-color: var(--color-background-hover);
}
</style>
