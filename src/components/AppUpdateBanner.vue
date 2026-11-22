<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import DownloadOutline from 'vue-material-design-icons/DownloadOutline.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import { useAppUpdate } from '../composables/useAppUpdate'

const {
	updateProgress,
	isUpdateReady,
	statusLabel,
	progressDetail,
	applyUpdate,
	dismissUpdate,
} = useAppUpdate()
</script>

<template>
	<div
		v-if="updateProgress.active && !updateProgress.dismissed"
		:class="$style.banner"
		role="status"
		aria-live="polite"
		data-test="app-update-banner">
		<div :class="$style.header">
			<RefreshIcon v-if="isUpdateReady" :size="18" :class="$style.icon" />
			<DownloadOutline v-else :size="18" :class="$style.icon" />
			<span :class="$style.title">{{ statusLabel }}</span>
			<span v-if="!isUpdateReady" :class="$style.percent">{{ updateProgress.percent }}%</span>
		</div>
		<template v-if="isUpdateReady">
			<div :class="$style.actions">
				<NcButton
					type="button"
					variant="tertiary"
					size="small"
					data-test="update-later-btn"
					@click="dismissUpdate">
					{{ t('sheetmusic', 'Later') }}
				</NcButton>
				<NcButton
					type="button"
					variant="primary"
					size="small"
					data-test="update-reload-btn"
					@click="applyUpdate">
					{{ t('sheetmusic', 'Reload') }}
				</NcButton>
			</div>
		</template>
		<template v-else>
			<NcProgressBar
				:value="updateProgress.percent"
				size="small"
				:show-value="false" />
			<p v-if="progressDetail" :class="$style.detail">
				{{ progressDetail }}
			</p>
		</template>
	</div>
</template>

<style module>
.banner {
	position: fixed;
	right: 16px;
	bottom: 16px;
	z-index: 10050;
	width: min(340px, calc(100vw - 32px));
	padding: 12px 14px;
	border-radius: var(--border-radius-large, 10px);
	background-color: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	border: 1px solid var(--color-border, #ddd);
	box-shadow: 0 6px 20px rgba(0, 0, 0, 0.18);
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.header {
	display: flex;
	align-items: center;
	gap: 8px;
}

.icon {
	color: var(--color-primary-element, #0082c9);
	flex-shrink: 0;
}

.title {
	flex: 1;
	font-size: 0.9rem;
	font-weight: 600;
	min-width: 0;
}

.percent {
	font-size: 0.85rem;
	font-weight: 600;
	font-variant-numeric: tabular-nums;
	color: var(--color-text-maxcontrast, #555);
}

.detail {
	margin: 0;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast, #555);
	font-variant-numeric: tabular-nums;
}

.actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
