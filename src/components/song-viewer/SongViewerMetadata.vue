<template>
  <div v-if="hasMetadata" :class="$style.metadata">
    <div v-if="composers.length > 0" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Composer:') }}</span>
      <span>{{ composers.map((c) => c.name).join(', ') }}</span>
    </div>
    <div v-if="books.length > 0" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Book:') }}</span>
      <span>{{ books.map((b) => b.title ?? b.name).join(', ') }}</span>
    </div>
    <div v-if="collections.length > 0" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Collection:') }}</span>
      <span>{{ collections.map((c) => c.name).join(', ') }}</span>
    </div>
    <div v-if="artists.length > 0" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Artist:') }}</span>
      <span>{{ artists.map((a) => a.name).join(', ') }}</span>
    </div>
    <div v-if="genres.length > 0" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Genre:') }}</span>
      <span>{{ genres.map((g) => g.name).join(', ') }}</span>
    </div>
    <div v-if="keys.length > 0" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Key:') }}</span>
      <span>{{ keys.map((k) => k.name).join(', ') }}</span>
    </div>
    <div v-if="customGroups.length > 0" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Custom Group:') }}</span>
      <span>{{ customGroups.map((cg) => cg.name).join(', ') }}</span>
    </div>
    <div v-if="years.length > 0" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Year:') }}</span>
      <span>{{ years.map((y) => y.name).join(', ') }}</span>
    </div>
    <div v-if="signatures.length > 0" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Time Signature:') }}</span>
      <span>{{ signatures.map((s) => s.name).join(', ') }}</span>
    </div>
    <div v-if="sourceTypes.length > 0" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Source Type:') }}</span>
      <span>{{ sourceTypes.map((st) => st.name).join(', ') }}</span>
    </div>
    <div v-if="instruments.length > 0" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Instrument:') }}</span>
      <span>{{ instruments.map((i) => i.name).join(', ') }}</span>
    </div>
    <div v-if="bookmarks.length > 0" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Bookmarks:') }}</span>
      <div :class="$style.metaLinkList">
        <button
          v-for="(bm, idx) in bookmarks"
          :key="bm.id ?? `meta-bm-${idx}`"
          type="button"
          :class="$style.metaLinkBtn"
          @click="emit('jump-to-bookmark', bm.pageNum)">
          {{ formatBookmarkLabel(bm) }}
        </button>
      </div>
    </div>
    <div v-if="audioFiles.length > 0" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Audio Files:') }}</span>
      <span>{{ audioFiles.map((a) => a.title || a.file).join(', ') }}</span>
    </div>
    <div v-if="song.opus" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Opus:') }}</span>
      <span>{{ song.opus }}</span>
    </div>
    <div v-if="song.catalogNumber" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Catalog No.:') }}</span>
      <span>{{ song.catalogNumber }}</span>
    </div>
    <div v-if="song.songNumber" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Song No.:') }}</span>
      <span>{{ song.songNumber }}</span>
    </div>
    <div v-if="song.stars" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Rating:') }}</span>
      <span>{{ formatStars(song.stars) }}</span>
    </div>
    <div v-if="song.difficulty" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Difficulty:') }}</span>
      <span>{{ formatDifficulty(song.difficulty) }}</span>
    </div>
    <div v-if="song.duration" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Duration:') }}</span>
      <span>{{ formatDuration(song.duration) }}</span>
    </div>
    <div v-if="song.keywords" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Keywords:') }}</span>
      <span>{{ song.keywords }}</span>
    </div>
    <div v-if="song.custom" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Custom:') }}</span>
      <span>{{ song.custom }}</span>
    </div>
    <div v-if="song.custom2" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Custom 2:') }}</span>
      <span>{{ song.custom2 }}</span>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import type { AudioFile, Bookmark, Entity, Song } from '../../types/song'
import { formatDifficulty, formatDuration, formatStars } from '../../utils/song-formatter'

const props = defineProps<{
  song: Song
  composers: Entity[]
  books: Entity[]
  collections: Entity[]
  artists: Entity[]
  genres: Entity[]
  keys: Entity[]
  customGroups: Entity[]
  years: Entity[]
  signatures: Entity[]
  sourceTypes: Entity[]
  instruments: Entity[]
  bookmarks: Bookmark[]
  audioFiles: AudioFile[]
}>()

const emit = defineEmits<{
  'jump-to-bookmark': [pageNum: number]
}>()

function formatBookmarkLabel(bm: Bookmark): string {
  if (bm.endPage && bm.endPage > bm.pageNum) {
    return `${bm.name} (pp. ${bm.pageNum}–${bm.endPage})`
  }
  return `${bm.name} (p. ${bm.pageNum})`
}

const hasMetadata = computed(() => {
  return props.composers.length > 0
    || props.books.length > 0
    || props.collections.length > 0
    || props.artists.length > 0
    || props.genres.length > 0
    || props.keys.length > 0
    || props.customGroups.length > 0
    || props.years.length > 0
    || props.signatures.length > 0
    || props.sourceTypes.length > 0
    || props.instruments.length > 0
    || props.bookmarks.length > 0
    || props.audioFiles.length > 0
    || !!props.song.opus
    || !!props.song.catalogNumber
    || !!props.song.songNumber
    || !!props.song.stars
    || !!props.song.difficulty
    || !!props.song.duration
    || !!props.song.keywords
    || !!props.song.custom
    || !!props.song.custom2
})
</script>

<style module>
.metadata {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 16px;
  padding: 8px 16px;
  background-color: var(--color-background-dark);
  font-size: 0.85rem;
  border-bottom: 1px solid var(--color-border);
}

.metaItem {
  display: flex;
  align-items: center;
  gap: 4px;
}

.metaLabel {
  font-weight: 600;
  color: var(--color-text-maxcontrast);
}

.metaLinkList {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.metaLinkBtn {
  background: none;
  border: none;
  padding: 0;
  color: var(--color-primary-element);
  cursor: pointer;
  font-size: inherit;
  text-decoration: underline;
}

.metaLinkBtn:hover {
  opacity: 0.8;
}
</style>
