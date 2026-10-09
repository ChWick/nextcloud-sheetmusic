<template>
  <ViewHeader
    :title="config.plural"
    :create-label="t('mobilesheetsmanager', 'New {entity}', { entity: config.singular })"
    @create="openCreateDialog" />
  <div :class="$style.entitiesArea">
    <div :class="$style.searchWrapper">
      <NcTextField
        v-model="searchQuery"
        :placeholder="t('mobilesheetsmanager', 'Search {entities}…', { entities: config.plural.toLowerCase() })"
        :label="t('mobilesheetsmanager', 'Search {entities}', { entities: config.plural.toLowerCase() })"
        :class="$style.searchInput" />
    </div>
    <div :class="$style.listColumn">
      <ul :class="$style.entityList">
        <li v-for="entity in visibleEntities"
            :key="entity.id"
            :class="[$style.entityRow, revealedDeleteEntityId === entity.id && $style.deleteRevealed]"
            @touchstart="handleRowTouchStart($event)"
            @touchend="handleRowTouchEnd(entity, $event)"
            @touchcancel="handleRowTouchCancel">
          <button type="button"
                  :class="$style.entityButton"
                  :title="t('mobilesheetsmanager', 'Show the songs with this {entity}', { entity: config.singular })"
                  @click="showSongsOf(entity)">
            {{ entityLabel(entity) }}
          </button>
          <span :class="$style.entitySongCount">{{ songCountLabel(entity) }}</span>
          <NcButton variant="error"
                    size="small"
                    :class="$style.deleteButton"
                    :aria-label="t('mobilesheetsmanager', 'Delete {entity}', { entity: config.singular })"
                    :title="t('mobilesheetsmanager', 'Delete {entity}', { entity: config.singular })"
                    @click="openDeleteDialog(entity)">
            <template #icon>
              <DeleteIcon :size="18" />
            </template>
          </NcButton>
        </li>
        <li v-if="!isLoading && !fileError && visibleEntities.length === 0">
          {{ searchQuery.trim() && entities.length > 0
            ? t('mobilesheetsmanager', 'No {entities} match the search query.', { entities: config.plural.toLowerCase() })
            : t('mobilesheetsmanager', 'No {entities} in this library yet.', { entities: config.plural.toLowerCase() }) }}
        </li>
      </ul>
      <p v-if="fileError" :class="$style.error" role="alert">{{ fileError }}</p>
    </div>

    <NcDialog v-if="isCreateDialogOpen"
              :name="t('mobilesheetsmanager', 'New {entity}', { entity: config.singular })"
              size="normal"
              @close="closeCreateDialog">
      <p>{{ t('mobilesheetsmanager', 'The new {entity} can be assigned to songs right afterwards.', { entity: config.singular }) }}</p>
      <NcTextField
        v-model="newEntityName"
        :placeholder="namePlaceholder"
        @keyup.enter="createEntity" />
      <p v-if="createError" :class="$style.error" role="alert">{{ createError }}</p>
      <template #actions>
        <NcButton @click="closeCreateDialog">{{ t('mobilesheetsmanager', 'Cancel') }}</NcButton>
        <NcButton variant="primary"
                  :disabled="isCreating || !newEntityName.trim()"
                  @click="createEntity">
          {{ t('mobilesheetsmanager', 'Create {entity}', { entity: config.singular }) }}
        </NcButton>
      </template>
    </NcDialog>

    <NcDialog v-if="isDeleteDialogOpen"
              :name="t('mobilesheetsmanager', 'Delete {entity}', { entity: config.singular })"
              size="normal"
              @close="closeDeleteDialog">
      <p>{{ t('mobilesheetsmanager', 'Delete "{name}"?', { name: entityToDelete ? entityLabel(entityToDelete) : '' }) }}</p>
      <p>{{ t('mobilesheetsmanager', 'All songs stay in the library. Only the {entity} entry is removed from them.', { entity: config.singular }) }}</p>
      <p v-if="deleteError" :class="$style.error" role="alert">{{ deleteError }}</p>
      <template #actions>
        <NcButton @click="closeDeleteDialog">{{ t('mobilesheetsmanager', 'Cancel') }}</NcButton>
        <NcButton variant="error" :disabled="isDeleting" @click="deleteEntity">
          {{ t('mobilesheetsmanager', 'Delete {entity}', { entity: config.singular }) }}
        </NcButton>
      </template>
    </NcDialog>
  </div>
</template>

