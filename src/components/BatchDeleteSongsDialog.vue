<script setup lang="ts">
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { showError } from '@nextcloud/dialogs'
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
	(e: 'deleted', data: LibraryData | undefined, count: number): void
}>()

const deleteFiles = ref(true)
const isDeleting = ref(false)
const deleteError = ref('')

async function confirmBatchDelete() {
	if (props.songIds.length === 0 || props.libraryId < 0) return
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
		emit('deleted', data, props.songIds.length)
	} catch (error) {
		deleteError.value = describeRequestFailure(error, t('sheetmusic', 'Failed to delete selected songs.'))
		showError(deleteError.value)
	} finally {
		isDeleting.value = false
	}
}

function handleClose() {
	if (isDeleting.value) return
	emit('update:open', false)
}
</script>

<template>
	<NcDialog
		v-if="open"
		:name="t('sheetmusic', 'Delete selected songs')"
		size="normal"
		@close="handleClose">
		<p>{{ t('sheetmusic', 'Delete {count} selected songs from this library?', { count: songIds.length }) }}</p>
		<p>{{ t('sheetmusic', 'The songs and everything pointing at them are removed from the MobileSheets database.') }}</p>
		<NcCheckboxRadioSwitch v-model="deleteFiles" type="checkbox">
			{{ t('sheetmusic', 'Delete all attached files') }}
		</NcCheckboxRadioSwitch>
		<p v-if="deleteFiles">
			{{ t('sheetmusic', 'The attached file(s) are moved to the Nextcloud trash bin, so they can be restored from there.') }}
		</p>
		<p v-else>
			{{ t('sheetmusic', 'The attached file(s) are kept in your Nextcloud files.') }}
		</p>
		<p v-if="deleteError" :class="$style.error" role="alert">{{ deleteError }}</p>
		<template #actions>
			<NcButton @click="handleClose">
				{{ t('sheetmusic', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="error"
				:disabled="isDeleting"
				@click="confirmBatchDelete">
				<template v-if="isDeleting">
					<NcLoadingIcon :size="16" />
				</template>
				{{ t('sheetmusic', 'Delete {count} songs', { count: songIds.length }) }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style module>
.error {
	color: var(--color-error);
	margin: 8px 0;
}
</style>

