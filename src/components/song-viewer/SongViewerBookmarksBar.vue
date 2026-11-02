<template>
  <div
    v-if="bookmarks.length > 0"
    :class="$style.bookmarksBar"
    role="region"
    :aria-label="t('sheetmusic', 'Bookmarks')">
    <span :class="$style.bookmarksLabel">{{ t('sheetmusic', 'Bookmarks:') }}</span>
    <div :class="$style.bookmarksList">
      <button
        v-for="(bm, idx) in bookmarks"
        :key="bm.id ?? `bm-${idx}`"
        type="button"
        :class="[$style.bookmarkChip, activeBookmarkPage === bm.pageNum && $style.bookmarkChipActive]"
        :title="t('sheetmusic', 'Jump to {name}', { name: bm.name })"
        @click="emit('jump', bm.pageNum)">
        {{ formatBookmarkLabel(bm) }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import type { Bookmark } from '../../types/song'

defineProps<{
  bookmarks: Bookmark[]
  activeBookmarkPage?: number
}>()

const emit = defineEmits<{
  'jump': [pageNum: number]
}>()

function formatBookmarkLabel(bm: Bookmark): string {
  if (bm.endPage && bm.endPage > bm.pageNum) {
    return `${bm.name} (pp. ${bm.pageNum}–${bm.endPage})`
  }
  return `${bm.name} (p. ${bm.pageNum})`
}
</script>

<style module>
.bookmarksBar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 12px;
  background-color: var(--color-background-dark);
  border-bottom: 1px solid var(--color-border);
  overflow-x: auto;
  white-space: nowrap;
}

.bookmarksLabel {
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--color-text-maxcontrast);
  flex-shrink: 0;
}

.bookmarksList {
  display: flex;
  align-items: center;
  gap: 6px;
}

.bookmarkChip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 8px;
  font-size: 0.8rem;
  font-weight: 500;
  border-radius: 12px;
  border: 1px solid var(--color-border);
  background-color: var(--color-main-background);
  color: var(--color-main-text);
  cursor: pointer;
  transition: all 0.15s ease;
}

.bookmarkChip:hover {
  background-color: var(--color-background-hover);
  border-color: var(--color-primary-element);
}

.bookmarkChipActive {
  background-color: var(--color-primary-element);
  color: var(--color-primary-element-text);
  border-color: var(--color-primary-element);
}
</style>
