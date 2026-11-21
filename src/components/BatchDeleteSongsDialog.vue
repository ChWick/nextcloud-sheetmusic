<script setup lang="ts">
import { ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { showError } from '@nextcloud/dialogs'
import ConfirmDeleteDialog from './ConfirmDeleteDialog.vue'
import type { LibraryData } from '../types/song'
import { apiPost } from '../api/client'
import { describeRequestFailure } from '../utils/api'

const props = defineProps<{
	open: boolean
	libraryId: number
	songIds: number[]
}>()

const emit = defineEmits<{
	(e: 'update:open', open: boolean): void
	(e: 'deleted', data: LibraryData | undefined, songIds: number[]): void
}>()

const deleteFiles = ref(true)
const isDeleting = ref(false)
const deleteError = ref('')

watch(
	() => props.open,
	(isOpen) => {
		if (isOpen) {
			deleteFiles.value = true
			deleteError.value = ''
		}
	},
)

async function confirmBatchDelete() {
	if (props.songIds.length === 0 || props.libraryId < 0 || isDeleting.value) return
	isDeleting.value = true
	deleteError.value = ''

	try {
		const response = await apiPost('/api/songs-batch-delete', {
			libraryId: props.libraryId,
			songIds: props.songIds,
			deleteFiles: deleteFiles.value,
		})
		emit('update:open', false)
		const data = response.data?.ocs?.data as LibraryData | undefined
		emit('deleted', data, [...props.songIds])
	} catch (error) {
		deleteError.value = describeRequestFailure(error, t('sheetmusic', 'Failed to delete selected songs.'))
		showError(deleteError.value)
	} finally {
		isDeleting.value = false
	}
}
</script>

<template>
	<ConfirmDeleteDialog
		:open="open"
		:title="t('sheetmusic', 'Delete selected songs')"
		:confirm-label="t('sheetmusic', 'Delete {count} songs', { count: songIds.length }, { escape: false })"
		:is-deleting="isDeleting"
		:error="deleteError"
		@close="emit('update:open', false)"
		@confirm="confirmBatchDelete">
		<p>{{ t('sheetmusic', 'Delete {count} selected songs from this library?', { count: songIds.length }, { escape: false }) }}</p>
		<p>{{ t('sheetmusic', 'The songs and everything pointing at them are removed from the MobileSheets database.') }}</p>
		<NcCheckboxRadioSwitch
			v-model="deleteFiles"
			:disabled="isDeleting"
			type="checkbox">
			{{ t('sheetmusic', 'Delete all attached files') }}
		</NcCheckboxRadioSwitch>
		<p v-if="deleteFiles">
			{{ t('sheetmusic', 'The attached file(s) are moved to the Nextcloud trash bin, so they can be restored from there.') }}
		</p>
		<p v-else>
			{{ t('sheetmusic', 'The attached file(s) are kept in your Nextcloud files.') }}
		</p>
	</ConfirmDeleteDialog>
</template>
