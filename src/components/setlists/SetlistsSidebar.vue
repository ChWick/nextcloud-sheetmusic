<template>
  <div :class="$style.masterColumn">
    <div :class="$style.searchWrapper">
      <NcTextField
        :model-value="searchQuery"
        :placeholder="t('sheetmusic', 'Search setlists…')"
        :label="t('sheetmusic', 'Search setlists')"
        :class="$style.searchInput"
        @update:model-value="emit('update:searchQuery', $event)" />
    </div>

    <div :class="$style.listColumn">
      <ul :class="$style.setlistsList">
        <li
          v-for="setlist in visibleSetlists"
          :key="setlist.id"
          :class="[$style.setlistRow, selectedSetlist?.id === setlist.id && $style.selectedRow]">
          <button
            type="button"
            :class="$style.setlistButton"
            :title="t('sheetmusic', 'Open setlist {name}', { name: setlist.name })"
            @click="emit('select', setlist)">
            <PlaylistMusic :size="20" :class="$style.setlistIcon" />
            <span :class="$style.setlistName">{{ setlist.name }}</span>
          </button>
          <span :class="$style.itemCountBadge">{{ setlist.itemCount ?? 0 }}</span>
          <div v-if="canWrite !== false" :class="$style.rowActions">
            <NcButton
              size="small"
              :title="t('sheetmusic', 'Rename setlist')"
              :aria-label="t('sheetmusic', 'Rename setlist')"
              @click="emit('rename', setlist)">
              <template #icon>
                <PencilIcon :size="18" />
              </template>
            </NcButton>
            <NcButton
              variant="error"
              size="small"
              :title="t('sheetmusic', 'Delete setlist')"
              :aria-label="t('sheetmusic', 'Delete setlist')"
              @click="emit('delete', setlist)">
              <template #icon>
                <DeleteIcon :size="18" />
              </template>
            </NcButton>
          </div>
        </li>
        <li v-if="!isLoading && visibleSetlists.length === 0" :class="$style.emptyMessage">
          {{ searchQuery.trim() && totalSetlistsCount > 0
            ? t('sheetmusic', 'No setlists match your search.')
            : t('sheetmusic', 'No setlists yet. Click "New setlist" to create one.') }}
        </li>
      </ul>
      <p v-if="errorMessage" :class="$style.error" role="alert">{{ errorMessage }}</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import PlaylistMusic from 'vue-material-design-icons/PlaylistMusic.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import type { Setlist } from '../../types/song'

withDefaults(
  defineProps<{
    visibleSetlists: Setlist[]
    totalSetlistsCount: number
    selectedSetlist: Setlist | null
    searchQuery: string
    isLoading: boolean
    errorMessage: string
    canWrite?: boolean
  }>(),
  {
    canWrite: true,
  },
)

const emit = defineEmits<{
  'update:searchQuery': [query: string]
  'select': [setlist: Setlist]
  'rename': [setlist: Setlist]
  'delete': [setlist: Setlist]
}>()
</script>

<style module>
.masterColumn {
  display: flex;
  flex-direction: column;
  gap: 12px;
  border-right: 1px solid var(--color-border);
  padding-right: 16px;
  overflow-y: auto;
}

.searchWrapper {
  width: 100%;
}

.searchInput {
  width: 100%;
}

.listColumn {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.setlistsList {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.setlistRow {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  border-radius: var(--border-radius-element, 8px);
  transition: background-color 0.15s ease;
}

.setlistRow:hover {
  background-color: var(--color-background-hover);
}

.selectedRow {
  background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.12));
}

.setlistButton {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 1;
  background: transparent;
  border: 0;
  padding: 4px 0;
  cursor: pointer;
  text-align: start;
  min-width: 0;
  color: inherit;
  font: inherit;
}

.setlistIcon {
  color: var(--color-primary-element, #0082c9);
  flex-shrink: 0;
}

.setlistName {
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.itemCountBadge {
  font-size: 0.8rem;
  color: var(--color-text-maxcontrast);
  background-color: var(--color-background-darker);
  padding: 2px 8px;
  border-radius: 12px;
  flex-shrink: 0;
}

.rowActions {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-shrink: 0;
}

.emptyMessage {
  padding: 16px 8px;
  color: var(--color-text-maxcontrast);
  text-align: center;
}

.error {
  color: var(--color-error);
  font-size: 0.85rem;
}
</style>
