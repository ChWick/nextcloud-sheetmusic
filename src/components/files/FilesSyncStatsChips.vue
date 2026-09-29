<script setup lang="ts">
import type { SyncStats } from '../../utils/file-sync'
import { translate as t } from '@nextcloud/l10n'

defineProps<{
	stats: SyncStats
}>()
</script>

<template>
	<div>
		<div :class="$style.stats">
			<span :class="$style.chip">{{ t('mobilesheetsmanager', '{count} files checked', { count: stats.files }) }}</span>
			<span v-if="stats.moved" :class="$style.chip">
				{{ t('mobilesheetsmanager', '{count} moved', { count: stats.moved }) }}
			</span>
			<span v-if="stats.updated" :class="$style.chip">
				{{ t('mobilesheetsmanager', '{count} document properties updated', { count: stats.updated }) }}
			</span>
			<span v-if="stats.current" :class="$style.chip">
				{{ t('mobilesheetsmanager', '{count} already up to date', { count: stats.current }) }}
			</span>
			<span v-if="stats.warnings" :class="[$style.chip, $style.warningChip]">
				⚠ {{ t('mobilesheetsmanager', '{count} warnings', { count: stats.warnings }) }}
			</span>
			<span v-if="stats.errors" :class="[$style.chip, $style.errorChip]">
				✖ {{ t('mobilesheetsmanager', '{count} errors', { count: stats.errors }) }}
			</span>
		</div>
		<p v-if="stats.warnings === 0 && stats.errors === 0" :class="$style.allGood">
			{{ t('mobilesheetsmanager', 'Everything is up to date: file paths and metadata.') }}
		</p>
	</div>
</template>

<style module>
.stats {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 12px;
}

.chip {
	padding: 4px 12px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: 999px;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.warningChip {
	border-color: var(--color-warning);
	color: var(--color-warning);
}

.errorChip {
	border-color: var(--color-error);
	color: var(--color-error);
}

.allGood {
	margin: 0 0 12px;
	color: var(--color-success);
}
</style>
