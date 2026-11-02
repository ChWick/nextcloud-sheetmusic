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
import Restore from 'vue-material-design-icons/Restore.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import { apiPost } from '../../api/client'
import { describeRequestFailure } from '../../utils/api'
import { getFileName } from '../../utils/file'
import {
	formatPageOrder,
	generatePresetOrder,
	validatePageOrder,
} from '../../utils/page-order'
import type { LibraryData, SongFile } from '../../types/song'

const props = defineProps<{
	open: boolean
	file: SongFile | null
	songId: number
	libraryId: number
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	'saved': [data: LibraryData, updatedFile: SongFile]
}>()

const pageOrderInput = ref('')
const isSaving = ref(false)
const errorMessage = ref('')

const fileName = computed(() => (props.file ? getFileName(props.file.path) : ''))
const totalPages = computed(() => Math.max(1, props.file?.pageCount ?? 1))

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

		showSuccess(t('sheetmusic', 'Page order saved and synced to MobileSheets.'))
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
		size="normal"
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
			<!-- File information header -->
			<div :class="$style.fileHeader">
				<FormatListNumbered :size="28" :class="$style.headerIcon" />
				<div :class="$style.headerMeta">
					<span :class="$style.fileName">{{ fileName }}</span>
					<span :class="$style.fileDetails">
						{{ t('sheetmusic', 'Document has {count} page | Document has {count} pages', { count: totalPages }, totalPages) }}
					</span>
				</div>
			</div>

			<!-- Page order input -->
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
					{{ t('sheetmusic', 'Specify page numbers or ranges (e.g. "1-4", "1, 2, 4", "1-2, 1-2"). MobileSheets uses this order for display.') }}
				</p>
			</div>

			<!-- Preset actions -->
			<div :class="$style.presetsSection">
				<span :class="$style.sectionLabel">{{ t('sheetmusic', 'Presets:') }}</span>
				<div :class="$style.presetButtons">
					<NcButton
						size="small"
						variant="tertiary"
						:disabled="isSaving"
						@click="applyPreset('all')">
						<template #icon>
							<Restore :size="16" />
						</template>
						{{ t('sheetmusic', 'All Pages (1-{count})', { count: totalPages }) }}
					</NcButton>
					<NcButton
						size="small"
						variant="tertiary"
						:disabled="isSaving"
						@click="applyPreset('repeat')">
						{{ t('sheetmusic', 'Repeat All (1-{count}, 1-{count})', { count: totalPages }) }}
					</NcButton>
					<NcButton
						v-if="totalPages > 1"
						size="small"
						variant="tertiary"
						:disabled="isSaving"
						@click="applyPreset('reverse')">
						{{ t('sheetmusic', 'Reverse Order') }}
					</NcButton>
				</div>
			</div>

			<!-- Clickable Page Number Palette -->
			<div v-if="totalPages > 1" :class="$style.paletteSection">
				<span :class="$style.sectionLabel">{{ t('sheetmusic', 'Add Page to Sequence:') }}</span>
				<div :class="$style.paletteGrid">
					<button
						v-for="p in totalPages"
						:key="`palette-page-${p}`"
						type="button"
						:class="$style.paletteChip"
						:title="t('sheetmusic', 'Append Page {page} to order', { page: p })"
						@click="appendPage(p)">
						+ {{ t('sheetmusic', 'Page {p}', { p }) }}
					</button>
				</div>
			</div>

			<!-- Resulting Page Sequence Preview -->
			<div v-if="isValid && pageSequence.length > 0" :class="$style.previewSection">
				<div :class="$style.previewHeader">
					<span :class="$style.sectionLabel">{{ t('sheetmusic', 'Resulting Page Order:') }}</span>
					<span :class="$style.previewCountBadge">
						{{ t('sheetmusic', '{count} page in sequence | {count} pages in sequence', { count: pageSequence.length }, pageSequence.length) }}
					</span>
				</div>
				<div :class="$style.pageChipsList">
					<div
						v-for="(page, idx) in pageSequence"
						:key="`seq-${idx}-${page}`"
						:class="$style.pageChip">
						<span :class="$style.chipIndex">{{ idx + 1 }}</span>
						<span :class="$style.chipLabel">{{ t('sheetmusic', 'Page {p}', { p: page }) }}</span>
						<button
							type="button"
							:class="$style.chipRemoveBtn"
							:title="t('sheetmusic', 'Remove from sequence')"
							:aria-label="t('sheetmusic', 'Remove position {pos}', { pos: idx + 1 })"
							@click="removeSequenceItem(idx)">
							&times;
						</button>
					</div>
				</div>
			</div>

			<!-- MobileSheets format guidance notice -->
			<div :class="$style.helpNotice">
				<InformationOutline :size="20" :class="$style.helpIcon" />
				<div :class="$style.helpText">
					<strong>{{ t('sheetmusic', 'MobileSheets Compatibility:') }}</strong>
					<span>
						{{ t('sheetmusic', 'This page ordering is synced directly to MobileSheets. It allows repeating pages for repeats or skipping unneeded pages without altering the underlying PDF file.') }}
					</span>
				</div>
			</div>

			<p v-if="errorMessage" :class="$style.errorText" role="alert">
				{{ errorMessage }}
			</p>
		</div>
	</NcDialog>
</template>

<style module>
.dialogContent {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 4px 0;
	min-width: 0;
	box-sizing: border-box;
}

.fileHeader {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius, 4px);
}

.headerIcon {
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.headerMeta {
	display: flex;
	flex-direction: column;
	min-width: 0;
	overflow: hidden;
}

.fileName {
	font-weight: 600;
	font-size: 1rem;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.fileDetails {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.inputSection {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.inputHint {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.presetsSection {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.sectionLabel {
	font-weight: 600;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.presetButtons {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.paletteSection {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.paletteGrid {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	max-height: 120px;
	overflow-y: auto;
}

.paletteChip {
	display: inline-flex;
	align-items: center;
	padding: 4px 10px;
	font-size: 0.85rem;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	border-radius: var(--border-radius-pill, 16px);
	cursor: pointer;
	transition: background-color 0.15s ease;
}

.paletteChip:hover {
	background: var(--color-background-hover);
	border-color: var(--color-primary-element);
}

.previewSection {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius, 4px);
}

.previewHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.previewCountBadge {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.pageChipsList {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	max-height: 140px;
	overflow-y: auto;
	padding: 2px;
}

.pageChip {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 4px 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	font-size: 0.85rem;
}

.chipIndex {
	font-size: 0.75rem;
	font-weight: 700;
	color: var(--color-primary-element);
	background: var(--color-background-hover);
	padding: 1px 4px;
	border-radius: 2px;
}

.chipLabel {
	font-weight: 500;
}

.chipRemoveBtn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 18px;
	height: 18px;
	border: none;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	cursor: pointer;
	border-radius: 50%;
	line-height: 1;
}

.chipRemoveBtn:hover {
	background: var(--color-background-darker);
	color: var(--color-error);
}

.helpNotice {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 10px 12px;
	background-color: var(--color-background-hover);
	border-left: 3px solid var(--color-primary-element);
	border-radius: 0 var(--border-radius, 4px) var(--border-radius, 4px) 0;
}

.helpIcon {
	color: var(--color-primary-element);
	flex-shrink: 0;
	margin-top: 1px;
}

.helpText {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	line-height: 1.35;
}

.errorText {
	color: var(--color-error);
	font-size: 0.85rem;
	margin: 0;
}
</style>
