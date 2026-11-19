<script setup lang="ts">
import type { PdfPageResolutionSummary } from './pdf-enhance-engine'
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import ViewportPopover from '../ViewportPopover.vue'

const props = withDefaults(
	defineProps<{
		formattedFileSize: string
		fileSizeBytes?: number | null
		totalPages: number
		pageLabel?: string
		primaryResolution?: PdfPageResolutionSummary | null
		pageResolutions?: PdfPageResolutionSummary[]
		showTriggerText?: boolean
		triggerTestId?: string
		dropdownTestId?: string
		fileSizeTestId?: string
		resolutionTestId?: string
	}>(),
	{
		fileSizeBytes: null,
		pageLabel: undefined,
		primaryResolution: null,
		pageResolutions: () => [],
		showTriggerText: false,
		triggerTestId: 'pdf-info-trigger',
		dropdownTestId: 'pdf-info-dropdown',
		fileSizeTestId: 'pdf-info-filesize',
		resolutionTestId: 'pdf-info-resolution',
	},
)

const isOpen = ref(false)
const displayedPages = computed(() => props.pageLabel ?? String(props.totalPages))
</script>

<template>
	<ViewportPopover
		v-model:open="isOpen"
		:title="t('sheetmusic', 'PDF File & Resolution Info')"
		:pane-test-id="dropdownTestId"
		:pane-class="$style.infoPane">
		<template #trigger="{ toggle }">
			<button
				type="button"
				:class="$style.infoBadgeBtn"
				:data-test="triggerTestId"
				:title="t('sheetmusic', 'Show PDF file size and resolution details')"
				:aria-label="t('sheetmusic', 'Show PDF file size and resolution details')"
				:aria-expanded="isOpen"
				@click="toggle">
				<InformationOutline :size="15" />
				<span v-if="showTriggerText" :class="$style.infoBadgeText">
					<span v-if="formattedFileSize !== '—'">{{ formattedFileSize }}</span>
					<span v-if="formattedFileSize !== '—' && primaryResolution"> · </span>
					<span v-if="primaryResolution">
						{{ primaryResolution.dpi }} DPI ({{ primaryResolution.pixelWidth }}×{{ primaryResolution.pixelHeight }} px)
					</span>
				</span>
			</button>
		</template>

		<div :class="$style.infoGrid">
			<span :class="$style.infoLabel">{{ t('sheetmusic', 'File size:') }}</span>
			<span :class="$style.infoVal" :data-test="fileSizeTestId">
				{{ formattedFileSize }}
				<template v-if="fileSizeBytes"> ({{ fileSizeBytes.toLocaleString() }} B)</template>
			</span>
			<span :class="$style.infoLabel">{{ t('sheetmusic', 'Pages:') }}</span>
			<span :class="$style.infoVal">{{ displayedPages }}</span>
			<template v-if="primaryResolution">
				<span :class="$style.infoLabel">{{ t('sheetmusic', 'Resolution:') }}</span>
				<span :class="$style.infoVal" :data-test="resolutionTestId">
					{{ primaryResolution.pixelWidth }} × {{ primaryResolution.pixelHeight }} px ({{ primaryResolution.dpi }} DPI)
				</span>
				<span :class="$style.infoLabel">{{ t('sheetmusic', 'Page size:') }}</span>
				<span :class="$style.infoVal">
					{{ primaryResolution.widthMm }} × {{ primaryResolution.heightMm }} mm ({{ Math.round(primaryResolution.widthPt) }} × {{ Math.round(primaryResolution.heightPt) }} pt)
				</span>
			</template>
		</div>
		<div
			v-if="pageResolutions && pageResolutions.length > 1"
			:class="$style.perPageList">
			<div :class="$style.perPageTitle">{{ t('sheetmusic', 'Per-Page Resolution') }}</div>
			<div
				v-for="pr in pageResolutions"
				:key="pr.pageNum"
				:class="$style.perPageRow">
				<span>{{ t('sheetmusic', 'Page {n}', { n: pr.pageNum }, { escape: false }) }}</span>
				<span>{{ pr.pixelWidth }}×{{ pr.pixelHeight }} px · {{ pr.dpi }} DPI · {{ pr.widthMm }}×{{ pr.heightMm }} mm</span>
			</div>
		</div>
	</ViewportPopover>
</template>

<style module>
.infoPane {
	width: min(310px, calc(100vw - 24px));
}

.infoBadgeBtn {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 3px 9px;
	border-radius: 12px;
	border: 1px solid var(--color-border);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	font-size: 0.8rem;
	cursor: pointer;
	white-space: nowrap;
}

.infoBadgeBtn:hover {
	background: var(--color-background-dark);
}

.infoGrid {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 4px 10px;
}

.infoLabel {
	color: var(--color-text-maxcontrast);
}

.infoVal {
	font-weight: 600;
}

.perPageList {
	margin-top: 8px;
	padding-top: 6px;
	border-top: 1px solid var(--color-border);
	max-height: 150px;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 3px;
}

.perPageTitle {
	font-weight: 600;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 2px;
}

.perPageRow {
	display: flex;
	justify-content: space-between;
	gap: 8px;
	font-size: 11px;
}

@media (max-width: 1400px) {
	.infoBadgeText {
		display: none;
	}

	.infoBadgeBtn {
		padding: 4px 6px;
		border-radius: 50%;
	}
}
</style>