<script setup lang="ts">
import type { Artist, Book, Collection, Composer, CustomGroup, EntityType, FilterEntity, Genre, Key, LibraryData, Signature, Song, SourceType, Year } from '../types/song'

import { translate as t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import ViewHeader from './ViewHeader.vue'
import { useLibraryData } from '../composables/useLibraryData'
import { apiDelete, apiPost } from '../api/client'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { normalizeSearchString } from '../utils/string'

const props = defineProps<{
  selectedLibrary: number
  /** Which of the song filters this view manages: composers, artists, books, collections, genres, keys, customgroups, years, signatures or sourcetypes. */
  entityType: EntityType
}>()

const router = useRouter()

/** Everything this shared view needs to know about the entity kind it shows. */
interface EntityConfig {
  createEndpoint: '/api/composers' | '/api/artists' | '/api/books' | '/api/collections' | '/api/genres' | '/api/keys' | '/api/customgroups' | '/api/years' | '/api/signatures' | '/api/sourcetypes'
  deleteEndpoint: '/api/composers/{id}' | '/api/artists/{id}' | '/api/books/{id}' | '/api/collections/{id}' | '/api/genres/{id}' | '/api/keys/{id}' | '/api/customgroups/{id}' | '/api/years/{id}' | '/api/signatures/{id}' | '/api/sourcetypes/{id}'
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
  genres: {
    createEndpoint: '/api/genres',
    deleteEndpoint: '/api/genres/{id}',
    nameKey: 'name',
    singular: t('mobilesheetsmanager', 'genre'),
    plural: t('mobilesheetsmanager', 'Genres'),
  },
  keys: {
    createEndpoint: '/api/keys',
    deleteEndpoint: '/api/keys/{id}',
    nameKey: 'name',
    singular: t('mobilesheetsmanager', 'key'),
    plural: t('mobilesheetsmanager', 'Keys'),
  },
  customgroups: {
    createEndpoint: '/api/customgroups',
    deleteEndpoint: '/api/customgroups/{id}',
    nameKey: 'name',
    singular: t('mobilesheetsmanager', 'custom group'),
    plural: t('mobilesheetsmanager', 'Custom Groups'),
  },
  years: {
    createEndpoint: '/api/years',
    deleteEndpoint: '/api/years/{id}',
    nameKey: 'name',
    singular: t('mobilesheetsmanager', 'year'),
    plural: t('mobilesheetsmanager', 'Years'),
  },
  signatures: {
    createEndpoint: '/api/signatures',
    deleteEndpoint: '/api/signatures/{id}',
    nameKey: 'name',
    singular: t('mobilesheetsmanager', 'time signature'),
    plural: t('mobilesheetsmanager', 'Time Signatures'),
  },
  sourcetypes: {
    createEndpoint: '/api/sourcetypes',
    deleteEndpoint: '/api/sourcetypes/{id}',
    nameKey: 'name',
    singular: t('mobilesheetsmanager', 'source type'),
    plural: t('mobilesheetsmanager', 'Source Types'),
  },
}

const config = computed<EntityConfig>(() => entityConfigs[props.entityType])

const {
  songs,
  composers,
  books,
  collections,
  artists,
  genres,
  keys,
  customGroups,
  years,
  signatures,
  sourceTypes,
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
  if (props.entityType === 'genres') return genres.value
  if (props.entityType === 'keys') return keys.value
  if (props.entityType === 'customgroups') return customGroups.value
  if (props.entityType === 'years') return years.value
  if (props.entityType === 'signatures') return signatures.value
  if (props.entityType === 'sourcetypes') return sourceTypes.value
  return []
})

/** Entry whose delete dialog is open. */
const entityToDelete = ref<FilterEntity | null>(null)
const isCreateDialogOpen = ref(false)
const isDeleteDialogOpen = ref(false)
const isCreating = ref(false)
const isDeleting = ref(false)
const revealedDeleteEntityId = ref<number | null>(null)
const searchQuery = ref('')
const newEntityName = ref('')
const createError = ref('')
const deleteError = ref('')
let rowTouchStart: { x: number; y: number } | null = null

/** Books carry a title, every other entity kind a name. */
function entityLabel(entity: FilterEntity): string {
  return 'title' in entity ? entity.title : entity.name
}

