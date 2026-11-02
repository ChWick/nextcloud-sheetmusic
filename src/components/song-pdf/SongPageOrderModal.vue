<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import FormatListNumbered from 'vue-material-design-icons/FormatListNumbered.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import MagnifyPlus from 'vue-material-design-icons/MagnifyPlus.vue'
import MagnifyMinus from 'vue-material-design-icons/MagnifyMinus.vue'
import { apiPost } from '../../api/client'
import { describeRequestFailure } from '../../utils/api'
import { getFileName } from '../../utils/file'
import {
	formatPageOrder,
	generatePresetOrder,
	validatePageOrder,
} from '../../utils/page-order'
import type { LibraryData, SongFile } from '../../types/song'
import SongPageOrderPresets from './SongPageOrderPresets.vue'
import SongPageOrderGrid from './SongPageOrderGrid.vue'
import SongPageOrderSequence from './SongPageOrderSequence.vue'
import SongPageOrderZoomModal from './SongPageOrderZoomModal.vue'
import { usePageOrderThumbnails } from './usePageOrderThumbnails'

const props = defineProps<{
	open: boolean
	file: SongFile | null
	songId: number
	libraryId: number
	pdf?: any
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	'saved': [data: LibraryData, updatedFile: SongFile]
}>()

const pageOrderInput = ref('')
const isSaving = ref(false)
const errorMessage = ref('')

// Card width / zoom for parallel preview grid
const cardWidth = ref(240)
const MIN_CARD_WIDTH = 160
const MAX_CARD_WIDTH = 420

function zoomIn() {
	cardWidth.value = Math.min(MAX_CARD_WIDTH, cardWidth.value + 40)
}

function zoomOut() {
	cardWidth.value = Math.max(MIN_CARD_WIDTH, cardWidth.value - 40)
}

function resetZoom() {
	cardWidth.value = 240
}

// Zoom / Lightbox modal state for individual page inspection
const isZoomModalOpen = ref(false)
const zoomPageNum = ref(1)

function openPageZoom(pageNum: number) {
	zoomPageNum.value = pageNum
	isZoomModalOpen.value = true
}

function prevZoomPage() {
	if (zoomPageNum.value > 1) {
		zoomPageNum.value--
	}
}

function nextZoomPage() {
	if (zoomPageNum.value < totalPages.value) {
		zoomPageNum.value++
	}
}

const fileName = computed(() => (props.file ? getFileName(props.file.path) : ''))
const totalPages = computed(() => Math.max(1, props.file?.pageCount ?? 1))
const filePath = computed(() => props.file?.path ?? '')
const pdfRef = computed(() => props.pdf ?? null)

// Thumbnails composable
const { thumbnails, isLoading: isLoadingThumbnails } = usePageOrderThumbnails(
	pdfRef,
	filePath,
	totalPages,
)

// Initialize input value when modal opens or file changes
watch(
	() => [props.open, props.file],
	() => {
		if (props.open && props.file) {
			errorMessage.value = ''
			const current = props.file.pageOrder?.trim()
			if (current) {
				pageOrderInput.value = current
			} else {
				pageOrderInput.value = generatePresetOrder('all', totalPages.value)
			}
		}
	},
	{ immediate: true },
)

const validation = computed(() => validatePageOrder(pageOrderInput.value, totalPages.value))
const isValid = computed(() => validation.value.valid)
const pageSequence = computed(() => validation.value.pages)

const pageOccurrences = computed<Record<number, number>>(() => {
	const counts: Record<number, number> = {}
	for (const p of pageSequence.value) {
		counts[p] = (counts[p] ?? 0) + 1
	}
	return counts
})

function applyPreset(preset: 'all' | 'reverse' | 'repeat') {
	pageOrderInput.value = generatePresetOrder(preset, totalPages.value)
}

