<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import DeleteSweepOutline from 'vue-material-design-icons/DeleteSweepOutline.vue'
import BackupRestore from 'vue-material-design-icons/BackupRestore.vue'
import ViewHeader from './ViewHeader.vue'
import type { LibraryData } from '../types/song'

interface DeletedSong {
	id: number
	uuid: string
	title: string
	composers: string[]
	fileCount: number
	deletedAt: number
	rating: number
}

const props = defineProps<{
	selectedLibrary: number
}>()

const emit = defineEmits<{
	(e: 'restored', data: LibraryData): void
}>()

const isLoading = ref(false)
const restoringId = ref<number | null>(null)
const deletedSongs = ref<DeletedSong[]>([])
const error = ref('')

async function fetchDeletedSongs() {
	if (props.selectedLibrary < 0) return
	isLoading.value = true
	error.value = ''
	try {
		const url = generateOcsUrl('/apps/sheetmusic/api/libraries/' + props.selectedLibrary + '/deleted-songs')
		const response = await axios.get(url)
		deletedSongs.value = response.data?.ocs?.data ?? []
	} catch (e: any) {
		error.value = e?.message || t('sheetmusic', 'Failed to load deleted songs')
	} finally {
		isLoading.value = false
	}
}

watch(
	() => props.selectedLibrary,
	() => {
		fetchDeletedSongs()
	},
	{ immediate: true },
)

function formatDate(sec: number): string {
	if (!sec) return ''
	return new Date(sec * 1000).toLocaleString()
}

async function restoreSong(song: DeletedSong) {
	restoringId.value = song.id
	try {
		const url = generateOcsUrl('/apps/sheetmusic/api/songs/' + song.id + '/restore')
		const response = await axios.post(url)
		const data = response.data?.ocs?.data as LibraryData
		deletedSongs.value = deletedSongs.value.filter((s) => s.id !== song.id)
		showSuccess(t('sheetmusic', 'Song "{title}" restored successfully', { title: song.title }))
		emit('restored', data)
	} catch (e: any) {
		showError(e?.response?.data?.ocs?.data?.error || t('sheetmusic', 'Failed to restore song'))
	} finally {
		restoringId.value = null
	}
}
</script>

<template>
	<div :class="$style.wrapper">
		<ViewHeader :title="t('sheetmusic', 'Trash / Deleted Songs')" />

		<div :class="$style.content">
			<div v-if="isLoading" :class="$style.loading">
				<NcLoadingIcon :size="32" />
				<p>{{ t('sheetmusic', 'Loading deleted songs...') }}</p>
			</div>

			<p v-else-if="error" :class="$style.error" role="alert">
				{{ error }}
			</p>

			<div v-else-if="deletedSongs.length === 0" :class="$style.empty">
				<DeleteSweepOutline :size="56" :class="$style.emptyIcon" />
				<h3>{{ t('sheetmusic', 'Trash is empty') }}</h3>
				<p>{{ t('sheetmusic', 'No deleted songs found in this library.') }}</p>
			</div>

			<div v-else :class="$style.listContainer">
				<table :class="$style.table">
					<thead>
						<tr>
							<th>{{ t('sheetmusic', 'Title') }}</th>
							<th>{{ t('sheetmusic', 'Composers') }}</th>
							<th>{{ t('sheetmusic', 'Files') }}</th>
							<th>{{ t('sheetmusic', 'Deleted Date') }}</th>
							<th :class="$style.actionsCol">{{ t('sheetmusic', 'Actions') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="song in deletedSongs" :key="song.id">
							<td :class="$style.titleCell">
								<strong>{{ song.title }}</strong>
							</td>
							<td>
								{{ song.composers.length ? song.composers.join(', ') : '—' }}
							</td>
							<td>
								{{ song.fileCount }}
							</td>
							<td>
								{{ formatDate(song.deletedAt) }}
							</td>
							<td :class="$style.actionsCol">
								<NcButton
									type="button"
									variant="primary"
									:disabled="restoringId === song.id"
									:title="t('sheetmusic', 'Restore song')"
									@click="restoreSong(song)">
									<template #icon>
										<BackupRestore :size="16" />
									</template>
									{{ t('sheetmusic', 'Restore') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
	</div>
</template>

<style module>
.wrapper {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.content {
	flex: 1;
	padding: 16px 24px;
	overflow-y: auto;
}

.loading,
.empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 60px 16px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.emptyIcon {
	margin-bottom: 16px;
	opacity: 0.4;
}

.error {
	color: var(--color-error);
	padding: 16px;
}

.listContainer {
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	overflow: hidden;
}

.table {
	width: 100%;
	border-collapse: collapse;
	text-align: left;
}

.table th,
.table td {
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
}

.table th {
	background-color: var(--color-background-hover);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.titleCell {
	color: var(--color-main-text);
}

.actionsCol {
	text-align: right;
}
</style>
