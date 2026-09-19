<template>
  <div :class="$style.filesArea">
    {{ t('mobilesheetsmanager', 'Files view placeholder') }}
    <button type="button" @click="regenerateFilePaths">{{ t('mobilesheetsmanager', 'Regenerate File Paths')}}</button>
  </div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n';
import axios from '@nextcloud/axios';
import { generateOcsUrl } from '@nextcloud/router';
import type { LibraryData, Song } from '../types/song';

const props = defineProps<{
	selectedLibrary: string;
}>();

async function regenerateFilePaths() {
	try {
		const response = await axios.get(generateOcsUrl('/apps/mobilesheetsmanager/api/songs'), {
			params: { path: props.selectedLibrary },
		});

		const data = response.data.ocs.data as LibraryData;
		console.log('Regenerated Library Data:');
		console.log(`Songs: ${data.songs.length}`);
		console.log(`Composers: ${data.composers.length}`);
		console.log(`Books: ${data.books.length}`);
		console.log(`Collections: ${data.collections.length}`);
    console.log(`Artists: ${data.artists.length}`);

		data.songs.forEach((song: Song) => {
			console.log(`Song: ${song.title}, Files:`, song.files);
		});
	} catch (error) {
		console.error('Failed to regenerate file paths:', error);
	}
}
</script>

<style module>
.filesArea {
  display: grid;
  grid-template-columns: minmax(220px, 320px) minmax(0, 1fr);
  gap: 24px;
  align-items: start;
  padding: 16px;
}
</style>