function appendPage(pageNum: number) {
	if (!isValid.value) {
		pageOrderInput.value = `${pageNum}`
		return
	}
	const updated = [...pageSequence.value, pageNum]
	pageOrderInput.value = formatPageOrder(updated)
}

function removeSequenceItem(index: number) {
	if (pageSequence.value.length <= 1) return
	const updated = [...pageSequence.value]
	updated.splice(index, 1)
	pageOrderInput.value = formatPageOrder(updated)
}

async function handleSave() {
	if (!props.file || !isValid.value || isSaving.value) return

	isSaving.value = true
	errorMessage.value = ''

	try {
		const orderToSave = pageOrderInput.value.trim()
		const response = await apiPost(
			'/api/songs/{id}/file/{fileId}/page-order',
			{
				libraryId: props.libraryId,
				pageOrder: orderToSave,
			},
			{
				pathParams: {
					id: props.songId,
					fileId: props.file.id,
				},
			},
		)

		const libraryData = response.data.ocs.data as LibraryData
		const updatedFile: SongFile = {
			...props.file,
			pageOrder: orderToSave,
		}

		showSuccess(t('sheetmusic', 'Page order saved.'))
		emit('saved', libraryData, updatedFile)
		emit('update:open', false)
	} catch (error) {
		const desc = describeRequestFailure(error, t('sheetmusic', 'Failed to save page order.'))
		errorMessage.value = desc
		showError(desc)
	} finally {
		isSaving.value = false
	}
}
</script>

