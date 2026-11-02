<template>
  <NcDialog
    v-if="open"
    :name="t('sheetmusic', 'Add to setlist')"
    size="normal"
    @close="emit('close')">
    <div :class="$style.addItemContent">
      <p>{{ t('sheetmusic', 'Select a song from your library:') }}</p>
      <NcTextField
        v-model="searchQuery"
        :placeholder="t('sheetmusic', 'Filter songs…')"
        :label="t('sheetmusic', 'Filter songs')"
        :class="$style.songSearchInput" />

      <div :class="$style.songSelectionList">
        <div
          v-for="song in filteredSongs"
          :key="song.id"
          :class="[$style.songChoiceRow, selectedSong?.id === song.id && $style.songChoiceSelected]"
          @click="selectSong(song)">
          <div :class="$style.songChoiceInfo">
            <span :class="$style.songChoiceTitle">{{ song.title }}</span>
            <span v-if="song.bookmarks && song.bookmarks.length > 0" :class="$style.songChoiceBookmarkCount">
              {{ t('sheetmusic', '{count} bookmarks', { count: song.bookmarks.length }) }}
            </span>
          </div>
        </div>
        <div v-if="filteredSongs.length === 0" :class="$style.noSongsMatch">
          {{ t('sheetmusic', 'No songs match your search.') }}
        </div>
      </div>

      <!-- If song selected: Option for Whole Song vs Specific Bookmark -->
      <div v-if="selectedSong" :class="$style.targetOptionArea">
        <h4>{{ t('sheetmusic', 'Include:') }}</h4>
        <div :class="$style.radioOption">
          <label>
            <input
              v-model="targetType"
              type="radio"
              value="entire_song" />
            <strong>{{ t('sheetmusic', 'Entire Song') }}</strong> ({{ selectedSong.title }})
          </label>
        </div>

        <div v-if="selectedSong.bookmarks && selectedSong.bookmarks.length > 0" :class="$style.radioOption">
          <label>
            <input
              v-model="targetType"
              type="radio"
              value="bookmark" />
            <strong>{{ t('sheetmusic', 'Specific Bookmark / Movement') }}</strong>
          </label>
          <div v-if="targetType === 'bookmark'" :class="$style.bookmarkDropdown">
            <select v-model="selectedBookmarkId" :class="$style.selectBookmark">
              <option :value="null" disabled>
                {{ t('sheetmusic', 'Select a bookmark…') }}
              </option>
              <option
                v-for="bm in selectedSong.bookmarks"
                :key="bm.id"
                :value="bm.id">
                {{ bm.name }} (p. {{ bm.pageNum }}{{ bm.endPage ? `–${bm.endPage}` : '' }})
              </option>
            </select>
          </div>
        </div>
        <p v-else :class="$style.noBookmarksNote">
          <em>{{ t('sheetmusic', 'This song has no bookmarks defined.') }}</em>
        </p>
      </div>

      <p v-if="error" :class="$style.error" role="alert">{{ error }}</p>
    </div>

    <template #actions>
      <NcButton @click="emit('close')">{{ t('sheetmusic', 'Cancel') }}</NcButton>
      <NcButton
        variant="primary"
        :disabled="isAdding || !canSubmit"
        @click="handleAdd">
        {{ t('sheetmusic', 'Add to setlist') }}
      </NcButton>
    </template>
  </NcDialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import type { Song } from '../../types/song'

const props = defineProps<{
  open: boolean
  availableSongs: Song[]
  isAdding: boolean
  error: string
}>()

const emit = defineEmits<{
  'close': []
  'add': [payload: { songId: number, bookmarkId: number | null }]
}>()

const searchQuery = ref('')
const selectedSong = ref<Song | null>(null)
const targetType = ref<'entire_song' | 'bookmark'>('entire_song')
const selectedBookmarkId = ref<number | null>(null)

watch(() => props.open, (isOpen) => {
  if (isOpen) {
    searchQuery.value = ''
    selectedSong.value = null
    targetType.value = 'entire_song'
    selectedBookmarkId.value = null
  }
})

const filteredSongs = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()
  if (!q) return props.availableSongs
  return props.availableSongs.filter(s => s.title.toLowerCase().includes(q))
})

const canSubmit = computed(() => {
  if (!selectedSong.value) return false
  if (targetType.value === 'bookmark' && !selectedBookmarkId.value) return false
  return true
})

function selectSong(song: Song) {
  selectedSong.value = song
  if (song.bookmarks && song.bookmarks.length > 0) {
    selectedBookmarkId.value = song.bookmarks[0].id ?? null
  } else {
    targetType.value = 'entire_song'
    selectedBookmarkId.value = null
  }
}

function handleAdd() {
  if (!selectedSong.value || !canSubmit.value) return
  emit('add', {
    songId: selectedSong.value.id,
    bookmarkId: targetType.value === 'bookmark' ? selectedBookmarkId.value : null,
  })
}
</script>

<style module>
.addItemContent {
  display: flex;
  flex-direction: column;
  gap: 12px;
  max-height: 480px;
  overflow-y: auto;
}

.songSearchInput {
  width: 100%;
}

.songSelectionList {
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-element, 8px);
  max-height: 200px;
  overflow-y: auto;
  padding: 4px;
}

.songChoiceRow {
  padding: 8px 12px;
  cursor: pointer;
  border-radius: 4px;
  transition: background-color 0.15s;
}

.songChoiceRow:hover {
  background-color: var(--color-background-hover);
}

.songChoiceSelected {
  background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.15));
}

.songChoiceInfo {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.songChoiceTitle {
  font-weight: 500;
}

.songChoiceBookmarkCount {
  font-size: 0.75rem;
  color: var(--color-text-maxcontrast);
  background-color: var(--color-background-darker);
  padding: 2px 6px;
  border-radius: 10px;
}

.noSongsMatch {
  padding: 16px;
  text-align: center;
  color: var(--color-text-maxcontrast);
}

.targetOptionArea {
  margin-top: 8px;
  border-top: 1px solid var(--color-border);
  padding-top: 12px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.targetOptionArea h4 {
  margin: 0 0 4px 0;
  font-size: 0.95rem;
}

.radioOption {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.radioOption label {
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
}

.bookmarkDropdown {
  margin-left: 24px;
}

.selectBookmark {
  width: 100%;
  padding: 6px 10px;
  border-radius: var(--border-radius-element, 6px);
  border: 1px solid var(--color-border);
  background: var(--color-main-background);
  color: var(--color-main-text);
  font: inherit;
}

.noBookmarksNote {
  font-size: 0.85rem;
  color: var(--color-text-maxcontrast);
  margin: 4px 0;
}

.error {
  color: var(--color-error);
  font-size: 0.85rem;
}
</style>
