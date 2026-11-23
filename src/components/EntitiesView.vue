<template>
  <ViewHeader
    :title="config.plural"
    :create-label="effectiveCanWrite ? t('sheetmusic', 'New {entity}', { entity: config.singular }, { escape: false }) : undefined"
    @create="openCreateDialog">
    <NcButton
      v-if="effectiveCanWrite"
      :title="t('sheetmusic', 'Merge duplicates')"
      :aria-label="t('sheetmusic', 'Merge duplicates')"
      @click="openMergeDialog()">
      <template #icon>
        <MergeIcon :size="20" />
      </template>
      {{ t('sheetmusic', 'Merge duplicates') }}
    </NcButton>
  </ViewHeader>
  <div :class="$style.entitiesArea">
    <div :class="$style.searchWrapper">
      <NcTextField
        v-model="searchQuery"
        :placeholder="t('sheetmusic', 'Search {entities}…', { entities: config.plural.toLowerCase() }, { escape: false })"
        :label="t('sheetmusic', 'Search {entities}', { entities: config.plural.toLowerCase() }, { escape: false })"
        :class="$style.searchInput" />
    </div>
    <div :class="$style.listColumn">
      <ul :class="$style.entityList">
        <li v-for="entity in visibleEntities"
            :key="entity.id"
            :class="[$style.entityRow, revealedDeleteEntityId === entity.id && effectiveCanWrite && $style.deleteRevealed]"
            @touchstart="effectiveCanWrite && handleRowTouchStart($event)"
            @touchend="effectiveCanWrite && handleRowTouchEnd(entity, $event)"
            @touchcancel="handleRowTouchCancel">
          <div v-if="editingEntityId === entity.id" :class="$style.editFieldWrapper">
            <input
              ref="editInputRef"
              v-model="editingEntityName"
              type="text"
              :class="$style.inlineEditInput"
              :aria-label="t('sheetmusic', 'Edit {entity}', { entity: config.singular }, { escape: false })"
              :disabled="isUpdatingEntity"
              @keyup.enter="saveEditEntity(entity)"
              @keyup.escape="cancelEditEntity" />
          </div>
          <button v-else
                  type="button"
                  :class="$style.entityButton"
                  :title="t('sheetmusic', 'Show the songs with this {entity}', { entity: config.singular }, { escape: false })"
                  @click="showSongsOf(entity)">
            {{ entityLabel(entity) }}
          </button>
          <span :class="$style.entitySongCount">{{ songCountLabel(entity) }}</span>
          <div v-if="effectiveCanWrite" :class="$style.rowActions">
            <template v-if="editingEntityId === entity.id">
              <NcButton
                variant="primary"
                size="small"
                :class="$style.actionButton"
                :disabled="isUpdatingEntity || !editingEntityName.trim()"
                :aria-label="t('sheetmusic', 'Save {entity}', { entity: config.singular }, { escape: false })"
                :title="t('sheetmusic', 'Save {entity}', { entity: config.singular }, { escape: false })"
                @click="saveEditEntity(entity)">
                <template #icon>
                  <CheckIcon :size="18" />
                </template>
              </NcButton>
              <NcButton
                size="small"
                :class="$style.actionButton"
                :disabled="isUpdatingEntity"
                :aria-label="t('sheetmusic', 'Cancel')"
                :title="t('sheetmusic', 'Cancel')"
                @click="cancelEditEntity">
                <template #icon>
                  <CloseIcon :size="18" />
                </template>
              </NcButton>
            </template>
            <template v-else>
              <NcButton
                size="small"
                :class="$style.actionButton"
                :aria-label="t('sheetmusic', 'Edit {entity}', { entity: config.singular }, { escape: false })"
                :title="t('sheetmusic', 'Edit {entity}', { entity: config.singular }, { escape: false })"
                @click="startEditEntity(entity)">
                <template #icon>
                  <PencilIcon :size="18" />
                </template>
              </NcButton>
              <NcButton
                size="small"
                :class="$style.actionButton"
                :aria-label="t('sheetmusic', 'Merge {entity}', { entity: config.singular }, { escape: false })"
                :title="t('sheetmusic', 'Merge {entity}', { entity: config.singular }, { escape: false })"
                @click="openMergeDialog(entity)">
                <template #icon>
                  <MergeIcon :size="18" />
                </template>
              </NcButton>
              <NcButton variant="error"
                        size="small"
                        :class="$style.deleteButton"
                        :aria-label="t('sheetmusic', 'Delete {entity}', { entity: config.singular }, { escape: false })"
                        :title="t('sheetmusic', 'Delete {entity}', { entity: config.singular }, { escape: false })"
                        @click="openDeleteDialog(entity)">
                <template #icon>
                  <DeleteIcon :size="18" />
                </template>
              </NcButton>
            </template>
          </div>
        </li>
        <li v-if="!isLoading && !fileError && visibleEntities.length === 0">
          {{ searchQuery.trim() && entities.length > 0
            ? t('sheetmusic', 'No {entities} match the search query.', { entities: config.plural.toLowerCase() }, { escape: false })
            : t('sheetmusic', 'No {entities} in this library yet.', { entities: config.plural.toLowerCase() }, { escape: false }) }}
        </li>
      </ul>
      <p v-if="fileError" :class="$style.error" role="alert">{{ fileError }}</p>
    </div>

    <NameInputDialog
      v-model:name="newEntityName"
      :open="isCreateDialogOpen"
      :title="t('sheetmusic', 'New {entity}', { entity: config.singular }, { escape: false })"
      :prompt="t('sheetmusic', 'The new {entity} can be assigned to songs right afterwards.', { entity: config.singular }, { escape: false })"
      :placeholder="namePlaceholder"
      :submit-label="t('sheetmusic', 'Create {entity}', { entity: config.singular }, { escape: false })"
      :is-submitting="isCreating"
      :error="createError"
      @close="closeCreateDialog"
      @submit="createEntity" />

    <ConfirmDeleteDialog
      :open="isDeleteDialogOpen"
      :title="t('sheetmusic', 'Delete {entity}', { entity: config.singular }, { escape: false })"
      :confirm-label="t('sheetmusic', 'Delete {entity}', { entity: config.singular }, { escape: false })"
      :is-deleting="isDeleting"
      :error="deleteError"
      :show-loading-icon="false"
      @close="closeDeleteDialog"
      @confirm="deleteEntity">
      <p>{{ t('sheetmusic', 'Delete "{name}"?', { name: entityToDelete ? entityLabel(entityToDelete) : '' }, { escape: false }) }}</p>
      <p>{{ t('sheetmusic', 'All songs stay in the library. Only the {entity} entry is removed from them.', { entity: config.singular }, { escape: false }) }}</p>
    </ConfirmDeleteDialog>

    <EntityMergeDialog
      :open="isMergeDialogOpen"
      :selected-library="selectedLibrary"
      :entity-type="entityType"
      :singular="config.singular"
      :plural="config.plural"
      :entities="entities"
      :entity-song-counts="entitySongCounts"
      :initial-entity="entityToMerge"
      @close="closeMergeDialog"
      @merged="handleEntityMerged" />
  </div>