<template>
	<NcDialog
		v-if="open && file"
		:open="open"
		:name="t('sheetmusic', 'Edit Page Order')"
		size="large"
		:class="$style.largeDialog"
		@update:open="emit('update:open', $event)">
		<template #actions>
			<NcButton
				type="button"
				variant="secondary"
				:disabled="isSaving"
				@click="emit('update:open', false)">
				<template #icon>
					<Close :size="20" />
				</template>
				{{ t('sheetmusic', 'Cancel') }}
			</NcButton>
			<NcButton
				type="button"
				variant="primary"
				:disabled="!isValid || isSaving"
				@click="handleSave">
				<template #icon>
					<NcLoadingIcon v-if="isSaving" :size="20" />
					<Check v-else :size="20" />
				</template>
				{{ isSaving ? t('sheetmusic', 'Saving…') : t('sheetmusic', 'Save Page Order') }}
			</NcButton>
		</template>

		<div :class="$style.dialogContent">
			<!-- Top controls header -->
			<div :class="$style.topControls">
				<!-- File information & total pages -->
				<div :class="$style.fileHeader">
					<FormatListNumbered :size="24" :class="$style.headerIcon" />
					<div :class="$style.headerMeta">
						<span :class="$style.fileName">{{ fileName }}</span>
						<span :class="$style.fileDetails">
							{{ t('sheetmusic', 'Document has {count} page | Document has {count} pages', { count: totalPages }, totalPages) }}
						</span>
					</div>
				</div>

				<!-- Zoom controls for parallel grid -->
				<div :class="$style.zoomControls">
					<span :class="$style.zoomLabel">{{ t('sheetmusic', 'Preview Size:') }}</span>
					<NcButton
						size="small"
						variant="secondary"
						:disabled="cardWidth <= MIN_CARD_WIDTH"
						:title="t('sheetmusic', 'Smaller preview')"
						:aria-label="t('sheetmusic', 'Smaller preview')"
						@click="zoomOut">
						<template #icon>
							<MagnifyMinus :size="16" />
						</template>
					</NcButton>
					<NcButton
						size="small"
						variant="secondary"
						:title="t('sheetmusic', 'Reset preview size')"
						:aria-label="t('sheetmusic', 'Reset preview size')"
						@click="resetZoom">
						{{ cardWidth }}px
					</NcButton>
					<NcButton
						size="small"
						variant="secondary"
						:disabled="cardWidth >= MAX_CARD_WIDTH"
						:title="t('sheetmusic', 'Larger preview')"
						:aria-label="t('sheetmusic', 'Larger preview')"
						@click="zoomIn">
						<template #icon>
							<MagnifyPlus :size="16" />
						</template>
					</NcButton>
				</div>
			</div>

			<!-- Page order input & presets bar -->
			<div :class="$style.specificationRow">
				<div :class="$style.inputSection">
					<NcTextField
						v-model="pageOrderInput"
						:label="t('sheetmusic', 'Page Order Specification')"
						:placeholder="t('sheetmusic', 'e.g. 1-3, 1-2')"
						:disabled="isSaving"
						:error="!isValid && Boolean(validation.error)"
						:error-message="validation.error"
						autofocus />
					<p :class="$style.inputHint">
						{{ t('sheetmusic', 'Specify page numbers or ranges (e.g. "1-4", "1, 2, 4", "1-2, 1-2"). Pages will be displayed in this order.') }}
					</p>
				</div>
				<SongPageOrderPresets
					:total-pages="totalPages"
					:disabled="isSaving"
					@select="applyPreset" />
			</div>

			<!-- Parallel Page Previews Grid (Large parallel cards with zoom option) -->
			<div :class="$style.gridSection">
				<div :class="$style.gridSectionHeader">
					<span :class="$style.sectionLabel">{{ t('sheetmusic', 'Pages Preview (Click to add to order):') }}</span>
					<NcLoadingIcon v-if="isLoadingThumbnails" :size="16" />
				</div>
				<SongPageOrderGrid
					:total-pages="totalPages"
					:page-thumbnails="thumbnails"
					:page-occurrences="pageOccurrences"
					:card-width="cardWidth"
					:disabled="isSaving"
					@append="appendPage"
					@zoom="openPageZoom" />
			</div>

			<!-- Resulting Page Sequence Preview -->
			<SongPageOrderSequence
				v-if="isValid"
				:pages="pageSequence"
				:disabled="isSaving"
				@remove="removeSequenceItem" />

			<p v-if="errorMessage" :class="$style.errorText" role="alert">
				{{ errorMessage }}
			</p>
		</div>

		<!-- Zoom Lightbox Modal for Full Page Inspection -->
		<SongPageOrderZoomModal
			v-model:open="isZoomModalOpen"
			:page-num="zoomPageNum"
			:total-pages="totalPages"
			:thumbnail-url="thumbnails[zoomPageNum]"
			@prev="prevZoomPage"
			@next="nextZoomPage"
			@append="appendPage" />
	</NcDialog>
</template>

<style module>
.largeDialog :global(.modal-container) {
	width: 94vw !important;
	max-width: 1440px !important;
	height: 88vh !important;
	max-height: 920px !important;
}

.dialogContent {
	display: flex;
	flex-direction: column;
	gap: 14px;
	padding: 4px 0;
	min-width: 0;
	height: 100%;
	box-sizing: border-box;
}

.topControls {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	flex-wrap: wrap;
}

.fileHeader {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 8px 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius, 4px);
	flex: 1 1 auto;
	min-width: 0;
}

.headerIcon {
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.headerMeta {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.fileName {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.fileDetails {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.zoomControls {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-shrink: 0;
}

.zoomLabel {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin-right: 2px;
}

.specificationRow {
	display: flex;
	align-items: flex-start;
	gap: 20px;
	flex-wrap: wrap;
}

.inputSection {
	flex: 1 1 320px;
	min-width: 260px;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.inputHint {
	font-size: 0.78rem;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.gridSection {
	display: flex;
	flex-direction: column;
	gap: 6px;
	flex: 1 1 auto;
	min-height: 240px;
}

.gridSectionHeader {
	display: flex;
	align-items: center;
	gap: 8px;
}

.sectionLabel {
	font-weight: 600;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.errorText {
	color: var(--color-error);
	font-size: 0.85rem;
	margin: 0;
}
</style>
