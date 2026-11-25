<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import CropIcon from 'vue-material-design-icons/Crop.vue'
import { PERFORMANCE_BACKGROUND_PRESETS } from '../../utils/performance-settings'

withDefaults(defineProps<{
	open: boolean
	setlistName?: string
	showSetlistScope?: boolean
	isSetlistOverride: boolean
	backgroundColor?: string
	tightCrop?: boolean
}>(), {
	setlistName: '',
	showSetlistScope: true,
	backgroundColor: '',
	tightCrop: false,
})

const emit = defineEmits<{
	close: []
	'update:isSetlistOverride': [enabled: boolean]
	'update:backgroundColor': [color: string]
	'update:tightCrop': [enabled: boolean]
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
			<!-- Display: Background Color -->
			<section :class="$style.section">
				<strong :class="$style.sectionTitle">{{ t('sheetmusic', 'Background color') }}</strong>
				<div :class="$style.swatchGrid">
					<button
						v-for="preset in PERFORMANCE_BACKGROUND_PRESETS"
						:key="preset.value || 'default'"
						type="button"
						:class="[$style.swatchBtn, backgroundColor === preset.value && $style.swatchBtnActive]"
						:title="preset.label"
						:aria-label="preset.label"
						:aria-pressed="backgroundColor === preset.value"
						:data-test="`performance-bg-preset-${preset.value || 'default'}`"
						@click="emit('update:backgroundColor', preset.value)">
						<span :class="$style.swatchChip" :style="{ backgroundColor: preset.previewColor }" />
						<span :class="$style.swatchLabel">{{ preset.label }}</span>
					</button>
				</div>
				<label :class="$style.customColorRow">
					<span>{{ t('sheetmusic', 'Custom color') }}</span>
					<input
						type="color"
						:value="backgroundColor || '#ffffff'"
						:class="$style.colorInput"
						:aria-label="t('sheetmusic', 'Custom background color')"
						data-test="performance-bg-custom"
						@input="emit('update:backgroundColor', ($event.target as HTMLInputElement).value)">
				</label>
			</section>

			<!-- Display: Tight On-the-Fly Cropping -->
			<section :class="$style.section">
				<strong :class="$style.sectionTitle">{{ t('sheetmusic', 'Page cropping') }}</strong>
				<button
					type="button"
					:class="[$style.scopeOption, tightCrop && $style.scopeOptionActive]"
					:aria-pressed="tightCrop"
					data-test="performance-tight-crop-toggle"
					@click="emit('update:tightCrop', !tightCrop)">
					<span :class="$style.toggleTitle">
						<CropIcon :size="18" />
						<strong>{{ t('sheetmusic', 'Tight on-the-fly crop') }}</strong>
					</span>
					<span>{{ t('sheetmusic', 'Automatically trim blank PDF margins so notes fill more of the screen.') }}</span>
				</button>
			</section>

			<!-- Scope (for setlists) -->
			<section v-if="showSetlistScope" :class="$style.section">
				<strong :class="$style.sectionTitle">{{ t('sheetmusic', 'Settings scope') }}</strong>
				<p :class="$style.settingsHint">
					{{ t('sheetmusic', 'Choose whether layout, page-turning, zoom, and display changes apply to all setlists in this library or only this setlist.') }}
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
			</section>
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
	gap: 16px;
	padding: 16px;
	overflow-y: auto;
}

.section {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.sectionTitle {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.swatchGrid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 6px;
}

.swatchBtn {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.82rem;
	cursor: pointer;
	text-align: left;
}

.swatchBtn:hover {
	background: var(--color-background-hover);
}

.swatchBtnActive {
	border-color: var(--color-primary-element);
	background: var(--color-primary-light);
	font-weight: 600;
}

.swatchChip {
	width: 18px;
	height: 18px;
	border-radius: 50%;
	border: 1px solid var(--color-border-dark, rgba(0, 0, 0, 0.25));
	flex-shrink: 0;
}

.swatchLabel {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.customColorRow {
	display: flex;
	align-items: center;
	justify-content: space-between;
	font-size: 0.84rem;
	padding: 2px 4px;
	cursor: pointer;
}

.colorInput {
	width: 36px;
	height: 28px;
	padding: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: transparent;
	cursor: pointer;
}

.toggleTitle {
	display: inline-flex;
	align-items: center;
	gap: 6px;
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