</template>

<script setup lang="ts">
import type { EntityType, FilterEntity, LibraryData, Song } from '../types/song'

import { translate as t } from '@nextcloud/l10n'
import { computed, nextTick, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { loadState } from '@nextcloud/initial-state'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import MergeIcon from 'vue-material-design-icons/Merge.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import ConfirmDeleteDialog from './ConfirmDeleteDialog.vue'
import NameInputDialog from './NameInputDialog.vue'
import ViewHeader from './ViewHeader.vue'
import EntityMergeDialog from './EntityMergeDialog.vue'
import { ENTITY_COLUMNS, type SongEntityProp } from './songs/song-view-columns'
import { useLibraryData } from '../composables/useLibraryData'
import { apiDelete, apiPost } from '../api/client'
import { describeRequestFailure } from '../utils/api'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { normalizeSearchString } from '../utils/string'

const props = withDefaults(
  defineProps<{
    selectedLibrary: number
    /** Which of the song filters this view manages: composers, artists, books, collections, genres, keys, customgroups, years, signatures or sourcetypes. */
    entityType: EntityType
    canWrite?: boolean
  }>(),
  {
    canWrite: true,
  },
)

const isPublicShare = Boolean(loadState('sheetmusic', 'isPublicShare', false))
const effectiveCanWrite = computed(() => !isPublicShare && props.canWrite !== false)

const router = useRouter()

/** Everything this shared view needs to know about the entity kind it shows. */
interface EntityConfig {
  createEndpoint: string
  deleteEndpoint: string
  nameKey: 'name' | 'title'
  singular: string
  plural: string
}

const currentEntityCol = computed(() =>
  ENTITY_COLUMNS.find((col) => col.entityType === props.entityType) ?? ENTITY_COLUMNS[0],
)

const config = computed<EntityConfig>(() => {
  const col = currentEntityCol.value
  return {
    createEndpoint: col.endpoint,
    deleteEndpoint: `${col.endpoint}/{id}`,
    nameKey: col.nameKey,
    singular: col.singularLabel(),
    plural: col.pluralLabel(),
  }
})

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
  instruments,
  arrangers,
  fileError,
  isLoading,
  handleLibraryDataUpdate,
  loadLibraryData,
} = useLibraryData()

const libraryEntitiesByProp = {
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
  instruments,
  arrangers,
} satisfies Record<SongEntityProp, { value: FilterEntity[] }>

const entities = computed<FilterEntity[]>(() => {
  const prop = currentEntityCol.value?.songProp
  return prop ? libraryEntitiesByProp[prop].value : []
})

