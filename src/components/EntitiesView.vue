<template>
  <ViewHeader
    :title="config.plural"
    :create-label="t('mobilesheetsmanager', 'New {entity}', { entity: config.singular })"
    :filter-bar-visible="filterBarVisible"
    @create="openCreateDialog"
    @toggle-filters="toggleFilterBar" />
  <div :class="$style.entitiesArea">
    <div v-if="filterBarVisible" :class="$style.filterBarWrapper">
      <SongFilterBar
        v-model:selected-composers="selectedComposers"
        v-model:selected-books="selectedBooks"
        v-model:selected-collections="selectedCollections"
        v-model:selected-artists="selectedArtists"
        :composers="composers"
        :books="books"
        :collections="collections"
        :artists="artists"
        @clear="clearFilters" />
    </div>
    <div :class="$style.listColumn">
      <ul :class="$style.entityList">
        <li v-for="entity in visibleEntities" :key="entity.id" :class="$style.entityRow">
          <button type="button"
                  :class="$style.entityButton"
                  :title="t('mobilesheetsmanager', 'Show the songs with this {entity}', { entity: config.singular })"
                  @click="showSongsOf(entity)">
            {{ entityLabel(entity) }}
          </button>
          <NcButton variant="error"
                    size="small"
                    :class="$style.deleteButton"
                    :aria-label="t('mobilesheetsmanager', 'Delete {entity}', { entity: config.singular })"
                    @click="openDeleteDialog(entity)">
            {{ t('mobilesheetsmanager', 'Delete') }}
          </NcButton>
        </li>
        <li v-if="!isLoading && !fileError && visibleEntities.length === 0">
          {{ hasActiveFilters && entities.length > 0
            ? t('mobilesheetsmanager', 'No {entities} match the selected filters.', { entities: config.plural.toLowerCase() })
            : t('mobilesheetsmanager', 'No {entities} in this library yet.', { entities: config.plural.toLowerCase() }) }}
        </li>
      </ul>
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
      <p>{{ t('mobilesheetsmanager', 'Delete "{name}"?', { name: entityToDelete ? entityLabel(entityToDelete) : '' }) }}</p>
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
import { useRouter } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import SongFilterBar from './SongFilterBar.vue'
import ViewHeader from './ViewHeader.vue'
import { useSongFilters } from '../composables/useSongFilters'
import { useLibraryData } from '../composables/useLibraryData'
import { apiDelete, apiPost } from '../api/client'

const props = defineProps<{
  selectedLibrary: number
  /** Which of the song filters this view manages: composers, artists, books or collections. */
  entityType: EntityType
}>()

const router = useRouter()

/** Everything this shared view needs to know about the entity kind it shows. */
interface EntityConfig {
  createEndpoint: '/api/composers' | '/api/artists' | '/api/books' | '/api/collections'
  deleteEndpoint: '/api/composers/{id}' | '/api/artists/{id}' | '/api/books/{id}' | '/api/collections/{id}'
  nameKey: 'name' | 'title'
  singular: string
  plural: string
}

const entityConfigs: Record<EntityType, EntityConfig> = {
  composers: {
    createEndpoint: '/api/composers',
    deleteEndpoint: '/api/composers/{id}',
    nameKey: 'name',
    singular: t('mobilesheetsmanager', 'composer'),
    plural: t('mobilesheetsmanager', 'Composers'),
  },
  artists: {
    createEndpoint: '/api/artists',
    deleteEndpoint: '/api/artists/{id}',
    nameKey: 'name',
    singular: t('mobilesheetsmanager', 'artist'),
    plural: t('mobilesheetsmanager', 'Artists'),
  },
  books: {
    createEndpoint: '/api/books',
    deleteEndpoint: '/api/books/{id}',
    nameKey: 'title',
    singular: t('mobilesheetsmanager', 'book'),
    plural: t('mobilesheetsmanager', 'Books'),
  },
  collections: {
    createEndpoint: '/api/collections',
    deleteEndpoint: '/api/collections/{id}',
    nameKey: 'name',
    singular: t('mobilesheetsmanager', 'collection'),
    plural: t('mobilesheetsmanager', 'Collections'),
  },
}

const config = computed<EntityConfig>(() => entityConfigs[props.entityType])

