<template>
  <header :class="$style.headerBar">
    <h2 :class="$style.title">{{ title }}</h2>
    <div :class="$style.actions">
      <slot />
      <NcButton v-if="filterBarVisible !== undefined"
                :aria-pressed="filterBarVisible"
                :title="filterBarVisible ? t('mobilesheetsmanager', 'Hide filters') : t('mobilesheetsmanager', 'Show filters')"
                :aria-label="filterBarVisible ? t('mobilesheetsmanager', 'Hide filters') : t('mobilesheetsmanager', 'Show filters')"
                @click="emit('toggle-filters')">
        <template #icon>
          <FilterVariantIcon :size="18" />
        </template>
      </NcButton>
      <NcActions
        v-if="createLabel || importLabel"
        :name="t('mobilesheetsmanager', 'Add or import song')"
        :aria-label="t('mobilesheetsmanager', 'Add or import song')">
        <template #icon>
          <PlusIcon :size="20" />
        </template>
        <NcActionButton v-if="createLabel" @click="emit('create')">
          <template #icon>
            <PlusIcon :size="20" />
          </template>
          {{ createLabel }}
        </NcActionButton>
        <NcActionButton v-if="importLabel" @click="emit('import')">
          <template #icon>
            <UploadIcon :size="20" />
          </template>
          {{ importLabel }}
        </NcActionButton>
      </NcActions>
    </div>
  </header>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import UploadIcon from 'vue-material-design-icons/Upload.vue'
import FilterVariantIcon from 'vue-material-design-icons/FilterVariant.vue'

defineProps<{
  /** Title of the view, at the left of the header bar. */
  title: string
  /** Label of the view's create button; the button is hidden when unset. */
  createLabel?: string
  /** Label of the view's import button; the button is hidden when unset. */
  importLabel?: string
  /** Whether the filter bar is shown; the toggle is hidden when unset. */
  filterBarVisible?: boolean
}>()

const emit = defineEmits<{
  /** The view's create button was pressed. */
  create: []
  /** The view's import button was pressed. */
  import: []
  /** The filter bar should be shown or hidden. */
  'toggle-filters': []
}>()
</script>

<style module>
.headerBar {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: var(--default-clickable-area, 44px);
  padding: 4px 16px 4px calc(var(--default-clickable-area, 44px) + 16px);
  border-bottom: 1px solid var(--color-border);
  flex-wrap: wrap;
}

.title {
  margin: 0;
  font-size: 1.2rem;
  font-weight: 600;
}

.actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-inline-start: auto;
  flex-wrap: wrap;
}
</style>