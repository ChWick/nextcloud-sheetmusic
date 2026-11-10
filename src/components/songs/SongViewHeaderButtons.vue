<template>
  <template v-if="isEditInUrl && openSong">
    <NcButton
      variant="error"
      :title="t('sheetmusic', 'Delete song')"
      :aria-label="t('sheetmusic', 'Delete song')"
      @click="$emit('deleteEdit')">
      <template #icon>
        <DeleteIcon :size="20" />
      </template>
    </NcButton>
    <NcButton
      variant="secondary"
      :title="t('sheetmusic', 'Song history & revisions')"
      :aria-label="t('sheetmusic', 'Song history & revisions')"
      @click="$emit('openHistory')">
      <template #icon>
        <History :size="20" />
      </template>
    </NcButton>
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
      v-if="openSong"
      variant="secondary"
      :title="t('sheetmusic', 'Song history & revisions')"
      :aria-label="t('sheetmusic', 'Song history & revisions')"
      @click="$emit('openHistory')">
      <template #icon>
        <History :size="20" />
      </template>
    </NcButton>
    <NcButton
      v-if="openSong && canWrite"
      variant="secondary"
      :title="t('sheetmusic', 'Duplicate song')"
      :aria-label="t('sheetmusic', 'Duplicate song')"
      @click="$emit('duplicate')">
      <template #icon>
        <ContentCopyIcon :size="20" />
      </template>
    </NcButton>
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
    <NcButton
      v-if="canWrite"
      variant="error"
      :title="t('sheetmusic', 'Delete song')"
      :aria-label="t('sheetmusic', 'Delete song')"
      @click="$emit('deleteView')">
      <template #icon>
        <DeleteIcon :size="20" />
      </template>
    </NcButton>
  </template>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
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
