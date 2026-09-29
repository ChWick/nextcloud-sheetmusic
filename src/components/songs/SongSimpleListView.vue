<script setup lang="ts">
import type { EntityType, FilterEntity, Song } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'

defineProps<{
	songs: Song[]
	isFiltered?: (entityType: EntityType, id: number) => boolean
}>()

const emit = defineEmits<{
	'view-song': [song: Song]
	'edit-song': [song: Song]
	'filter-entity': [entityType: EntityType, entity: FilterEntity]
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
					<template v-if="song.composers && song.composers.length > 0">
						<template v-for="(c, idx) in song.composers" :key="c.id">
							<button
								type="button"
								:class="[$style.filterChip, isFiltered?.('composers', c.id) && $style.activeChip]"
								:title="t('mobilesheetsmanager', 'Toggle filter for composer {name}', { name: c.name })"
								@click.stop="emit('filter-entity', 'composers', c)">
								{{ c.name }}
							</button>
							<template v-if="idx < song.composers.length - 1">, </template>
						</template>
					</template>
					<template v-if="song.composers?.length && song.books?.length"> • </template>
					<template v-if="song.books && song.books.length > 0">
						<template v-for="(b, idx) in song.books" :key="b.id">
							<button
								type="button"
								:class="[$style.filterChip, isFiltered?.('books', b.id) && $style.activeChip]"
								:title="t('mobilesheetsmanager', 'Toggle filter for book {title}', { title: b.title })"
								@click.stop="emit('filter-entity', 'books', b)">
								{{ b.title }}
							</button>
							<template v-if="idx < song.books.length - 1">, </template>
						</template>
					</template>
					<template v-if="(song.composers?.length || song.books?.length) && song.collections?.length"> • </template>
					<template v-if="song.collections && song.collections.length > 0">
						<template v-for="(col, idx) in song.collections" :key="col.id">
							<button
								type="button"
								:class="[$style.filterChip, isFiltered?.('collections', col.id) && $style.activeChip]"
								:title="t('mobilesheetsmanager', 'Toggle filter for collection {name}', { name: col.name })"
								@click.stop="emit('filter-entity', 'collections', col)">
								{{ col.name }}
							</button>
							<template v-if="idx < song.collections.length - 1">, </template>
						</template>
					</template>
					<template v-if="(song.composers?.length || song.books?.length || song.collections?.length) && song.artists?.length"> • </template>
					<template v-if="song.artists && song.artists.length > 0">
						<template v-for="(art, idx) in song.artists" :key="art.id">
							<button
								type="button"
								:class="[$style.filterChip, isFiltered?.('artists', art.id) && $style.activeChip]"
								:title="t('mobilesheetsmanager', 'Toggle filter for artist {name}', { name: art.name })"
								@click.stop="emit('filter-entity', 'artists', art)">
								{{ art.name }}
							</button>
							<template v-if="idx < song.artists.length - 1">, </template>
						</template>
					</template>
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
	display: inline;
}

.filterChip {
	appearance: none;
	-webkit-appearance: none;
	background: transparent !important;
	border: none !important;
	border-radius: 0 !important;
	box-shadow: none !important;
	outline: none !important;
	padding: 0 !important;
	margin: 0 !important;
	font: inherit;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	text-align: left;
	display: inline;
	text-decoration: none;
	transition: color 0.15s ease;
}

.filterChip:hover,
.filterChip:focus,
.filterChip:active {
	background: transparent !important;
	border: none !important;
	box-shadow: none !important;
	color: var(--color-primary-element) !important;
	text-decoration: underline;
}

.activeChip {
	color: var(--color-primary-element) !important;
	font-weight: 600;
	text-decoration: underline;
}

.activeChip:hover,
.activeChip:focus,
.activeChip:active {
	background: transparent !important;
	border: none !important;
	box-shadow: none !important;
	color: var(--color-primary-element-hover, var(--color-primary-element)) !important;
}

.actions {
	display: flex;
	gap: 8px;
}
</style>
