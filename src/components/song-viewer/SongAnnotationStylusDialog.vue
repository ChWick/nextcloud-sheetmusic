<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<script setup lang="ts">
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { useStylusSettings } from '../../composables/useStylusSettings'
import type { PressureMode } from '../../utils/stylus-settings'
import StylusPressureScratchpad from './StylusPressureScratchpad.vue'
import StylusTipCalibrationSection from './StylusTipCalibrationSection.vue'

const emit = defineEmits<{
	close: []
}>()

const {
	settings,
	penDetected,
	updateSettings,
	resetSettings,
	markPenDetected,
} = useStylusSettings()

const tipCalibrationRef = ref<InstanceType<typeof StylusTipCalibrationSection> | null>(null)
const scratchpadRef = ref<InstanceType<typeof StylusPressureScratchpad> | null>(null)

const PRESSURE_MODES = computed<Array<{ value: PressureMode; label: string }>>(() => [
	{ value: 'auto', label: t('sheetmusic', 'Auto') },
	{ value: 'hardware', label: t('sheetmusic', 'Hardware') },
	{ value: 'velocity', label: t('sheetmusic', 'Velocity') },
	{ value: 'off', label: t('sheetmusic', 'Off') },
])

const gammaLabel = computed(() => {
	const g = settings.value.pressureGamma
	if (g < 0.85) return t('sheetmusic', 'Soft ({val})', { val: g.toFixed(2) })
	if (g > 1.15) return t('sheetmusic', 'Firm ({val})', { val: g.toFixed(2) })
	return t('sheetmusic', 'Linear ({val})', { val: g.toFixed(2) })
})

function handleResetAll() {
	resetSettings()
	tipCalibrationRef.value?.resetUiState()
	scratchpadRef.value?.resetUiState()
}
</script>

