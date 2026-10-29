<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import { nextTick, ref, useId } from 'vue'
import type { InlineEditableField } from './song-view-columns'

const props = withDefaults(
	defineProps<{
		tag?: 'td' | 'div'
		field: InlineEditableField
		value: string | number | null | undefined
		displayText?: string
		ariaLabel: string
		editTitle: string
		placeholder?: string
		mode?: 'text' | 'select'
		options?: Array<{ value: number, label: string }>
		suggestions?: string[]
		variant?: 'table-title' | 'table-meta' | 'card-title' | 'list-title'
		allowEmpty?: boolean
	}>(),
	{
		tag: 'div',
		displayText: undefined,
		placeholder: undefined,
		mode: 'text',
		options: () => [],
		suggestions: () => [],
		variant: 'table-meta',
		allowEmpty: true,
	},
)

const emit = defineEmits<{
	save: [value: string | number]
}>()

const datalistId = `inline-datalist-${props.field}-${useId()}`
const isEditing = ref(false)
const draftText = ref('')
const draftNumber = ref(0)
const inputRef = ref<HTMLInputElement | null>(null)
const selectRef = ref<HTMLSelectElement | null>(null)

async function startEdit() {
	if (isEditing.value) return
	isEditing.value = true
	if (props.mode === 'select') {
		draftNumber.value = Number(props.value ?? 0)
	} else {
		draftText.value = String(props.value ?? '')
	}
	await nextTick()
	if (inputRef.value) {
		inputRef.value.focus()
		inputRef.value.select?.()
	}
	if (selectRef.value) {
		selectRef.value.focus()
	}
}

function cancelEdit() {
	isEditing.value = false
	draftText.value = ''
}

function commitEdit() {
	if (!isEditing.value) return

	if (props.mode === 'select') {
		const nextNum = Math.max(0, Math.min(5, Number(draftNumber.value) || 0))
		const prevNum = Number(props.value ?? 0)
		cancelEdit()
		if (nextNum !== prevNum) {
			emit('save', nextNum)
		}
		return
	}

	const trimmed = draftText.value.trim()
	if (!props.allowEmpty && !trimmed) {
		return
	}

	const prevVal = String(props.value ?? '').trim()
	cancelEdit()
	if (trimmed !== prevVal) {
		emit('save', trimmed)
	}
}

defineExpose({
	startEdit,
	cancelEdit,
	isEditing,
})
</script>

<template>
	<component
		:is="tag"
		@dblclick.stop="startEdit">
		<div
			v-if="isEditing"
			:class="$style.inlineEditContainer"
			@click.stop
			@dblclick.stop>
			<select
				v-if="mode === 'select'"
				ref="selectRef"
				v-model.number="draftNumber"
				:class="$style.inlineEditSelect"
				data-test="inline-edit-select"
				:aria-label="ariaLabel"
				@change="commitEdit"
				@keydown.enter.prevent="commitEdit"
				@keydown.esc.prevent="cancelEdit">
				<option v-for="opt in options" :key="opt.value" :value="opt.value">
					{{ opt.label }}
				</option>
			</select>
			<template v-else>
				<input
					ref="inputRef"
					v-model="draftText"
					type="text"
					:class="$style.inlineEditInput"
					data-test="inline-edit-input"
					:aria-label="ariaLabel"
					:placeholder="placeholder"
					:list="suggestions.length > 0 ? datalistId : undefined"
					@keydown.enter.prevent="commitEdit"
					@keydown.esc.prevent="cancelEdit">
				<datalist v-if="suggestions.length > 0" :id="datalistId">
					<option v-for="s in suggestions" :key="s" :value="s" />
				</datalist>
			</template>
			<button
				v-if="mode === 'text'"
				type="button"
				:class="[$style.inlineActionBtn, $style.inlineSaveBtn]"
				data-test="inline-edit-save"
				:title="t('sheetmusic', 'Save')"
				:aria-label="t('sheetmusic', 'Save')"
				@click.stop="commitEdit">
				<CheckIcon :size="14" />
			</button>
			<button
				type="button"
				:class="[$style.inlineActionBtn, $style.inlineCancelBtn]"
				data-test="inline-edit-cancel"
				:title="t('sheetmusic', 'Cancel')"
				:aria-label="t('sheetmusic', 'Cancel')"
				@click.stop="cancelEdit">
				<CloseIcon :size="14" />
			</button>
		</div>
		<div v-else :class="$style.editableCellContent">
			<slot>
				<span
					:class="[
						variant === 'table-title' && $style.tableTitle,
						variant === 'card-title' && $style.cardTitle,
						variant === 'list-title' && $style.listTitle,
					]">
					{{ displayText ?? (value ? String(value) : '—') }}
				</span>
			</slot>
			<button
				type="button"
				:class="[$style.inlineEditTrigger, 'sheetmusic-inline-edit-trigger']"
				:data-test="`inline-edit-${field}-btn`"
				:title="editTitle"
				:aria-label="editTitle"
				@click.stop="startEdit">
				<PencilIcon :size="14" />
			</button>
		</div>
	</component>
</template>

<style module>
.editableCellContent {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	max-width: 100%;
	min-width: 0;
}

.tableTitle {
	color: var(--color-primary-element);
	text-decoration: underline;
	font-weight: 600;
}

.cardTitle {
	color: var(--color-main-text);
	text-align: left;
	min-width: 0;
	word-break: break-word;
	font-weight: 600;
}

.listTitle {
	font-weight: 600;
	font-size: 0.95rem;
	color: var(--color-main-text);
	text-align: left;
}

.inlineEditTrigger {
	appearance: none;
	-webkit-appearance: none;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 22px;
	height: 22px;
	padding: 0;
	margin: 0;
	border: 1px solid transparent;
	border-radius: 4px;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	opacity: 0;
	flex-shrink: 0;
	transition: opacity 0.15s ease, color 0.15s ease, background-color 0.15s ease, border-color 0.15s ease;
}

.editableCellContent:hover .inlineEditTrigger,
.inlineEditTrigger:focus,
.inlineEditTrigger:focus-visible {
	opacity: 0.75;
}

.inlineEditTrigger:hover {
	opacity: 1 !important;
	color: var(--color-primary-element);
	background-color: var(--color-main-background);
	border-color: var(--color-border);
}

.inlineEditContainer {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	width: 100%;
	min-width: 140px;
}

.inlineEditInput {
	flex: 1;
	min-width: 100px;
	padding: 4px 8px;
	border: 1px solid var(--color-primary-element);
	border-radius: 4px;
	font: inherit;
	font-weight: 500;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	box-sizing: border-box;
}

.inlineEditSelect {
	padding: 4px 6px;
	border: 1px solid var(--color-primary-element);
	border-radius: 4px;
	font: inherit;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.inlineActionBtn {
	appearance: none;
	-webkit-appearance: none;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	padding: 0;
	margin: 0;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	flex-shrink: 0;
}

.inlineSaveBtn {
	color: var(--color-element-success, #16a34a);
}

.inlineSaveBtn:hover {
	background-color: var(--color-background-hover);
	border-color: var(--color-element-success, #16a34a);
}

.inlineCancelBtn {
	color: var(--color-text-maxcontrast);
}

.inlineCancelBtn:hover {
	background-color: var(--color-background-hover);
	color: var(--color-error, #dc2626);
	border-color: var(--color-error, #dc2626);
}
</style>
