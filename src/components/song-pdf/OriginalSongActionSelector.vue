<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcPopover from '@nextcloud/vue/components/NcPopover'
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'
import RadioboxMarked from 'vue-material-design-icons/RadioboxMarked.vue'
import RadioboxBlank from 'vue-material-design-icons/RadioboxBlank.vue'

export type OriginalSongAction = 'keep' | 'remove-pages' | 'delete-file' | 'delete-song'

interface ActionOption {
	value: OriginalSongAction
	label: string
	infoAriaLabel: string
	infoText: string
	disabled: boolean
}

const props = withDefaults(
	defineProps<{
		modelValue: OriginalSongAction
		attachedFilesCount: number
		canRemovePages?: boolean
	}>(),
	{
		canRemovePages: true,
	},
)

const emit = defineEmits<{
	'update:modelValue': [val: OriginalSongAction]
}>()

const hasExactlyOneFile = computed(() => props.attachedFilesCount === 1)

const options = computed<ActionOption[]>(() => [
	{
		value: 'keep',
		label: t('sheetmusic', 'Do nothing (default)'),
		infoAriaLabel: t('sheetmusic', 'Info about keeping original song'),
		infoText: t('sheetmusic', 'Keep original song and its PDF file untouched.'),
		disabled: false,
	},
	{
		value: 'remove-pages',
		label: t('sheetmusic', 'Remove extracted pages from original PDF'),
		infoAriaLabel: t('sheetmusic', 'Info about removing extracted pages from original PDF'),
		infoText: props.canRemovePages
			? t('sheetmusic', 'The extracted pages will be removed from the original PDF, keeping the remaining pages.')
			: t('sheetmusic', 'Only possible if at least one page remains in the original PDF.'),
		disabled: !props.canRemovePages,
	},
	{
		value: 'delete-file',
		label: t('sheetmusic', 'Delete PDF from original song'),
		infoAriaLabel: t('sheetmusic', 'Info about deleting PDF file'),
		infoText: t('sheetmusic', 'The PDF file will be removed from the original song, but the song itself is kept.'),
		disabled: false,
	},
	{
		value: 'delete-song',
		label: t('sheetmusic', 'Delete original song and attached files'),
		infoAriaLabel: t('sheetmusic', 'Info about deleting original song'),
		infoText: hasExactlyOneFile.value
			? t('sheetmusic', 'The original song and its file will be completely deleted.')
			: t('sheetmusic', 'Only possible if the song has exactly one attached file ({count} files currently).', { count: props.attachedFilesCount }, { escape: false }),
		disabled: !hasExactlyOneFile.value,
	},
])

function select(val: OriginalSongAction) {
	const option = options.value.find((o) => o.value === val)
	if (!option || option.disabled) {
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
			<div
				v-for="opt in options"
				:key="opt.value"
				:class="[
					$style.optionRow,
					modelValue === opt.value && $style.optionRowActive,
					opt.disabled && $style.optionRowDisabled,
				]"
				role="radio"
				:aria-checked="modelValue === opt.value"
				:aria-disabled="opt.disabled ? 'true' : 'false'"
				:tabindex="opt.disabled ? -1 : 0"
				@click="select(opt.value)"
				@keydown.enter.prevent="select(opt.value)"
				@keydown.space.prevent="select(opt.value)">
				<input
					type="radio"
					name="originalSongAction"
					:value="opt.value"
					:disabled="opt.disabled"
					:checked="modelValue === opt.value"
					:class="$style.hiddenRadio"
					tabindex="-1"
					aria-hidden="true"
					@change="select(opt.value)">
				<div :class="$style.radioIndicator">
					<RadioboxMarked v-if="modelValue === opt.value" :size="18" :class="$style.checkedIcon" />
					<RadioboxBlank v-else :size="18" :class="$style.uncheckedIcon" />
				</div>
				<span :class="$style.optionLabel">
					{{ opt.label }}
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
							:aria-label="opt.infoAriaLabel"
							@click.stop>
							<InformationOutlineIcon :size="16" />
						</button>
					</template>
					<div :class="$style.infoPopoverContent">
						{{ opt.infoText }}
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
	flex-shrink: 0;
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
	overflow-wrap: break-word;
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
