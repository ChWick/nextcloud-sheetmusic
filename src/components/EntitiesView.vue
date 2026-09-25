<template>
  <div :class="$style.entitiesArea">
    <div :class="$style.filterBarWrapper">
      <SongFilterBar
        v-model:selected-composers="selectedComposers"
        v-model:selected-books="selectedBooks"
        v-model:selected-collections="selectedCollections"
        v-model:selected-artists="selectedArtists"
        :composers="composers"
        :books="books"
        :collections="collections"
        :artists="artists" />
    </div>
    <div :class="$style.listColumn">
      <NcButton
        variant="primary"
        :class="$style.newButton"
        @click="openCreateDialog">
        {{ t('mobilesheetsmanager', 'New {entity}', { entity: config.singular }) }}
      </NcButton>
      <ul :class="$style.entityList">
        <li v-for="entity in visibleEntities" :key="entity.id">
          <button type="button"
                  :class="$style.entityButton"
                  :aria-pressed="selectedEntity?.id === entity.id"
                  @click="selectEntity(entity)">
            {{ entityLabel(entity) }}
          </button>
        </li>
        <li v-if="!isLoading && !fileError && visibleEntities.length === 0">
          {{ hasActiveFilters && entities.length > 0
            ? t('mobilesheetsmanager', 'No {entities} match the selected filters.', { entities: config.plural.toLowerCase() })
            : t('mobilesheetsmanager', 'No {entities} in this library yet.', { entities: config.plural.toLowerCase() }) }}
        </li>
      </ul>
    </div>

    <div :class="$style.detailArea">
      <template v-if="selectedEntity">
        <h2 :class="$style.detailTitle">{{ entityLabel(selectedEntity) }}</h2>
        <h3 :class="$style.detailSubtitle">
          {{ t('mobilesheetsmanager', 'Songs with this {entity}', { entity: config.singular }) }}
        </h3>
        <ul :class="$style.songList">
          <li v-for="song in songsUsingEntity" :key="song.id">{{ song.title }}</li>
          <li v-if="songsUsingEntity.length === 0">
            {{ t('mobilesheetsmanager', 'No songs use this {entity}.', { entity: config.singular }) }}
          </li>
        </ul>
        <NcButton variant="error" :class="$style.deleteButton" @click="openDeleteDialog">
          {{ t('mobilesheetsmanager', 'Delete {entity}', { entity: config.singular }) }}
        </NcButton>
      </template>
      <p v-else-if="!isLoading && !fileError">
        {{ t('mobilesheetsmanager', 'Select a {entity} to see its songs.', { entity: config.singular }) }}
      </p>
      <p v-if="fileError" :class="$style.error" role="alert">{{ fileError }}</p>
    </div>

    <NcDialog v-if="isCreateDialogOpen"
              :name="t('mobilesheetsmanager', 'New {entity}', { entity: config.singular })"
              @close="closeCreateDialog">
      <p>{{ t('mobilesheetsmanager', 'The new {entity} can be assigned to songs right afterwards.', { entity: config.singular }) }}</p>
      <NcTextField
        v-model="newEntityName"
        :placeholder="namePlaceholder"
        @keyup.enter="createEntity" />
      <p v-if="createError" :class="$style.error" role="alert">{{ createError }}</p>
      <div :class="$style.dialogActions">
        <NcButton @click="closeCreateDialog">{{ t('mobilesheetsmanager', 'Cancel') }}</NcButton>
        <NcButton variant="primary"
                  :disabled="isCreating || !newEntityName.trim()"
                  @click="createEntity">
          {{ t('mobilesheetsmanager', 'Create {entity}', { entity: config.singular }) }}
        </NcButton>
      </div>
    </NcDialog>

    <NcDialog v-if="isDeleteDialogOpen"
              :name="t('mobilesheetsmanager', 'Delete {entity}', { entity: config.singular })"
              @close="closeDeleteDialog">
      <p>{{ t('mobilesheetsmanager', 'Delete "{name}"?', { name: selectedEntity ? entityLabel(selectedEntity) : '' }) }}</p>
      <p>{{ t('mobilesheetsmanager', 'All songs stay in the library. Only the {entity} entry is removed from them.', { entity: config.singular }) }}</p>
      <p v-if="deleteError" :class="$style.error" role="alert">{{ deleteError }}</p>
      <div :class="$style.dialogActions">
        <NcButton @click="closeDeleteDialog">{{ t('mobilesheetsmanager', 'Cancel') }}</NcButton>
        <NcButton variant="error" :disabled="isDeleting" @click="deleteEntity">
          {{ t('mobilesheetsmanager', 'Delete {entity}', { entity: config.singular }) }}
        </NcButton>
      </div>
    </NcDialog>
  </div>
