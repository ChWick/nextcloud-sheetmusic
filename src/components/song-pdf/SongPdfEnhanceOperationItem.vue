<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'

import type { AdaptiveThresholdOp, CompressionOp, GaussianOp, GrayLevelMappingOp, ScanOperation } from './scan-types'
import { getFilterDefinition } from './pdf-enhance-settings'

const props = defineProps<{
	operation: ScanOperation
	index: number
	total: number
}>()

const emit = defineEmits<{
	toggle: []
	move: [direction: 'up' | 'down']
	remove: []
	input: []
	change: []
}>()
</script>

<template>
	<div :class="[$style.opCard, !operation.enabled && $style.opCardDisabled]">
		<!-- Op Card Header -->
		<div :class="$style.opHeader">
			<div :class="$style.opTitleRow">
				<input
					type="checkbox"
					:checked="operation.enabled"
					:title="t('sheetmusic', 'Toggle operation')"
					@change="emit('toggle')" />
				<strong :class="$style.opName">
					{{ index + 1 }}.
					{{
						operation.type === 'gaussian'
							? t('sheetmusic', 'Gaussian Filter')
							: operation.type === 'grayLevelMapping'
								? t('sheetmusic', 'Gray Level Mapping (Curves)')
								: operation.type === 'adaptiveThreshold'
									? t('sheetmusic', 'Adaptive Thresholding')
									: operation.type === 'compression'
										? t('sheetmusic', 'Image Compression')
										: ((operation as any).label || getFilterDefinition(operation.type)?.label || operation.type)
					}}
				</strong>
			</div>

			<div :class="$style.opControls">
				<button
					type="button"
					:class="$style.iconBtn"
					:disabled="index === 0"
					:title="t('sheetmusic', 'Move up')"
					@click="emit('move', 'up')">
					<ArrowUp :size="16" />
				</button>
				<button
					type="button"
					:class="$style.iconBtn"
					:disabled="index === total - 1"
					:title="t('sheetmusic', 'Move down')"
					@click="emit('move', 'down')">
					<ArrowDown :size="16" />
				</button>
				<button
					type="button"
					:class="[$style.iconBtn, $style.deleteBtn]"
					:title="t('sheetmusic', 'Remove operation')"
					@click="emit('remove')">
					<DeleteOutline :size="16" />
				</button>
			</div>
		</div>

		<!-- Op Parameters -->
		<div :class="$style.opParams">
			<!-- Gaussian Filter Parameters -->
			<template v-if="operation.type === 'gaussian'">
				<div :class="$style.paramRow">
					<label :class="$style.paramLabel">
						{{ t('sheetmusic', 'Filter Kernel Size (px):') }}
						<strong>{{ (operation as GaussianOp).size }}</strong>
					</label>
					<input
						v-model.number="(operation as GaussianOp).size"
						type="range"
						min="1"
						max="15"
						step="2"
						:class="$style.slider"
						@input="emit('input')"
						@change="emit('change')" />
				</div>
			</template>

			<!-- Gray Level Mapping Parameters -->
			<template v-else-if="operation.type === 'grayLevelMapping'">
				<div :class="$style.paramRow">
					<label :class="$style.paramLabel">
						{{ t('sheetmusic', 'Black Cutoff (Lower):') }}
						<strong>{{ (operation as GrayLevelMappingOp).lowerCutoff }}</strong>
					</label>
					<input
						v-model.number="(operation as GrayLevelMappingOp).lowerCutoff"
						type="range"
						min="0"
						max="255"
						:class="$style.slider"
						@input="emit('input')"
						@change="emit('change')" />
				</div>
				<div :class="$style.paramRow">
					<label :class="$style.paramLabel">
						{{ t('sheetmusic', 'White Cutoff (Upper):') }}
						<strong>{{ (operation as GrayLevelMappingOp).upperCutoff }}</strong>
					</label>
					<input
						v-model.number="(operation as GrayLevelMappingOp).upperCutoff"
						type="range"
						min="0"
						max="255"
						:class="$style.slider"
						@input="emit('input')"
						@change="emit('change')" />
				</div>
				<div :class="$style.paramRow">
					<label :class="$style.paramLabel">{{ t('sheetmusic', 'Color Mode:') }}</label>
					<select
						v-model="(operation as GrayLevelMappingOp).colorMode"
						:class="$style.select"
						@change="emit('input'); emit('change')">
						<option value="color">{{ t('sheetmusic', 'Preserve Color Tones') }}</option>
						<option value="grayscale">{{ t('sheetmusic', 'Convert to Grayscale') }}</option>
					</select>
				</div>
			</template>

			<!-- Adaptive Thresholding Parameters -->
			<template v-else-if="operation.type === 'adaptiveThreshold'">
				<div :class="$style.paramRow">
					<label :class="$style.paramLabel">
						{{ t('sheetmusic', 'Neighborhood Window Size:') }}
						<strong>{{ (operation as AdaptiveThresholdOp).windowSize }}</strong>
					</label>
					<input
						v-model.number="(operation as AdaptiveThresholdOp).windowSize"
						type="range"
						min="3"
						max="61"
						step="2"
						:class="$style.slider"
						@input="emit('input')"
						@change="emit('change')" />
				</div>
				<div :class="$style.paramRow">
					<label :class="$style.paramLabel">
						{{ t('sheetmusic', 'Mean Offset Delta:') }}
						<strong>{{ (operation as AdaptiveThresholdOp).delta }}</strong>
					</label>
					<input
						v-model.number="(operation as AdaptiveThresholdOp).delta"
						type="range"
						min="-20"
						max="40"
						:class="$style.slider"
						@input="emit('input')"
						@change="emit('change')" />
				</div>
				<div :class="$style.paramRow">
					<label :class="$style.paramLabel">
						{{ t('sheetmusic', 'Soft Transition (Softness):') }}
						<strong>
							{{ ((operation as AdaptiveThresholdOp).softness ?? 0) === 0
								? t('sheetmusic', 'Hard (0)')
								: (operation as AdaptiveThresholdOp).softness }}
						</strong>
					</label>
					<input
						:value="(operation as AdaptiveThresholdOp).softness ?? 0"
						type="range"
						min="0"
						max="60"
						step="1"
						:class="$style.slider"
						data-test="threshold-softness-slider"
						@input="(operation as AdaptiveThresholdOp).softness = Number(($event.target as HTMLInputElement).value); emit('input')"
						@change="emit('change')" />
				</div>
				<div :class="$style.paramRow">
					<label :class="$style.paramLabel">
						{{ t('sheetmusic', 'Custom Black Limit (Ink):') }}
						<strong>{{ (operation as AdaptiveThresholdOp).blackValue }}</strong>
					</label>
					<input
						v-model.number="(operation as AdaptiveThresholdOp).blackValue"
						type="range"
						min="0"
						max="100"
						:class="$style.slider"
						@input="emit('input')"
						@change="emit('change')" />
				</div>
				<div :class="$style.paramRow">
					<label :class="$style.paramLabel">
						{{ t('sheetmusic', 'Custom White Limit (Paper):') }}
						<strong>{{ (operation as AdaptiveThresholdOp).whiteValue }}</strong>
					</label>
					<input
						v-model.number="(operation as AdaptiveThresholdOp).whiteValue"
						type="range"
						min="150"
						max="255"
						:class="$style.slider"
						@input="emit('input')"
						@change="emit('change')" />
				</div>
				<div :class="$style.paramRow">
					<label :class="$style.paramLabel">{{ t('sheetmusic', 'Output Mode:') }}</label>
					<select
						v-model="(operation as AdaptiveThresholdOp).colorMode"
						:class="$style.select"
						@change="emit('input'); emit('change')">
						<option value="binarize">{{ t('sheetmusic', 'Binarize (Black & White)') }}</option>
						<option value="preserveColor">{{ t('sheetmusic', 'Whiten Paper, Keep Colored Notes') }}</option>
					</select>
				</div>
			</template>

			<!-- Image Compression Parameters -->
			<template v-else-if="operation.type === 'compression'">
				<div :class="$style.paramRow">
					<label :class="$style.paramLabel">{{ t('sheetmusic', 'Compression Format / Bit Depth:') }}</label>
					<select
						v-model="(operation as CompressionOp).format"
						:class="$style.select"
						data-test="compression-format-select"
						@change="emit('input'); emit('change')">
						<option value="jpeg">{{ t('sheetmusic', 'JPEG (Lossy DCT)') }}</option>
						<option value="grayscale-8bit">{{ t('sheetmusic', '8-bit Grayscale (256 shades)') }}</option>
						<option value="grayscale-4bit">{{ t('sheetmusic', '4-bit Grayscale (16 shades)') }}</option>
						<option value="grayscale-2bit">{{ t('sheetmusic', '2-bit Grayscale (4 shades)') }}</option>
						<option value="bw-1bit">{{ t('sheetmusic', '1-bit B&W (2 shades)') }}</option>
						<option value="rgb-24bit">{{ t('sheetmusic', '24-bit RGB (Lossless Color)') }}</option>
					</select>
				</div>
				<template v-if="(operation as CompressionOp).format === 'jpeg'">
					<div :class="$style.paramRow">
						<label :class="$style.paramLabel">
							{{ t('sheetmusic', 'JPEG Quality:') }}
							<strong>{{ (operation as CompressionOp).quality }}%</strong>
						</label>
						<input
							v-model.number="(operation as CompressionOp).quality"
							type="range"
							min="10"
							max="100"
							step="5"
							:class="$style.slider"
							data-test="compression-quality-slider"
							@input="emit('input')"
							@change="emit('change')" />
					</div>
					<div :class="$style.paramRow">
						<label :class="$style.paramLabel">{{ t('sheetmusic', 'JPEG Color Space:') }}</label>
						<select
							v-model="(operation as CompressionOp).colorMode"
							:class="$style.select"
							data-test="compression-colormode-select"
							@change="emit('input'); emit('change')">
							<option value="grayscale">{{ t('sheetmusic', 'Grayscale (8-bit)') }}</option>
							<option value="color">{{ t('sheetmusic', 'Color (24-bit YCbCr)') }}</option>
						</select>
					</div>
				</template>
			</template>
		</div>
	</div>