/** Entry whose delete dialog is open. */
const entityToDelete = ref<FilterEntity | null>(null)
const isCreateDialogOpen = ref(false)
const isDeleteDialogOpen = ref(false)
const isMergeDialogOpen = ref(false)
const entityToMerge = ref<FilterEntity | null>(null)
const isCreating = ref(false)
const isDeleting = ref(false)
const editingEntityId = ref<number | null>(null)
const editingEntityName = ref('')
const isUpdatingEntity = ref(false)
const editInputRef = ref<HTMLInputElement | HTMLInputElement[] | null>(null)
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
    return t('sheetmusic', 'Enter a title')
  }
  if (props.entityType === 'years') {
    return t('sheetmusic', 'Enter a year')
  }
  if (props.entityType === 'signatures') {
    return t('sheetmusic', 'Enter a time signature (e.g. 4/4)')
  }
  if (props.entityType === 'sourcetypes') {
    return t('sheetmusic', 'Enter a source type')
  }
  if (props.entityType === 'instruments') {
    return t('sheetmusic', 'Enter an instrument name')
  }
  return t('sheetmusic', 'Enter a name')
})

/** The entries of the kind this view manages that the song carries. */
function entitiesOfSong(song: Song): FilterEntity[] {
  return currentEntityCol.value.getItems(song)
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
    ? t('sheetmusic', 'song')
    : t('sheetmusic', 'songs')
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
    // An open delete or merge dialog belongs to the previous library or entity kind.
    closeDeleteDialog()
    closeMergeDialog()
    cancelEditEntity()
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
    const body = { libraryId: props.selectedLibrary, [config.value.nameKey]: name }

    await apiPost(postEndpoint as any, body as any)
    closeCreateDialog()
    await loadLibraryData(props.selectedLibrary)
    showSuccess(t('sheetmusic', '{entity} "{name}" created successfully.', { entity: config.value.singular, name }, { escape: false }))
  } catch {
    createError.value = t('sheetmusic', 'Could not create the {entity}.', { entity: config.value.singular }, { escape: false })
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
    const entityName = entityLabel(entity)
    const deleteEndpoint = config.value.deleteEndpoint
    const response = await apiDelete(deleteEndpoint as any, {
      pathParams: { id: entity.id },
      queryParams: { libraryId: props.selectedLibrary },
    })
    closeDeleteDialog()
    handleLibraryDataUpdate(response.data.ocs.data)
    if (entityName) {
      showSuccess(t('sheetmusic', '{entity} "{name}" deleted successfully.', { entity: config.value.singular, name: entityName }, { escape: false }))
    } else {
      showSuccess(t('sheetmusic', '{entity} deleted successfully.', { entity: config.value.singular }, { escape: false }))
    }
  } catch {
    deleteError.value = t('sheetmusic', 'Could not delete the {entity}.', { entity: config.value.singular }, { escape: false })
    showError(deleteError.value)
  } finally {
    isDeleting.value = false
  }
}

/*** Merging entities ***/
function openMergeDialog(entity?: FilterEntity) {
  entityToMerge.value = entity ?? null
  isMergeDialogOpen.value = true
}

function startEditEntity(entity: FilterEntity) {
  editingEntityId.value = entity.id
  editingEntityName.value = entityLabel(entity)
  nextTick(() => {
    const raw = editInputRef.value
    const input = Array.isArray(raw) ? raw[0] : raw
    input?.focus()
    input?.select()
  })
}

function cancelEditEntity() {
  editingEntityId.value = null
  editingEntityName.value = ''
}

async function saveEditEntity(entity: FilterEntity) {
  const nextName = editingEntityName.value.trim()
  if (!nextName || isUpdatingEntity.value) {
    return
  }
  if (nextName === entityLabel(entity)) {
    cancelEditEntity()
    return
  }

  isUpdatingEntity.value = true
  try {
    const response = await apiPost(
      '/api/entities/{id}' as any,
      {
        libraryId: props.selectedLibrary,
        entityType: props.entityType,
        [config.value.nameKey]: nextName,
      } as any,
      {
        pathParams: { id: entity.id },
      } as any,
    )
    handleLibraryDataUpdate((response.data?.ocs?.data ?? null) as LibraryData | null)
    showSuccess(t('sheetmusic', 'Updated "{name}".', { name: nextName }, { escape: false }))
    cancelEditEntity()
  } catch (e: unknown) {
    showError(describeRequestFailure(e, t('sheetmusic', 'Could not update {entity}.', { entity: config.value.singular }, { escape: false })))
  } finally {
    isUpdatingEntity.value = false
  }
}

function closeMergeDialog() {
  isMergeDialogOpen.value = false
  entityToMerge.value = null
}

function handleEntityMerged(newLibraryData?: LibraryData) {
  if (newLibraryData) {
    handleLibraryDataUpdate(newLibraryData)
  } else {
    loadLibraryData(props.selectedLibrary)
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
.rowActions {
  display: flex;
  align-items: center;
  gap: 4px;
}
.deleteButton,
.actionButton {
  flex-shrink: 0;
}
.editFieldWrapper {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
}
.inlineEditInput {
  width: 100%;
  min-width: 0;
  padding: 6px 8px;
  font: inherit;
  color: inherit;
  background: var(--color-main-background);
  border: 1px solid var(--color-primary-element);
  border-radius: var(--border-radius);
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