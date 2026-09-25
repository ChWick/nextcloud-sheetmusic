<template>
  <header :class="$style.headerBar">
    <h2 :class="$style.title">{{ title }}</h2>
    <div :class="$style.actions">
      <NcButton v-if="createLabel"
                variant="primary"
                @click="emit('create')">
        {{ createLabel }}
      </NcButton>
      <NcButton v-if="filterBarVisible !== undefined"
                :aria-pressed="filterBarVisible"
                @click="emit('toggle-filters')">
        {{ filterBarVisible ? t('mobilesheetsmanager', 'Hide filters') : t('mobilesheetsmanager', 'Show filters') }}
      </NcButton>
    </div>
  </header>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'

defineProps<{
  /** Title of the view, at the left of the header bar. */
  title: string
  /** Label of the view's create button; the button is hidden when unset. */
  createLabel?: string
  /** Whether the filter bar is shown; the toggle is hidden when unset. */
  filterBarVisible?: boolean
}>()

const emit = defineEmits<{
  /** The view's create button was pressed. */
  create: []
  /** The filter bar should be shown or hidden. */
  'toggle-filters': []
}>()
</script>

<style module>
.headerBar {
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: var(--default-clickable-area, 44px);
  /* The navigation toggle floats over the left edge of the header bar; keep the
     content of the header clear of it. */
  padding: 4px 16px 4px calc(var(--default-clickable-area, 44px) + 16px);
  border-bottom: 1px solid var(--color-border);
}

.title {
  margin: 0;
  font-size: 1.2rem;
  font-weight: 600;
}

.actions {
  display: flex;
  gap: 8px;
  margin-inline-start: auto;
}
</style>