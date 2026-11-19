<template>
  <template v-if="isEditInUrl && openSong">
    <NcButton
      variant="secondary"
      :title="t('sheetmusic', 'Save without closing')"
      :aria-label="t('sheetmusic', 'Save without closing')"
      @click="$emit('saveEdit', false)">
      <template #icon>
        <ContentSaveOutlineIcon :size="20" />
      </template>
    </NcButton>
    <NcButton
      variant="primary"
      :title="t('sheetmusic', 'Save and close')"
      :aria-label="t('sheetmusic', 'Save and close')"
      @click="$emit('saveEdit', true)">
      <template #icon>
        <CheckIcon :size="20" />
      </template>
    </NcButton>
    <NcActions :aria-label="t('sheetmusic', 'More song actions')">
      <NcActionButton :close-after-click="true" @click="$emit('openHistory')">
        <template #icon>
          <History :size="20" />
        </template>
        {{ t('sheetmusic', 'Song history & revisions') }}
      </NcActionButton>
      <NcActionButton :close-after-click="true" @click="$emit('deleteEdit')">
        <template #icon>
          <DeleteIcon :size="20" />
        </template>
        {{ t('sheetmusic', 'Delete song') }}
      </NcActionButton>
    </NcActions>
  </template>
  <template v-else-if="standaloneCreate">
    <NcButton
      variant="secondary"
      :title="t('sheetmusic', 'Cancel')"
      :aria-label="t('sheetmusic', 'Cancel')"
      @click="$emit('cancelCreate')">
      <template #icon>
        <CloseIcon :size="20" />
      </template>
    </NcButton>
    <NcButton
      variant="primary"
      :title="t('sheetmusic', 'Create song')"
      :aria-label="t('sheetmusic', 'Create song')"
      @click="$emit('saveCreate')">
      <template #icon>
        <CheckIcon :size="20" />
      </template>
    </NcButton>
  </template>
  <template v-else-if="!standaloneCreate && !isEditInUrl && !isModifyPdfInUrl">
    <NcButton
      v-if="canWrite"
      variant="secondary"
      :title="t('sheetmusic', 'Edit song')"
      :aria-label="t('sheetmusic', 'Edit song')"
      @click="$emit('edit')">
      <template #icon>
        <PencilIcon :size="20" />
      </template>
    </NcButton>
    <NcActions
      v-if="canWrite"
      :aria-label="t('sheetmusic', 'More song actions')">
      <NcActionButton v-if="openSong" :close-after-click="true" @click="$emit('openHistory')">
        <template #icon>
          <History :size="20" />
        </template>
        {{ t('sheetmusic', 'Song history & revisions') }}
      </NcActionButton>
      <NcActionButton v-if="openSong" :close-after-click="true" @click="$emit('duplicate')">
        <template #icon>
          <ContentCopyIcon :size="20" />
        </template>
        {{ t('sheetmusic', 'Duplicate song') }}
      </NcActionButton>
      <NcActionButton :close-after-click="true" @click="$emit('deleteView')">
        <template #icon>
          <DeleteIcon :size="20" />
        </template>
        {{ t('sheetmusic', 'Delete song') }}
      </NcActionButton>
    </NcActions>
  </template>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import ContentSaveOutlineIcon from 'vue-material-design-icons/ContentSaveOutline.vue'
import History from 'vue-material-design-icons/History.vue'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import type { Song } from '../../types/song'

const props = withDefaults(defineProps<{
	isEditInUrl: boolean
	openSong: Song | null
	standaloneCreate: boolean
	isModifyPdfInUrl: boolean
	canWrite?: boolean
}>(), {
	canWrite: true,
})

defineEmits<{
	deleteEdit: []
	openHistory: []
	saveEdit: [close: boolean]
	cancelCreate: []
	saveCreate: []
	duplicate: []
	edit: []
	deleteView: []
}>()
</script>