</template>

<style module>
.opCard {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
	padding: 8px;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.opCardDisabled {
	opacity: 0.55;
}

.opHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.opTitleRow {
	display: flex;
	align-items: center;
	gap: 6px;
}

.opName {
	font-size: 13px;
	color: var(--color-main-text);
}

.opControls {
	display: flex;
	align-items: center;
	gap: 2px;
}

.iconBtn {
	background: transparent;
	border: none;
	cursor: pointer;
	padding: 2px 4px;
	border-radius: 4px;
	color: var(--color-text-maxcontrast);
	display: flex;
	align-items: center;
}

.iconBtn:hover:not(:disabled) {
	background: var(--color-border);
	color: var(--color-main-text);
}

.iconBtn:disabled {
	opacity: 0.3;
	cursor: not-allowed;
}

.deleteBtn:hover {
	color: var(--color-error);
}

.opParams {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding-top: 4px;
	border-top: 1px solid var(--color-border-maxcontrast);
}

.paramRow {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.paramLabel {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	display: flex;
	justify-content: space-between;
}

.slider {
	width: 100%;
	height: 4px;
	cursor: pointer;
}

.select {
	padding: 4px;
	border-radius: 4px;
	border: 1px solid var(--color-border);
	font-size: 12px;
	background: var(--color-main-background);
	color: var(--color-main-text);
}
</style>
