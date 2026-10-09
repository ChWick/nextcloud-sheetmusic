<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'
import RadioboxMarked from 'vue-material-design-icons/RadioboxMarked.vue'
import RadioboxBlank from 'vue-material-design-icons/RadioboxBlank.vue'

export type OriginalSongAction = 'delete-song' | 'delete-file' | 'keep'

const props = defineProps<{
	modelValue: OriginalSongAction
	attachedFilesCount: number
}>()

const emit = defineEmits<{
	'update:modelValue': [val: OriginalSongAction]
}>()

const hasExactlyOneFile = computed(() => props.attachedFilesCount === 1)

function select(val: OriginalSongAction) {
	if (val === 'delete-song' && !hasExactlyOneFile.value) {
		return
	}
	emit('update:modelValue', val)
}
</script>

<template>
	<div :class="$style.container">
		<h5 :class="$style.heading">
			{{ t('sheetmusic', 'Original song') }}
		</h5>

		<div :class="$style.optionsList" role="radiogroup" :aria-label="t('sheetmusic', 'Original song action')">
			<!-- Option c: Keep original song and PDF (default) -->
			<div
				:class="[$style.optionRow, modelValue === 'keep' && $style.optionRowActive]"
				role="radio"
				:aria-checked="modelValue === 'keep'"
				tabindex="0"
				@click="select('keep')"
				@keydown.enter.prevent="select('keep')"
				@keydown.space.prevent="select('keep')">
				<input
					type="radio"
					name="originalSongAction"
					value="keep"
					:checked="modelValue === 'keep'"
					:class="$style.hiddenRadio"
					tabindex="-1"
					aria-hidden="true"
					@change="select('keep')">
				<div :class="$style.radioIndicator">
					<RadioboxMarked v-if="modelValue === 'keep'" :size="18" :class="$style.checkedIcon" />
					<RadioboxBlank v-else :size="18" :class="$style.uncheckedIcon" />
				</div>
				<span :class="$style.optionLabel">
					{{ t('sheetmusic', 'Do nothing (default)') }}
				</span>
				<NcPopover
					:triggers="['hover', 'focus']"
					placement="top"
					no-focus-trap
					:class="$style.infoPopover">
					<template #trigger>
						<button
							type="button"
							:class="$style.infoBtn"
							:aria-label="t('sheetmusic', 'Info about keeping original song')"
							@click.stop>
							<InformationOutlineIcon :size="16" />
						</button>
					</template>
					<div :class="$style.infoPopoverContent">
						{{ t('sheetmusic', 'Keep original song and its PDF file untouched.') }}
					</div>
				</NcPopover>
			</div>

			<!-- Option b: Delete the PDF file from the original song but keep the song -->
			<div
				:class="[$style.optionRow, modelValue === 'delete-file' && $style.optionRowActive]"
				role="radio"
				:aria-checked="modelValue === 'delete-file'"
				tabindex="0"
				@click="select('delete-file')"
				@keydown.enter.prevent="select('delete-file')"
				@keydown.space.prevent="select('delete-file')">
				<input
					type="radio"
					name="originalSongAction"
					value="delete-file"
					:checked="modelValue === 'delete-file'"
					:class="$style.hiddenRadio"
					tabindex="-1"
					aria-hidden="true"
					@change="select('delete-file')">
				<div :class="$style.radioIndicator">
					<RadioboxMarked v-if="modelValue === 'delete-file'" :size="18" :class="$style.checkedIcon" />
					<RadioboxBlank v-else :size="18" :class="$style.uncheckedIcon" />
				</div>
				<span :class="$style.optionLabel">
					{{ t('sheetmusic', 'Delete PDF from original song') }}
				</span>
				<NcPopover
					:triggers="['hover', 'focus']"
					placement="top"
					no-focus-trap
					:class="$style.infoPopover">
					<template #trigger>
						<button
							type="button"
							:class="$style.infoBtn"
							:aria-label="t('sheetmusic', 'Info about deleting PDF file')"
							@click.stop>
							<InformationOutlineIcon :size="16" />
						</button>
					</template>
					<div :class="$style.infoPopoverContent">
						{{ t('sheetmusic', 'The PDF file will be removed from the original song, but the song itself is kept.') }}
					</div>
				</NcPopover>
			</div>

			<!-- Option a: Delete original song and all its attached files -->
			<div
				:class="[
					$style.optionRow,
					modelValue === 'delete-song' && $style.optionRowActive,
					!hasExactlyOneFile && $style.optionRowDisabled,
				]"
				role="radio"
				:aria-checked="modelValue === 'delete-song'"
				:aria-disabled="!hasExactlyOneFile"
				:tabindex="hasExactlyOneFile ? 0 : -1"
				@click="select('delete-song')"
				@keydown.enter.prevent="select('delete-song')"
				@keydown.space.prevent="select('delete-song')">
				<input
					type="radio"
					name="originalSongAction"
					value="delete-song"
					:disabled="!hasExactlyOneFile"
					:checked="modelValue === 'delete-song'"
					:class="$style.hiddenRadio"
					tabindex="-1"
					aria-hidden="true"
					@change="select('delete-song')">
				<div :class="$style.radioIndicator">
					<RadioboxMarked v-if="modelValue === 'delete-song'" :size="18" :class="$style.checkedIcon" />
					<RadioboxBlank v-else :size="18" :class="$style.uncheckedIcon" />
				</div>
				<span :class="$style.optionLabel">
					{{ t('sheetmusic', 'Delete original song and attached files') }}
				</span>
				<NcPopover
					:triggers="['hover', 'focus']"
					placement="top"
					no-focus-trap
					:class="$style.infoPopover">
					<template #trigger>
						<button
							type="button"
							:class="$style.infoBtn"
							:aria-label="t('sheetmusic', 'Info about deleting original song')"
							@click.stop>
							<InformationOutlineIcon :size="16" />
						</button>
					</template>
					<div :class="$style.infoPopoverContent">
						{{ hasExactlyOneFile
							? t('sheetmusic', 'The original song and its file will be completely deleted.')
							: t('sheetmusic', 'Only possible if the song has exactly one attached file ({count} files currently).', { count: attachedFilesCount })
						}}
					</div>
				</NcPopover>
			</div>
		</div>
	</div>