<template>
	<NcDialog
		:name="t('sheetmusic', 'Stylus & Pressure Calibration')"
		size="normal"
		:close-on-click-outside="true"
		data-test="stylus-calibration-dialog"
		@closing="emit('close')">
		<div :class="$style.dialogBody">
			<!-- Section 1: Input & Palm Rejection -->
			<section :class="$style.section">
				<h3 :class="$style.sectionTitle">
					{{ t('sheetmusic', 'Input & Palm Rejection') }}
				</h3>
				<div :class="$style.toggles">
					<NcCheckboxRadioSwitch
						type="switch"
						:checked="settings.stylusOnlyDrawing"
						data-test="stylus-only-switch"
						@update:checked="updateSettings({ stylusOnlyDrawing: $event })">
						{{ t('sheetmusic', 'Stylus-only drawing (ignore finger touches so you can scroll & zoom)') }}
					</NcCheckboxRadioSwitch>

					<NcCheckboxRadioSwitch
						type="switch"
						:checked="settings.autoPalmRejection"
						data-test="auto-palm-switch"
						@update:checked="updateSettings({ autoPalmRejection: $event })">
						<span>
							{{ t('sheetmusic', 'Auto-enable palm rejection when a stylus is detected') }}
							<span v-if="penDetected" :class="$style.badge">
								{{ t('sheetmusic', 'Stylus active') }}
							</span>
						</span>
					</NcCheckboxRadioSwitch>

					<NcCheckboxRadioSwitch
						type="switch"
						:checked="settings.hardwareEraser"
						data-test="hardware-eraser-switch"
						@update:checked="updateSettings({ hardwareEraser: $event })">
						{{ t('sheetmusic', 'Use stylus eraser end to erase annotations') }}
					</NcCheckboxRadioSwitch>

					<NcCheckboxRadioSwitch
						type="switch"
						:checked="settings.lockTouchZoomWhileAnnotating"
						data-test="lock-touch-zoom-switch"
						@update:checked="updateSettings({ lockTouchZoomWhileAnnotating: $event })">
						{{ t('sheetmusic', 'Lock touch zoom & pan while annotating (prevents palm jitter)') }}
					</NcCheckboxRadioSwitch>
				</div>
			</section>

			<!-- Section 2: Tip Offset Calibration -->
			<StylusTipCalibrationSection
				ref="tipCalibrationRef"
				:offset-x="settings.offsetX"
				:offset-y="settings.offsetY"
				@update-settings="updateSettings"
				@pen-detected="markPenDetected" />

			<!-- Section 3: Pressure & Feel -->
			<section :class="$style.section">
				<h3 :class="$style.sectionTitle">
					{{ t('sheetmusic', 'Pressure Sensitivity') }}
				</h3>

				<div :class="$style.modeRow" role="radiogroup" :aria-label="t('sheetmusic', 'Pressure mode')">
					<button
						v-for="mode in PRESSURE_MODES"
						:key="mode.value"
						type="button"
						:class="[$style.modeBtn, settings.pressureMode === mode.value && $style.modeActive]"
						:data-test="`pressure-mode-${mode.value}`"
						@click="updateSettings({ pressureMode: mode.value })">
						{{ mode.label }}
					</button>
				</div>

				<div v-if="settings.pressureMode !== 'off'" :class="$style.sliderGrid">
					<label :class="$style.sliderField">
						<span :class="$style.sliderLabel">
							{{ t('sheetmusic', 'Width variation') }}
							<strong>{{ Math.round(settings.pressureSensitivity * 100) }}%</strong>
						</span>
						<input
							type="range"
							min="0"
							max="1"
							step="0.05"
							:value="settings.pressureSensitivity"
							data-test="pressure-sensitivity-slider"
							@input="updateSettings({ pressureSensitivity: Number(($event.target as HTMLInputElement).value) })">
					</label>

					<label :class="$style.sliderField">
						<span :class="$style.sliderLabel">
							{{ t('sheetmusic', 'Response curve') }}
							<strong>{{ gammaLabel }}</strong>
						</span>
						<input
							type="range"
							min="0.3"
							max="3"
							step="0.05"
							:value="settings.pressureGamma"
							data-test="pressure-gamma-slider"
							@input="updateSettings({ pressureGamma: Number(($event.target as HTMLInputElement).value) })">
					</label>

					<label :class="$style.sliderField">
						<span :class="$style.sliderLabel">
							{{ t('sheetmusic', 'Min pressure') }}
							<strong>{{ settings.pressureMin.toFixed(2) }}</strong>
						</span>
						<input
							type="range"
							min="0"
							max="0.8"
							step="0.02"
							:value="settings.pressureMin"
							data-test="pressure-min-slider"
							@input="updateSettings({ pressureMin: Number(($event.target as HTMLInputElement).value) })">
					</label>

					<label :class="$style.sliderField">
						<span :class="$style.sliderLabel">
							{{ t('sheetmusic', 'Max pressure') }}
							<strong>{{ settings.pressureMax.toFixed(2) }}</strong>
						</span>
						<input
							type="range"
							min="0.2"
							max="1"
							step="0.02"
							:value="settings.pressureMax"
							data-test="pressure-max-slider"
							@input="updateSettings({ pressureMax: Number(($event.target as HTMLInputElement).value) })">
					</label>
				</div>

				<StylusPressureScratchpad
					ref="scratchpadRef"
					:settings="settings"
					@update-settings="updateSettings"
					@pen-detected="markPenDetected" />
			</section>
		</div>

		<template #actions>
			<NcButton
				variant="tertiary"
				data-test="stylus-reset-all"
				@click="handleResetAll">
				{{ t('sheetmusic', 'Reset defaults') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-test="stylus-dialog-done"
				@click="emit('close')">
				{{ t('sheetmusic', 'Done') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style module>
.dialogBody {
	display: flex;
	flex-direction: column;
	gap: 18px;
	padding: 4px 2px;
}

.section {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.sectionTitle {
	margin: 0;
	font-size: 0.95rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.toggles {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.badge {
	display: inline-block;
	margin-left: 6px;
	padding: 1px 6px;
	border-radius: 10px;
	font-size: 0.72rem;
	font-weight: 600;
	background: var(--color-primary-light, #e6f4fa);
	color: var(--color-primary-element, #0082c9);
}

.sliderGrid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
	gap: 10px 16px;
}

.sliderField {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 0.82rem;
	color: var(--color-main-text);
}

.sliderLabel {
	display: flex;
	justify-content: space-between;
	gap: 8px;
}

.modeRow {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}

.modeBtn {
	padding: 6px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.82rem;
	cursor: pointer;
}

.modeBtn:hover {
	background: var(--color-background-hover);
}

.modeActive {
	background: var(--color-primary-light, #e6f4fa);
	border-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element, #0082c9);
	font-weight: 600;
}
</style>
