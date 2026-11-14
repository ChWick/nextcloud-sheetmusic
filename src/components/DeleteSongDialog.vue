<script setup lang="ts">
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { showError } from '@nextcloud/dialogs'
import type { LibraryData, Song } from '../types/song'
import { apiDelete } from '../api/client'
import { describeRequestFailure } from '../utils/api'

const props = defineProps<{
	open: boolean
	song: Song | null
	libraryId: number
	fileCount?: number
}>()

const emit = defineEmits<{
	(e: 'update:open', open: boolean): void
	(e: 'deleted', data: LibraryData | undefined, songId: number): void
}>()

const deleteFiles = ref(true)
const isDeletingSong = ref(false)
const deleteError = ref('')

async function confirmDeleteSong() {
	if (!props.song || props.libraryId < 0) return
	const songId = props.song.id
	isDeletingSong.value = true
	deleteError.value = ''

	try {
		const response = await apiDelete('/api/songs/{id}', {
			pathParams: { id: songId },
			queryParams: { libraryId: props.libraryId, deleteFiles: deleteFiles.value },
		})
		emit('update:open', false)
		const data = response.data?.ocs?.data as LibraryData | undefined
		emit('deleted', data, songId)
	} catch (error) {
		deleteError.value = describeRequestFailure(error, t('sheetmusic', 'Failed to delete the song.'))
		showError(deleteError.value)
	} finally {
		isDeletingSong.value = false
	}
}

function handleClose() {
	if (isDeletingSong.value) return
	emit('update:open', false)
}
</script>

<template>
	<NcDialog
		v-if="open"
		:name="t('sheetmusic', 'Delete song')"
		size="normal"
		@close="handleClose">
		<p>{{ t('sheetmusic', 'Delete "{title}" from this library?', { title: song?.title ?? '' }, { escape: false }) }}</p>
		<p>{{ t('sheetmusic', 'The song and everything pointing at it is removed from the MobileSheets database.') }}</p>
		<NcCheckboxRadioSwitch v-model="deleteFiles" type="checkbox">
			{{ t('sheetmusic', 'Delete all attached files') }}
		</NcCheckboxRadioSwitch>
		<p v-if="deleteFiles">
			{{ t('sheetmusic', 'The {count} attached file(s) are moved to the Nextcloud trash bin, so they can be restored from there.', { count: fileCount ?? song?.files?.length ?? 0 }, { escape: false }) }}
		</p>
		<p v-else>
			{{ t('sheetmusic', 'The {count} attached file(s) are kept in your Nextcloud files.', { count: fileCount ?? song?.files?.length ?? 0 }, { escape: false }) }}
		</p>
		<p v-if="deleteError" :class="$style.error" role="alert">{{ deleteError }}</p>
		<template #actions>
			<NcButton @click="handleClose">
				{{ t('sheetmusic', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="error"
				:disabled="isDeletingSong"
				@click="confirmDeleteSong">
				<template v-if="isDeletingSong">
					<NcLoadingIcon :size="16" />
				</template>
				{{ t('sheetmusic', 'Delete song') }}
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