const {
  songs,
  composers,
  books,
  collections,
  artists,
  fileError,
  isLoading,
  handleLibraryDataUpdate,
  loadLibraryData,
} = useLibraryData()

const entities = computed<FilterEntity[]>(() => {
  if (props.entityType === 'composers') return composers.value
  if (props.entityType === 'artists') return artists.value
  if (props.entityType === 'books') return books.value
  if (props.entityType === 'collections') return collections.value
  return []
})

/** Entry whose delete dialog is open. */
const entityToDelete = ref<FilterEntity | null>(null)
const isCreateDialogOpen = ref(false)
const isDeleteDialogOpen = ref(false)
const isCreating = ref(false)
const isDeleting = ref(false)
const newEntityName = ref('')
const createError = ref('')
const deleteError = ref('')

// The filter bar reads and writes its selections in the URL query, so the browser
// history restores them when going back or forward.
const { selectedComposers, selectedBooks, selectedCollections, selectedArtists, hasActiveFilters, filterSongs, clearFilters, filterBarVisible, toggleFilterBar } =
  useSongFilters(() => ({ composers: composers.value, books: books.value, collections: collections.value, artists: artists.value }))

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

// Watch for library or entity kind changes
watch(
  [() => props.selectedLibrary, () => props.entityType],
  ([library]) => {
    // An open delete dialog belongs to the previous library or entity kind.
    closeDeleteDialog()
    if (library) loadLibraryData(library)
  },
  { immediate: true },
)

/**
 * Picking an entry shows its songs: the songs view opens with this entry as the
 * only filter, in one history entry so going back returns to this view again.
 */
function showSongsOf(entity: FilterEntity) {
  router.push({
    name: 'LibraryView',
    params: { library: props.selectedLibrary, view: 'songs' },
    query: { [props.entityType]: String(entity.id) },
  })
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
    const postEndpoint = config.value.createEndpoint
    const body = config.value.nameKey === 'title'
      ? { libraryId: props.selectedLibrary, title: name }
      : { libraryId: props.selectedLibrary, name }

    await apiPost(postEndpoint as any, body as any)
    closeCreateDialog()
    await loadLibraryData(props.selectedLibrary)
  } catch {
    createError.value = t('mobilesheetsmanager', 'Could not create the {entity}.', { entity: config.value.singular })
  } finally {
    isCreating.value = false
  }
}

/*** Deleting an entity ***/
function openDeleteDialog(entity: FilterEntity) {
  entityToDelete.value = entity
  deleteError.value = ''
  isDeleteDialogOpen.value = true
}
function closeDeleteDialog() {
  isDeleteDialogOpen.value = false
  deleteError.value = ''
  entityToDelete.value = null
}
/**
 * Remove the entry the dialog was opened for. The songs of the library stay and
 * only lose this entry in their field, which the refreshed library data of the
 * response shows.
 */
async function deleteEntity() {
  const entity = entityToDelete.value
  if (!entity || isDeleting.value) return

  isDeleting.value = true
  deleteError.value = ''
  try {
    const deleteEndpoint = config.value.deleteEndpoint
    const response = await apiDelete(deleteEndpoint as any, {
      pathParams: { id: entity.id },
      queryParams: { libraryId: props.selectedLibrary },
    })
    closeDeleteDialog()
    handleLibraryDataUpdate(response.data.ocs.data)
  } catch {
    deleteError.value = t('mobilesheetsmanager', 'Could not delete the {entity}.', { entity: config.value.singular })
  } finally {
    isDeleting.value = false
  }
}
</script>

<style module>
.entitiesArea {
  margin: 16px;
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
}
.filterBarWrapper {
  margin-bottom: 4px;
}
.entityList {
  margin: 0;
  padding: 0;
  list-style: none;
}
.entityRow {
  display: flex;
  align-items: center;
  gap: 8px;
  border-bottom: 1px solid var(--color-border);
  min-width: 0;
}
.entityButton {
  flex: 1;
  padding: 10px 0;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: start;
  cursor: pointer;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.entityButton:hover {
  background: var(--color-background-hover);
}
.listColumn {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-width: 0;
}
.deleteButton {
  flex-shrink: 0;
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