</template>

<style module>
.hiddenRadio {
	position: absolute;
	opacity: 0;
	pointer-events: none;
	width: 0;
	height: 0;
	margin: 0;
}
.container {
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.heading {
	margin: 0 0 2px 0;
	font-size: 13px;
	font-weight: 600;
	color: var(--color-main-text);
}

.optionsList {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.optionRow {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 5px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element, 6px);
	background-color: var(--color-main-background);
	cursor: pointer;
	user-select: none;
	transition: background-color 0.15s ease, border-color 0.15s ease;
	font-size: 13px;
}

.optionRow:hover:not(.optionRowDisabled) {
	background-color: var(--color-background-hover);
}

.optionRowActive {
	border-color: var(--color-primary-element);
	background-color: var(--color-background-hover);
}

.optionRowDisabled {
	opacity: 0.55;
	cursor: not-allowed;
	background-color: var(--color-background-hover);
}

.radioIndicator {
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	width: 18px;
	height: 18px;
}

.checkedIcon {
	color: var(--color-primary-element);
}

.uncheckedIcon {
	color: var(--color-text-maxcontrast);
}

.optionLabel {
	flex: 1;
	min-width: 0;
	font-size: 12px;
	line-height: 1.3;
	color: var(--color-main-text);
	white-space: normal;
	word-break: break-word;
}

.infoPopover {
	display: flex;
	align-items: center;
	flex-shrink: 0;
}

.infoBtn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	background: none;
	border: none;
	padding: 2px;
	margin: 0;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	border-radius: 50%;
	transition: color 0.15s ease, background-color 0.15s ease;
}

.infoBtn:hover {
	color: var(--color-primary-element);
	background-color: var(--color-background-dark);
}

.infoPopoverContent {
	max-width: 240px;
	padding: 8px 10px;
	font-size: 12px;
	line-height: 1.35;
	color: var(--color-main-text);
}
</style>
