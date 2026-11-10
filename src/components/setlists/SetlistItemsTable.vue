<template>
  <div v-if="isLoading" :class="$style.loadingArea">
    <NcLoadingIcon :size="32" />
  </div>

  <div v-else-if="items.length === 0" :class="$style.emptyDetail">
    <PlaylistPlay :size="48" :class="$style.emptyDetailIcon" />
    <p>{{ t('sheetmusic', 'This setlist is currently empty.') }}</p>
    <p :class="$style.emptyDetailHint">
      {{ t('sheetmusic', 'Add songs or specific bookmarks (movements) to build your performance list.') }}
    </p>
    <NcButton v-if="canWrite !== false" variant="primary" @click="emit('add-item')">
      <template #icon>
        <PlusIcon :size="20" />
      </template>
      {{ t('sheetmusic', 'Add first item') }}
    </NcButton>
  </div>

  <div v-else :class="$style.itemsTableContainer">
    <table :class="$style.itemsTable">
      <thead>
        <tr>
          <th :class="$style.colPos">#</th>
          <th v-if="canWrite !== false" :class="$style.colOrder">{{ t('sheetmusic', 'Order') }}</th>
          <th :class="$style.colTitle">{{ t('sheetmusic', 'Song / Bookmark') }}</th>
          <th :class="$style.colDuration">{{ t('sheetmusic', 'Duration') }}</th>
          <th v-if="canWrite !== false" :class="$style.colActions">{{ t('sheetmusic', 'Actions') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="(item, index) in items"
          :key="item.id ?? index"
          :class="$style.itemRow">
          <td :class="$style.colPos">
            <span :class="$style.posBadge">{{ index + 1 }}</span>
          </td>
          <td v-if="canWrite !== false" :class="$style.colOrder">
            <div :class="$style.reorderButtons">
              <NcButton
                size="small"
                :disabled="index === 0"
                :title="t('sheetmusic', 'Move up')"
                :aria-label="t('sheetmusic', 'Move up')"
                @click="emit('move', index, index - 1)">
                <template #icon>
                  <ArrowUpIcon :size="16" />
                </template>
              </NcButton>
              <NcButton
                size="small"
                :disabled="index === items.length - 1"
                :title="t('sheetmusic', 'Move down')"
                :aria-label="t('sheetmusic', 'Move down')"
                @click="emit('move', index, index + 1)">
                <template #icon>
                  <ArrowDownIcon :size="16" />
                </template>
              </NcButton>
            </div>
          </td>
          <td :class="$style.colTitle">
            <div :class="$style.titleWrapper">
              <button
                type="button"
                :class="$style.songTitleBtn"
                :title="t('sheetmusic', 'Open in song viewer')"
                @click="emit('open-song', item)">
                {{ item.songTitle || t('sheetmusic', 'Untitled song') }}
              </button>

              <!-- Inline bookmark dropdown when song has bookmarks -->
              <div
                v-if="canWrite !== false && getSongBookmarks(item.songId).length > 0"
                :class="[$style.inlineBookmarkPicker, item.bookmarkId ? $style.hasBookmark : $style.noBookmark]">
                <BookmarkMusicOutline :size="14" :class="$style.bookmarkIcon" />
                <select
                  :value="item.bookmarkId ?? ''"
                  :class="$style.inlineBookmarkSelect"
                  :title="t('sheetmusic', 'Change bookmark')"
                  :aria-label="t('sheetmusic', 'Change bookmark for {song}', { song: item.songTitle || '' })"
                  @change="onBookmarkSelect(item, $event)">
                  <option value="">{{ t('sheetmusic', 'Entire song') }}</option>
                  <option
                    v-for="bm in getSongBookmarks(item.songId)"
                    :key="bm.id"
                    :value="bm.id">
                    {{ bm.name }} (p. {{ bm.pageNum }})
                  </option>
                </select>
              </div>

              <!-- Static badge fallback if availableSongs is not loaded yet and item already had bookmark -->
              <span
                v-else-if="item.bookmarkName"
                :class="$style.bookmarkBadge"
                :title="t('sheetmusic', 'Bookmark page {page}', { page: item.pageNum ?? 1 })">
                <BookmarkMusicOutline :size="14" :class="$style.bookmarkIcon" />
                {{ item.bookmarkName }}
                <span v-if="item.pageNum" :class="$style.bookmarkPage"> (p. {{ item.pageNum }})</span>
              </span>
            </div>
          </td>
          <td :class="$style.colDuration">
            {{ item.duration ? formatDuration(item.duration) : '—' }}
          </td>
          <td v-if="canWrite !== false" :class="$style.colActions">
            <NcButton
              variant="error"
              size="small"
              :title="t('sheetmusic', 'Remove from setlist')"
              :aria-label="t('sheetmusic', 'Remove from setlist')"
              @click="emit('remove', item)">
              <template #icon>
                <DeleteIcon :size="16" />
              </template>
            </NcButton>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ArrowUpIcon from 'vue-material-design-icons/ArrowUp.vue'
import ArrowDownIcon from 'vue-material-design-icons/ArrowDown.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import PlaylistPlay from 'vue-material-design-icons/PlaylistPlay.vue'
import BookmarkMusicOutline from 'vue-material-design-icons/BookmarkMusicOutline.vue'
import type { Bookmark, SetlistItem, Song } from '../../types/song'
import { formatDuration } from '../../utils/song-formatter'

const props = withDefaults(
  defineProps<{
    items: SetlistItem[]
    availableSongs?: Song[]
    isLoading: boolean
    canWrite?: boolean
  }>(),
  {
    availableSongs: () => [],
    canWrite: true,
  },
)

const emit = defineEmits<{
  'move': [fromIndex: number, toIndex: number]
  'open-song': [item: SetlistItem]
  'update-bookmark': [item: SetlistItem, bookmarkId: number | null]
  'remove': [item: SetlistItem]
  'add-item': []
}>()

const bookmarksBySongId = computed<Record<number, Bookmark[]>>(() => {
  const map: Record<number, Bookmark[]> = {}
  for (const song of props.availableSongs) {
    if (song.id && song.bookmarks && song.bookmarks.length > 0) {
      map[song.id] = song.bookmarks
    }
  }
  return map
})

function getSongBookmarks(songId: number): Bookmark[] {
  return bookmarksBySongId.value[songId] ?? []
}

function onBookmarkSelect(item: SetlistItem, event: Event) {
  const target = event.target as HTMLSelectElement
  const value = target.value
  const bookmarkId = value ? parseInt(value, 10) : null
  if (item.bookmarkId !== bookmarkId) {
    emit('update-bookmark', item, bookmarkId)
  }
}
</script>

<style module>
.loadingArea,
.emptyDetail {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 16px;
  text-align: center;
  color: var(--color-text-maxcontrast);
}

.emptyDetailIcon {
  margin-bottom: 16px;
  opacity: 0.5;
}

.emptyDetailHint {
  font-size: 0.85rem;
  margin-bottom: 16px;
}

.itemsTableContainer {
  width: 100%;
  overflow-x: auto;
}

.itemsTable {
  width: 100%;
  border-collapse: collapse;
}

.itemsTable th {
  text-align: start;
  padding: 8px 12px;
  font-weight: 600;
  font-size: 0.85rem;
  color: var(--color-text-maxcontrast);
  border-bottom: 2px solid var(--color-border);
}

.itemRow {
  border-bottom: 1px solid var(--color-border);
  transition: background-color 0.15s ease;
}

.itemRow:hover {
  background-color: var(--color-background-hover);
}

.itemsTable td {
  padding: 10px 12px;
  vertical-align: middle;
}

.colPos {
  width: 48px;
  text-align: center;
}

.posBadge {
  font-weight: 600;
  color: var(--color-text-maxcontrast);
}

.colOrder {
  width: 80px;
}

.reorderButtons {
  display: flex;
  gap: 2px;
}

.colTitle {
  min-width: 200px;
}

.titleWrapper {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.songTitleBtn {
  background: transparent;
  border: 0;
  padding: 0;
  color: var(--color-primary-element, #0082c9);
  font: inherit;
  font-weight: 500;
  cursor: pointer;
  text-align: start;
}

.songTitleBtn:hover {
  text-decoration: underline;
}

.bookmarkBadge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.12));
  color: var(--color-primary-element, #0082c9);
  border-radius: 12px;
  font-size: 0.8rem;
  font-weight: 500;
}

.inlineBookmarkPicker {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  border-radius: 12px;
  font-size: 0.8rem;
  font-weight: 500;
  padding: 1px 6px;
  transition: background-color 0.15s ease, border-color 0.15s ease;
}

.inlineBookmarkPicker.hasBookmark {
  background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.12));
  color: var(--color-primary-element, #0082c9);
}

.inlineBookmarkPicker.noBookmark {
  background-color: var(--color-background-hover, rgba(127, 127, 127, 0.08));
  color: var(--color-text-maxcontrast, #555);
  border: 1px dashed var(--color-border, #ccc);
}

.inlineBookmarkPicker:hover {
  opacity: 0.9;
}

.inlineBookmarkSelect {
  appearance: none;
  -webkit-appearance: none;
  background: transparent;
  border: 0;
  color: inherit;
  font: inherit;
  font-size: 0.8rem;
  font-weight: 500;
  padding: 2px 14px 2px 2px;
  cursor: pointer;
  outline: none;
  background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg%20xmlns='http://www.w3.org/2000/svg'%20viewBox='0%200%2024%2024'%20fill='%23666'%3E%3Cpath%20d='M7%2010l5%205%205-5z'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 0 center;
  background-size: 14px;
}

.inlineBookmarkSelect:focus-visible {
  outline: 2px solid var(--color-primary-element, #0082c9);
  border-radius: 4px;
}

.inlineBookmarkSelect option {
  background-color: var(--color-main-background, #fff);
  color: var(--color-main-text, #222);
}

.bookmarkIcon {
  flex-shrink: 0;
}

.bookmarkPage {
  opacity: 0.85;
}

.colDuration {
  width: 80px;
  font-variant-numeric: tabular-nums;
  color: var(--color-text-maxcontrast);
}

.colActions {
  width: 60px;
  text-align: end;
}
</style>
