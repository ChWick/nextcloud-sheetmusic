<script setup lang="ts">
import type { Song } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { formatArtists, formatBooks, formatCollections, formatComposers } from '../../utils/song-formatter'

defineProps<{
	songs: Song[]
}>()

const emit = defineEmits<{
	'view-song': [song: Song]
	'edit-song': [song: Song]
}>()
</script>

<template>
	<div :class="$style.cardGrid">
		<div v-for="song in songs" :key="song.id" :class="$style.songCard">
			<div :class="$style.cardHeader">
				<button
					type="button"
					:class="$style.cardTitleBtn"
					:title="t('mobilesheetsmanager', 'View this song')"
					@click="emit('view-song', song)">
					{{ song.title }}
				</button>
			</div>

			<div :class="$style.cardBody">
				<div v-if="song.composers && song.composers.length > 0" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Composer:') }}</span>
					<span :class="$style.metaValue">{{ formatComposers(song) }}</span>
				</div>
				<div v-if="song.books && song.books.length > 0" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Book:') }}</span>
					<span :class="$style.metaValue">{{ formatBooks(song) }}</span>
				</div>
				<div v-if="song.collections && song.collections.length > 0" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Collection:') }}</span>
					<span :class="$style.metaValue">{{ formatCollections(song) }}</span>
				</div>
				<div v-if="song.artists && song.artists.length > 0" :class="$style.metaRow">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Artist:') }}</span>
					<span :class="$style.metaValue">{{ formatArtists(song) }}</span>
				</div>
			</div>

			<div :class="$style.cardActions">
				<NcButton size="small" @click="emit('edit-song', song)">
					{{ t('mobilesheetsmanager', 'Edit') }}
				</NcButton>
				<NcButton size="small" @click="emit('view-song', song)">
					{{ t('mobilesheetsmanager', 'View') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<style module>
.cardGrid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: 16px;
}

.songCard {
	display: flex;
	flex-direction: column;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 16px;
	gap: 12px;
}

.songCard:hover {
	border-color: var(--color-primary-element);
}

.cardHeader {
	font-weight: 600;
	font-size: 1.05rem;
}

.cardTitleBtn {
	background: none;
	border: none;
	padding: 0;
	font: inherit;
	color: var(--color-main-text);
	cursor: pointer;
	text-align: left;
}

.cardTitleBtn:hover {
	color: var(--color-primary-element);
}

.cardBody {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 0.85rem;
	flex-grow: 1;
}

.metaRow {
	display: flex;
	gap: 6px;
}

.metaLabel {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.metaValue {
	color: var(--color-main-text);
}

.cardActions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}
</style>