const namePlaceholder = computed(() => {
  if (config.value.nameKey === 'title') {
    return t('mobilesheetsmanager', 'Enter a title')
  }
  if (props.entityType === 'years') {
    return t('mobilesheetsmanager', 'Enter a year')
  }
  if (props.entityType === 'signatures') {
    return t('mobilesheetsmanager', 'Enter a time signature (e.g. 4/4)')
  }
  if (props.entityType === 'sourcetypes') {
    return t('mobilesheetsmanager', 'Enter a source type')
  }
  return t('mobilesheetsmanager', 'Enter a name')
})

/** The entries of the kind this view manages that the song carries. */
function entitiesOfSong(song: Song): FilterEntity[] {
  if (props.entityType === 'customgroups') {
    return song.customGroups ?? []
  }
  if (props.entityType === 'sourcetypes') {
    return song.sourceTypes ?? []
  }
  return song[props.entityType] ?? []
}

/** Number of distinct songs carrying each entry in this view. */
const entitySongCounts = computed(() => {
  const counts = new Map<number, number>()
  for (const song of songs.value) {
    const attachedEntityIds = new Set(entitiesOfSong(song).map(entity => entity.id))
    for (const id of attachedEntityIds) {
      counts.set(id, (counts.get(id) ?? 0) + 1)
    }
  }
  return counts
})

function songCountLabel(entity: FilterEntity): string {
  const count = entitySongCounts.value.get(entity.id) ?? 0
  const unit = count === 1
    ? t('mobilesheetsmanager', 'song')
    : t('mobilesheetsmanager', 'songs')
  return `${count} ${unit}`
}

function handleRowTouchStart(event: TouchEvent) {
  const touch = event.touches[0]
  rowTouchStart = touch ? { x: touch.clientX, y: touch.clientY } : null
}

function handleRowTouchEnd(entity: FilterEntity, event: TouchEvent) {
  const start = rowTouchStart
  rowTouchStart = null
  const touch = event.changedTouches[0]
  if (!start || !touch) return

  const deltaX = touch.clientX - start.x
  const deltaY = touch.clientY - start.y
  if (Math.abs(deltaX) < 40 || Math.abs(deltaX) <= Math.abs(deltaY)) return

  revealedDeleteEntityId.value = deltaX < 0
    ? entity.id
    : revealedDeleteEntityId.value === entity.id ? null : revealedDeleteEntityId.value
}

function handleRowTouchCancel() {
  rowTouchStart = null
}

/**
 * The entries this view lists, filtered by the text search query.
 */
const visibleEntities = computed(() => {
  const query = normalizeSearchString(searchQuery.value)
  if (!query) return entities.value
  return entities.value.filter(entity => normalizeSearchString(entityLabel(entity)).includes(query))
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
    showSuccess(t('mobilesheetsmanager', '{entity} "{name}" created successfully.', { entity: config.value.singular, name }))
  } catch {
    createError.value = t('mobilesheetsmanager', 'Could not create the {entity}.', { entity: config.value.singular })
    showError(createError.value)
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
    const entityName = (entity as any).name ?? (entity as any).title ?? ''
    const deleteEndpoint = config.value.deleteEndpoint
    const response = await apiDelete(deleteEndpoint as any, {
      pathParams: { id: entity.id },
      queryParams: { libraryId: props.selectedLibrary },
    })
    closeDeleteDialog()
    handleLibraryDataUpdate(response.data.ocs.data)
    if (entityName) {
      showSuccess(t('mobilesheetsmanager', '{entity} "{name}" deleted successfully.', { entity: config.value.singular, name: entityName }))
    } else {
      showSuccess(t('mobilesheetsmanager', '{entity} deleted successfully.', { entity: config.value.singular }))
    }
  } catch {
    deleteError.value = t('mobilesheetsmanager', 'Could not delete the {entity}.', { entity: config.value.singular })
    showError(deleteError.value)
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
.searchWrapper {
  max-width: 400px;
}
.searchInput {
  width: 100%;
}
.entityList {
  display: grid;
  grid-template-columns: minmax(0, 1fr) max-content auto;
  column-gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.entityRow {
  display: grid;
  grid-column: 1 / -1;
  grid-template-columns: subgrid;
  align-items: center;
  border-bottom: 1px solid var(--color-border);
  min-width: 0;
}
.entityList > li:not(.entityRow) {
  grid-column: 1 / -1;
}
.entityList > li:not(.entityRow) {
  grid-column: 1 / -1;
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
.entitySongCount {
  color: var(--color-text-maxcontrast);
  white-space: nowrap;
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
@media (max-width: 768px) {
  .deleteButton {
    visibility: hidden;
  }
  .deleteRevealed .deleteButton {
    visibility: visible;
  }
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