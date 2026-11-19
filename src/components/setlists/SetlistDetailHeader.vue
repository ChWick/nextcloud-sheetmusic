<template>
  <div :class="$style.detailHeader">
    <div :class="$style.detailTitleArea">
      <h2 :class="$style.detailTitle">{{ name }}</h2>
      <span :class="$style.detailSubtitle">
        {{ t('sheetmusic', '{count} items', { count: itemCount }, { escape: false }) }}
        <span v-if="totalDurationFormatted"> • {{ totalDurationFormatted }}</span>
      </span>
    </div>
    <div :class="$style.actions">
      <ResponsiveNcButton
        v-if="itemCount > 0"
        variant="primary"
        collapse-at="container-560"
        :label="t('sheetmusic', 'Play Setlist')"
        @click="emit('play')">
        <template #icon>
          <PlayIcon :size="20" />
        </template>
      </ResponsiveNcButton>
      <ResponsiveNcButton
        v-if="offlineSupported"
        :variant="isOffline ? 'secondary' : 'tertiary'"
        collapse-at="container-560"
        :disabled="isSyncingOffline"
        :aria-pressed="isOffline"
        :label="offlineButtonLabel"
        @click="emit('toggle-offline')">
        <template #icon>
          <NcLoadingIcon v-if="isSyncingOffline" :size="18" />
          <CloudCheckOutline v-else-if="isOffline" :size="20" />
          <CloudDownloadOutline v-else :size="20" />
        </template>
      </ResponsiveNcButton>
      <ResponsiveNcButton
        v-if="canWrite !== false"
        :variant="itemCount > 0 ? 'secondary' : 'primary'"
        collapse-at="container-560"
        :label="t('sheetmusic', 'Add Song / Bookmark')"
        @click="emit('add-item')">
        <template #icon>
          <PlusIcon :size="20" />
        </template>
      </ResponsiveNcButton>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ResponsiveNcButton from '../ResponsiveNcButton.vue'
import CloudCheckOutline from 'vue-material-design-icons/CloudCheckOutline.vue'
import CloudDownloadOutline from 'vue-material-design-icons/CloudDownloadOutline.vue'
import PlayIcon from 'vue-material-design-icons/Play.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'

const props = withDefaults(
  defineProps<{
    name: string
    itemCount: number
    totalDurationFormatted?: string | null
    canWrite?: boolean
    offlineSupported?: boolean
    isOffline?: boolean
    isSyncingOffline?: boolean
    offlineProgressText?: string
  }>(),
  {
    canWrite: true,
    offlineSupported: false,
    isOffline: false,
    isSyncingOffline: false,
    offlineProgressText: '',
  },
)

const emit = defineEmits<{
  'add-item': []
  play: []
  'toggle-offline': []
}>()

const offlineButtonLabel = computed(() => {
  if (props.isSyncingOffline) {
    return props.offlineProgressText || t('sheetmusic', 'Saving offline…')
  }
  return props.isOffline
    ? t('sheetmusic', 'Available offline')
    : t('sheetmusic', 'Save offline')
})
</script>

<style module>
.detailHeader {
  container-type: inline-size;
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  border-bottom: 1px solid var(--color-border);
  padding-bottom: 12px;
}

.actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  flex-shrink: 0;
  margin-left: auto;
}

.detailTitleArea {
  display: flex;
  flex-direction: column;
  gap: 4px;
  flex: 1 1 260px;
  min-width: 0;
}

.detailTitle {
  margin: 0;
  font-size: 1.4rem;
  font-weight: 600;
}

.detailSubtitle {
  font-size: 0.9rem;
  color: var(--color-text-maxcontrast);
}
</style>
