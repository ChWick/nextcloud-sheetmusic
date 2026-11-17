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
      <NcButton
        v-if="itemCount > 0"
        variant="primary"
        :aria-label="t('sheetmusic', 'Play Setlist')"
        :title="t('sheetmusic', 'Play Setlist')"
        @click="emit('play')">
        <template #icon>
          <PlayIcon :size="20" />
        </template>
        {{ t('sheetmusic', 'Play Setlist') }}
      </NcButton>
      <NcButton
        v-if="canWrite !== false"
        :variant="itemCount > 0 ? 'secondary' : 'primary'"
        :aria-label="t('sheetmusic', 'Add Song / Bookmark')"
        :title="t('sheetmusic', 'Add Song / Bookmark')"
        @click="emit('add-item')">
        <template #icon>
          <PlusIcon :size="20" />
        </template>
        {{ t('sheetmusic', 'Add Song / Bookmark') }}
      </NcButton>
    </div>
  </div>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import PlayIcon from 'vue-material-design-icons/Play.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'

withDefaults(
  defineProps<{
    name: string
    itemCount: number
    totalDurationFormatted?: string | null
    canWrite?: boolean
  }>(),
  {
    canWrite: true,
  },
)

const emit = defineEmits<{
  'add-item': []
  play: []
}>()
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

@container (max-width: 560px) {
  .actions :global(.button-vue__text) {
    display: none;
  }

  .actions :global(.button-vue) {
    --button-padding: var(--button-radius);
    line-height: 1;
    width: var(--button-size, var(--default-clickable-area, 44px)) !important;
  }
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
