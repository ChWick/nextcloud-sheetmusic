<script setup lang="ts">
import type { Song } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { formatBooks, formatComposers } from '../../utils/song-formatter'

defineProps<{
	songs: Song[]
}>()

const emit = defineEmits<{
	'view-song': [song: Song]
	'edit-song': [song: Song]
}>()
</script>

<template>
	<ul :class="$style.songList">
		<li v-for="song in songs" :key="song.id" :class="$style.songRow">
			<div :class="$style.songInfo">
				<button
					type="button"
					:class="$style.songButton"
					:title="t('mobilesheetsmanager', 'View this song')"
					@click="emit('view-song', song)">
					{{ song.title }}
				</button>
				<span :class="$style.subtitle">
					{{ formatComposers(song, '') }}
					<template v-if="formatComposers(song, '') && formatBooks(song, '')"> • </template>
					{{ formatBooks(song, '') }}
				</span>
			</div>
			<div :class="$style.actions">
				<NcButton size="small" @click="emit('edit-song', song)">
					{{ t('mobilesheetsmanager', 'Edit') }}
				</NcButton>
				<NcButton size="small" @click="emit('view-song', song)">
					{{ t('mobilesheetsmanager', 'View') }}
				</NcButton>
			</div>
		</li>
	</ul>
</template>

<style module>
.songList {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.songRow {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
}

.songRow:hover {
	background-color: var(--color-background-hover);
}

.songInfo {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.songButton {
	background: none;
	border: none;
	padding: 0;
	font-weight: 600;
	font-size: 0.95rem;
	color: var(--color-main-text);
	cursor: pointer;
	text-align: left;
}

.songButton:hover {
	color: var(--color-primary-element);
}

.subtitle {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.actions {
	display: flex;
	gap: 8px;
}
</style>