</template>

<script setup lang="ts">
import type { Artist, Book, Collection, Composer, EntityType, FilterEntity, LibraryData, Song } from '../types/song'

import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import SongFilterBar from './SongFilterBar.vue'
import { useSongFilters } from '../composables/useSongFilters'

const props = defineProps<{
  selectedLibrary: number
  /** Which of the song filters this view manages: composers, artists, books or collections. */
  entityType: EntityType
}>()

/** Everything this shared view needs to know about the entity kind it shows. */
interface EntityConfig {
  /** OCS endpoint that creates and deletes this kind of entity. */
  endpoint: string
  /** Property holding the display name: books are titled, everything else named. */
  nameKey: 'name' | 'title'
  singular: string
  plural: string
}

const entityConfigs: Record<EntityType, EntityConfig> = {
  composers: {
    endpoint: '/apps/mobilesheetsmanager/api/composers',
    nameKey: 'name',
    singular: t('mobilesheetsmanager', 'composer'),
    plural: t('mobilesheetsmanager', 'Composers'),
  },
  artists: {
    endpoint: '/apps/mobilesheetsmanager/api/artists',
    nameKey: 'name',
    singular: t('mobilesheetsmanager', 'artist'),
    plural: t('mobilesheetsmanager', 'Artists'),
  },
  books: {
    endpoint: '/apps/mobilesheetsmanager/api/books',
    nameKey: 'title',
    singular: t('mobilesheetsmanager', 'book'),
    plural: t('mobilesheetsmanager', 'Books'),
  },
  collections: {
    endpoint: '/apps/mobilesheetsmanager/api/collections',
    nameKey: 'name',
    singular: t('mobilesheetsmanager', 'collection'),
    plural: t('mobilesheetsmanager', 'Collections'),
  },
}

const config = computed<EntityConfig>(() => entityConfigs[props.entityType])

// Local state for this view
const entities = ref<FilterEntity[]>([])
const songs = ref<Song[]>([])
// Option lists of the filter bar: it filters by all four kinds, not just the one
// this view manages.
const composers = ref<Composer[]>([])
const books = ref<Book[]>([])
const collections = ref<Collection[]>([])
const artists = ref<Artist[]>([])
const selectedEntity = ref<FilterEntity | null>(null)
const fileError = ref('')
const isLoading = ref(false)
const isCreateDialogOpen = ref(false)
const isDeleteDialogOpen = ref(false)
const isCreating = ref(false)
const isDeleting = ref(false)
const newEntityName = ref('')
const createError = ref('')
const deleteError = ref('')

// The filter bar is a shared module: every view shows the same selections.
const { selectedComposers, selectedBooks, selectedCollections, selectedArtists, hasActiveFilters, filterSongs } =
  useSongFilters(() => props.selectedLibrary)

/** Books carry a title, every other entity kind a name. */
function entityLabel(entity: FilterEntity): string {
  return 'title' in entity ? entity.title : entity.name
}

const namePlaceholder = computed(() => (config.value.nameKey === 'title'
  ? t('mobilesheetsmanager', 'Enter a title')
  : t('mobilesheetsmanager', 'Enter a name')))

/** The entries of the kind this view manages that the song carries. */
function entitiesOfSong(song: Song): FilterEntity[] {
  return song[props.entityType] ?? []
}

/** Songs that match the filter bar selections. */
const visibleSongs = computed(() => filterSongs(songs.value))

/**
 * The entries this view lists. Filters hide the entries that no matching song
 * uses; without filters every entry is listed, even one not assigned to a song yet.
 */
const visibleEntities = computed(() => {
  if (!hasActiveFilters.value) return entities.value
  const usedIds = new Set(visibleSongs.value.flatMap(song => entitiesOfSong(song).map(entity => entity.id)))
  return entities.value.filter(entity => usedIds.has(entity.id))
})

/** Songs that carry the selected entity in the field this view manages. */
const songsUsingEntity = computed(() => {
  const entity = selectedEntity.value
  if (!entity) return []
  return visibleSongs.value.filter(song =>
    entitiesOfSong(song).some(candidate => candidate.id === entity.id),
  )
})

function handleLibraryDataUpdate(data: LibraryData) {
  songs.value = data.songs ?? []
  entities.value = data[props.entityType] ?? []
  composers.value = data.composers ?? []
  books.value = data.books ?? []
  collections.value = data.collections ?? []
  artists.value = data.artists ?? []
  // Keep the selection on the refreshed entry; it is gone after a deletion.
  selectedEntity.value = selectedEntity.value
    ? entities.value.find(entity => entity.id === selectedEntity.value?.id) ?? null
    : null
}

