<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import CheckIcon from 'vue-material-design-icons/Check.vue'

withDefaults(
	defineProps<{
		pageNum: number
		allPagesCount: number
		applyScope?: 'current' | 'all'
	}>(),
	{
		applyScope: 'current',
	},
)

const emit = defineEmits<{
	'update:applyScope': [scope: 'current' | 'all']
	'cancel': []
	'apply': []
}>()
</script>

<template>
	<div :class="$style.footer">
		<!-- Scope selector -->
		<div :class="$style.scopeSelector">
			<span :class="$style.scopeLabel">{{ t('sheetmusic', 'Apply crop to:') }}</span>
			<label :class="$style.radioOption">
				<input
					type="radio"
					value="current"
					name="cropScope"
					:checked="applyScope === 'current'"
					@change="emit('update:applyScope', 'current')">
				<span>{{ t('sheetmusic', 'Page {page} only', { page: pageNum }, { escape: false }) }}</span>
			</label>
			<label :class="$style.radioOption">
				<input
					type="radio"
					value="all"
					name="cropScope"
					:checked="applyScope === 'all'"
					@change="emit('update:applyScope', 'all')">
				<span>{{ t('sheetmusic', 'All pages ({count})', { count: allPagesCount }, { escape: false }) }}</span>
			</label>
		</div>

		<!-- Action buttons -->
		<div :class="$style.actionButtons">
			<NcButton
				variant="secondary"
				@click="emit('cancel')">
				{{ t('sheetmusic', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				@click="emit('apply')">
				<template #icon>
					<CheckIcon :size="18" />
				</template>
				{{ t('sheetmusic', 'Apply Crop') }}
			</NcButton>
		</div>
	</div>
</template>

<style module>
.footer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
	flex-wrap: wrap;
	gap: 12px;
	flex-shrink: 0;
}

.scopeSelector {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
}

.scopeLabel {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-main-text);
}

.radioOption {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
	cursor: pointer;
	color: var(--color-main-text);
}

.actionButtons {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-left: auto;
}

@media (max-width: 600px) {
	.footer {
		padding-top: 8px;
		gap: 8px;
	}

	.scopeSelector {
		gap: 8px;
		font-size: 12px;
	}
}
</style>
