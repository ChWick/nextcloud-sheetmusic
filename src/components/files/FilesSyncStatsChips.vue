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
			<span :class="$style.chip">{{ t('sheetmusic', '{count} files checked', { count: stats.files }, { escape: false }) }}</span>
			<span v-if="stats.moved" :class="$style.chip">
				{{ t('sheetmusic', '{count} moved', { count: stats.moved }, { escape: false }) }}
			</span>
			<span v-if="stats.updated" :class="$style.chip">
				{{ t('sheetmusic', '{count} document properties updated', { count: stats.updated }, { escape: false }) }}
			</span>
			<span v-if="stats.current" :class="$style.chip">
				{{ t('sheetmusic', '{count} already up to date', { count: stats.current }, { escape: false }) }}
			</span>
			<span v-if="stats.warnings" :class="[$style.chip, $style.warningChip]">
				⚠ {{ t('sheetmusic', '{count} warnings', { count: stats.warnings }, { escape: false }) }}
			</span>
			<span v-if="stats.errors" :class="[$style.chip, $style.errorChip]">
				✖ {{ t('sheetmusic', '{count} errors', { count: stats.errors }, { escape: false }) }}
			</span>
		</div>
		<p v-if="stats.warnings === 0 && stats.errors === 0" :class="$style.allGood">
			{{ t('sheetmusic', 'Everything is up to date: file paths and metadata.') }}
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