async function loadLibraryData(id: number) {
  isLoading.value = true
  fileError.value = ''
  try {
    const response = await axios.get(generateOcsUrl('/apps/mobilesheetsmanager/api/songs'), { params: { id } })
    handleLibraryDataUpdate(response.data.ocs.data as LibraryData)
  } catch {
    fileError.value = t('mobilesheetsmanager', 'Could not load this library.')
  } finally {
    isLoading.value = false
  }
}

// Watch for library or entity kind changes
watch(
  [() => props.selectedLibrary, () => props.entityType],
  ([library]) => {
    selectedEntity.value = null
    if (library) loadLibraryData(library)
  },
  { immediate: true },
)

// A filter can hide the selected entry: showing the songs of an entry that is not
// in the list would be confusing, so the selection goes with it.
watch(visibleEntities, visible => {
  if (selectedEntity.value && !visible.some(entity => entity.id === selectedEntity.value?.id)) {
    selectedEntity.value = null
  }
})

function selectEntity(entity: FilterEntity) {
  selectedEntity.value = entity
}

/*** Creating an entity ***/
function openCreateDialog() {
  newEntityName.value = ''
  createError.value = ''
  isCreateDialogOpen.value = true
}
function closeCreateDialog() {
  isCreateDialogOpen.value = false
  createError.value = ''
}
async function createEntity() {
  const name = newEntityName.value.trim()
  if (!name || isCreating.value) return

  isCreating.value = true
  createError.value = ''
  try {
    await axios.post(generateOcsUrl(config.value.endpoint), {
      libraryId: props.selectedLibrary,
      [config.value.nameKey]: name,
    })
    closeCreateDialog()
    await loadLibraryData(props.selectedLibrary)
  } catch {
    createError.value = t('mobilesheetsmanager', 'Could not create the {entity}.', { entity: config.value.singular })
  } finally {
    isCreating.value = false
  }
}

/*** Deleting an entity ***/
function openDeleteDialog() {
  if (!selectedEntity.value) return
  deleteError.value = ''
  isDeleteDialogOpen.value = true
}
function closeDeleteDialog() {
  isDeleteDialogOpen.value = false
  deleteError.value = ''
}
/**
 * Remove the selected entry. The songs of the library stay and only lose this
 * entry in their field, which the refreshed library data of the response shows.
 */
async function deleteEntity() {
  const entity = selectedEntity.value
  if (!entity || isDeleting.value) return

  isDeleting.value = true
  deleteError.value = ''
  try {
    const response = await axios.delete(
      generateOcsUrl(`${config.value.endpoint}/{id}`, { id: entity.id }),
      { params: { libraryId: props.selectedLibrary } },
    )
    closeDeleteDialog()
    selectedEntity.value = null
    handleLibraryDataUpdate(response.data.ocs.data as LibraryData)
  } catch {
    deleteError.value = t('mobilesheetsmanager', 'Could not delete the {entity}.', { entity: config.value.singular })
  } finally {
    isDeleting.value = false
  }
}
</script>

<style module>
.entitiesArea {
  display: grid;
  grid-template-columns: minmax(220px, 320px) minmax(0, 1fr);
  grid-template-rows: auto 1fr;
  gap: 24px;
  align-items: start;
}
.filterBarWrapper {
  grid-column: 1 / -1;
  margin-bottom: 12px;
}
.entityList {
  margin: 0;
  padding: 0;
  list-style: none;
}
.entityList li {
  border-bottom: 1px solid var(--color-border);
}
.entityButton {
  width: 100%;
  padding: 10px 0;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: start;
  cursor: pointer;
}
.entityButton[aria-pressed="true"] {
  background: var(--color-background-hover);
}
.listColumn {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-width: 0;
  overflow-y: auto;
}
.newButton {
  width: 100%;
  justify-content: center;
}
.detailArea {
  min-width: 0;
  overflow-y: auto;
}
.detailTitle {
  margin-top: 0;
}
.detailSubtitle {
  margin-bottom: 8px;
  color: var(--color-text-maxcontrast);
}
.songList {
  margin: 0 0 16px;
  padding: 0;
  list-style: none;
}
.songList li {
  padding: 6px 0;
  border-bottom: 1px solid var(--color-border);
}
.deleteButton {
  margin-top: 8px;
}
.dialogActions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 24px;
}
.error {
  color: var(--color-error);
}
</style>