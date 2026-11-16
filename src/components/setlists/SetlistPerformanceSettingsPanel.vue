<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'

defineProps<{
	open: boolean
	setlistName: string
	isSetlistOverride: boolean
}>()

const emit = defineEmits<{
	close: []
	'update:isSetlistOverride': [enabled: boolean]
	saveAsLibraryDefault: []
}>()
</script>

<template>
	<aside
		v-if="open"
		:class="$style.settingsPanel"
		role="region"
		:aria-label="t('sheetmusic', 'Viewer settings')">
		<div :class="$style.panelHeader">
			<strong>{{ t('sheetmusic', 'Viewer Settings') }}</strong>
			<NcButton variant="tertiary" size="small" @click="emit('close')">
				{{ t('sheetmusic', 'Close') }}
			</NcButton>
		</div>
		<div :class="$style.settingsBody">
			<p :class="$style.settingsHint">
				{{ t('sheetmusic', 'Choose whether layout, page-turning, and zoom changes apply to all setlists in this library or only this setlist.') }}
			</p>

			<button
				type="button"
				:class="[$style.scopeOption, !isSetlistOverride && $style.scopeOptionActive]"
				:aria-pressed="!isSetlistOverride"
				data-settings-scope="library"
				@click="emit('update:isSetlistOverride', false)">
				<strong>{{ t('sheetmusic', 'Use library defaults') }}</strong>
				<span>{{ t('sheetmusic', 'Changes are saved as the default for all setlists in this library.') }}</span>
			</button>

			<button
				type="button"
				:class="[$style.scopeOption, isSetlistOverride && $style.scopeOptionActive]"
				:aria-pressed="isSetlistOverride"
				data-settings-scope="setlist"
				@click="emit('update:isSetlistOverride', true)">
				<strong>{{ t('sheetmusic', 'Custom settings for this setlist') }}</strong>
				<span>{{ t('sheetmusic', 'Changes apply only to "{name}" and override library defaults.', { name: setlistName }, { escape: false }) }}</span>
			</button>

			<NcButton
				v-if="isSetlistOverride"
				variant="secondary"
				size="small"
				@click="emit('saveAsLibraryDefault')">
				{{ t('sheetmusic', 'Save current settings as library default') }}
			</NcButton>
		</div>
	</aside>
</template>

<style module>
.settingsPanel {
	position: fixed;
	top: 0;
	right: 0;
	bottom: 0;
	width: min(360px, 90vw);
	z-index: 100001;
	display: flex;
	flex-direction: column;
	background-color: var(--color-main-background);
	border-left: 1px solid var(--color-border);
	box-shadow: -4px 0 16px rgba(0, 0, 0, 0.2);
}

.panelHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
}

.settingsBody {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
	overflow-y: auto;
}

.settingsHint {
	margin: 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	line-height: 1.4;
}

.scopeOption {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 4px;
	padding: 12px;
	text-align: left;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 0.9rem;
}

.scopeOption span {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	line-height: 1.35;
}

.scopeOption:hover {
	background-color: var(--color-background-hover);
}

.scopeOptionActive {
	border-color: var(--color-primary-element);
	background-color: var(--color-primary-light);
}
</style>
