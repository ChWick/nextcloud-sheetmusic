<template>
  <div v-if="hasMetadata" :class="$style.metadata">
    <div v-for="item in entityItems" :key="item.key" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ item.label }}</span>
      <span>{{ item.value }}</span>
    </div>
    <div v-if="effectiveBookmarks.length > 0" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Bookmarks:') }}</span>
      <div :class="$style.metaLinkList">
        <button
          v-for="(bm, idx) in effectiveBookmarks"
          :key="bm.id ?? `meta-bm-${idx}`"
          type="button"
          :class="$style.metaLinkBtn"
          @click="emit('jump-to-bookmark', bm.pageNum)">
          {{ formatBookmarkLabel(bm) }}
        </button>
      </div>
    </div>
    <div v-if="effectiveAudioFiles.length > 0" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ t('sheetmusic', 'Audio Files:') }}</span>
      <span>{{ effectiveAudioFiles.map((a) => a.title || a.file).join(', ') }}</span>
    </div>
    <div v-for="item in scalarItems" :key="item.key" :class="$style.metaItem">
      <span :class="$style.metaLabel">{{ item.label }}</span>
      <span>{{ item.value }}</span>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import type { AudioFile, Bookmark, Song, SongEntityLists } from '../../types/song'
import { ENTITY_COLUMNS, SONG_SCALAR_FIELDS } from '../songs/song-view-columns'
import { formatBookmarkLabel } from '../../utils/song-formatter'

const props = defineProps<SongEntityLists & {
  song: Song
  bookmarks?: Bookmark[]
  audioFiles?: AudioFile[]
}>()

const emit = defineEmits<{
  'jump-to-bookmark': [pageNum: number]
}>()

const entityItems = computed(() =>
  ENTITY_COLUMNS.flatMap((col) => {
    const items = props[col.songProp] ?? col.getItems(props.song)
    if (!items || items.length === 0) return []
    const value = items.map((item: any) => item.title ?? item.name).filter(Boolean).join(', ')
    if (!value) return []
    return [{ key: col.songProp, label: col.cardLabel(), value }]
  }),
)

const effectiveBookmarks = computed(() => props.bookmarks ?? props.song.bookmarks ?? [])
const effectiveAudioFiles = computed(() => props.audioFiles ?? props.song.audioFiles ?? [])

const scalarItems = computed(() =>
  SONG_SCALAR_FIELDS.flatMap((field) => {
    const value = field.formatViewerValue(props.song)
    if (!value) return []
    return [{ key: field.key, label: field.cardLabel(), value }]
  }),
)

const hasMetadata = computed(() =>
  entityItems.value.length > 0
  || effectiveBookmarks.value.length > 0
  || effectiveAudioFiles.value.length > 0
  || scalarItems.value.length > 0,
)
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
