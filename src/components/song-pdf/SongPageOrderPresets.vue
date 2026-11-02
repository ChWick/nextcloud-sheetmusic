<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import Restore from 'vue-material-design-icons/Restore.vue'

withDefaults(
	defineProps<{
		totalPages?: number
		disabled?: boolean
	}>(),
	{
		totalPages: 1,
		disabled: false,
	},
)

const emit = defineEmits<{
	select: [preset: 'all' | 'reverse' | 'repeat']
}>()
</script>

<template>
	<div :class="$style.presetsSection">
		<span :class="$style.sectionLabel">{{ t('sheetmusic', 'Presets:') }}</span>
		<div :class="$style.presetButtons">
			<NcButton
				size="small"
				variant="tertiary"
				:disabled="disabled"
				@click="emit('select', 'all')">
				<template #icon>
					<Restore :size="16" />
				</template>
				{{ t('sheetmusic', 'All Pages (1-{count})', { count: totalPages }) }}
			</NcButton>
			<NcButton
				size="small"
				variant="tertiary"
				:disabled="disabled"
				@click="emit('select', 'repeat')">
				{{ t('sheetmusic', 'Repeat All (1-{count}, 1-{count})', { count: totalPages }) }}
			</NcButton>
			<NcButton
				v-if="totalPages > 1"
				size="small"
				variant="tertiary"
				:disabled="disabled"
				@click="emit('select', 'reverse')">
				{{ t('sheetmusic', 'Reverse Order') }}
			</NcButton>
		</div>
	</div>
</template>

<style module>
.presetsSection {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.sectionLabel {
	font-weight: 600;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.presetButtons {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
