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
import SongPageOrderPresets from './SongPageOrderPresets.vue'
import SongPageOrderPalette from './SongPageOrderPalette.vue'
import SongPageOrderSequence from './SongPageOrderSequence.vue'

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
			<SongPageOrderPresets
				:total-pages="totalPages"
				:disabled="isSaving"
				@select="applyPreset" />

			<!-- Clickable Page Number Palette -->
			<SongPageOrderPalette
				:total-pages="totalPages"
				:disabled="isSaving"
				@append="appendPage" />

			<!-- Resulting Page Sequence Preview -->
			<SongPageOrderSequence
				v-if="isValid"
				:pages="pageSequence"
				:disabled="isSaving"
				@remove="removeSequenceItem" />

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
}

.fileName {
	font-weight: 600;
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

.helpNotice {
	display: flex;
	gap: 10px;
	padding: 10px 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius, 4px);
	font-size: 0.85rem;
	line-height: 1.4;
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
}

.errorText {
	color: var(--color-error);
	font-size: 0.85rem;
	margin: 0;
}
</style>
