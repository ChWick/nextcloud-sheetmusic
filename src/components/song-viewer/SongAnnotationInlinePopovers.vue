<script setup lang="ts">
import type { SheetAnnotation } from '../../types/annotation'
import { nextTick, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'

export interface PendingTextEdit {
	mode: 'create' | 'edit'
	x: number
	y: number
	width: number
	height: number
	text: string
	existing?: SheetAnnotation
}

export interface PendingLinkEdit {
	mode?: 'create' | 'edit'
	x: number
	y: number
	width: number
	height: number
	targetPageOneIndexed: number
	label: string
	existing?: SheetAnnotation
}

const props = defineProps<{
	pendingText: PendingTextEdit | null
	pendingLink: PendingLinkEdit | null
	totalPages: number
}>()

const emit = defineEmits<{
	'update:pendingTextText': [text: string]
	'update:pendingLinkPage': [page: number]
	'update:pendingLinkLabel': [label: string]
	'commit-text': []
	'cancel-text': []
	'commit-link': []
	'cancel-link': []
}>()

const textInputRef = ref<HTMLTextAreaElement | null>(null)

watch(
	() => props.pendingText,
	(val) => {
		if (val) {
			nextTick(() => textInputRef.value?.focus())
		}
	},
)
</script>

<template>
	<!-- Inline Text Editor Popover -->
	<div
		v-if="pendingText"
		class="sheetmusic-inline-popover"
		:style="{
			left: `${pendingText.x * 100}%`,
			top: `${pendingText.y * 100}%`,
			minWidth: `${Math.max(180, pendingText.width * 100)}px`,
		}"
		@pointerdown.stop>
		<textarea
			ref="textInputRef"
			:value="pendingText.text"
			rows="3"
			:placeholder="t('sheetmusic', 'Enter annotation text...')"
			class="sheetmusic-inline-textarea"
			data-test="annotation-text-input"
			@input="emit('update:pendingTextText', ($event.target as HTMLTextAreaElement).value)"
			@keydown.ctrl.enter.prevent="emit('commit-text')"
			@keydown.meta.enter.prevent="emit('commit-text')"
			@keydown.esc.prevent="emit('cancel-text')" />
		<div class="sheetmusic-inline-actions">
			<button type="button" class="sheetmusic-inline-btn" @click="emit('cancel-text')">
				{{ t('sheetmusic', 'Cancel') }}
			</button>
			<button type="button" class="sheetmusic-inline-btn primary" data-test="annotation-text-save" @click="emit('commit-text')">
				{{ t('sheetmusic', 'Save') }}
			</button>
		</div>
	</div>

	<!-- Inline Link Target Popover -->
	<div
		v-if="pendingLink"
		class="sheetmusic-inline-popover"
		:style="{
			left: `${pendingLink.x * 100}%`,
			top: `${pendingLink.y * 100}%`,
		}"
		@pointerdown.stop>
		<div class="sheetmusic-inline-title">{{ t('sheetmusic', 'Jump to page') }}</div>
		<div class="sheetmusic-inline-fields">
			<label>
				<span>{{ t('sheetmusic', 'Page') }}</span>
				<input
					:value="pendingLink.targetPageOneIndexed"
					type="number"
					min="1"
					:max="totalPages"
					class="sheetmusic-inline-input"
					data-test="annotation-link-page-input"
					@input="emit('update:pendingLinkPage', Number(($event.target as HTMLInputElement).value))" />
			</label>
			<label>
				<span>{{ t('sheetmusic', 'Label') }}</span>
				<input
					:value="pendingLink.label"
					type="text"
					:placeholder="t('sheetmusic', 'e.g. To Coda')"
					class="sheetmusic-inline-input"
					data-test="annotation-link-label-input"
					@input="emit('update:pendingLinkLabel', ($event.target as HTMLInputElement).value)" />
			</label>
		</div>
		<div class="sheetmusic-inline-actions">
			<button type="button" class="sheetmusic-inline-btn" @click="emit('cancel-link')">
				{{ t('sheetmusic', 'Cancel') }}
			</button>
			<button type="button" class="sheetmusic-inline-btn primary" data-test="annotation-link-save" @click="emit('commit-link')">
				{{ t('sheetmusic', 'Add Link') }}
			</button>
		</div>
	</div>
</template>

<style scoped>
.sheetmusic-inline-popover {
	position: absolute;
	z-index: 20;
	background: var(--color-main-background, #ffffff);
	color: var(--color-main-text, #222222);
	border: 1px solid var(--color-border-dark, #cccccc);
	border-radius: 8px;
	box-shadow: 0 6px 20px rgba(0, 0, 0, 0.22);
	padding: 8px;
	min-width: 210px;
	pointer-events: auto;
}

.sheetmusic-inline-title {
	font-size: 12px;
	font-weight: 600;
	margin-bottom: 6px;
}

.sheetmusic-inline-textarea {
	width: 100%;
	box-sizing: border-box;
	padding: 6px;
	border: 1px solid var(--color-border-dark, #cccccc);
	border-radius: 4px;
	font-size: 13px;
	resize: both;
	background: var(--color-main-background, #ffffff);
	color: var(--color-main-text, #222222);
}

.sheetmusic-inline-fields {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-bottom: 8px;
}

.sheetmusic-inline-fields label {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	font-size: 12px;
}

.sheetmusic-inline-input {
	width: 120px;
	padding: 4px 6px;
	border: 1px solid var(--color-border-dark, #cccccc);
	border-radius: 4px;
	font-size: 12px;
}

.sheetmusic-inline-actions {
	display: flex;
	justify-content: flex-end;
	gap: 6px;
	margin-top: 6px;
}

.sheetmusic-inline-btn {
	padding: 4px 10px;
	border-radius: 4px;
	border: 1px solid var(--color-border-dark, #cccccc);
	background: var(--color-background-hover, #f5f5f5);
	color: var(--color-main-text, #222222);
	font-size: 12px;
	cursor: pointer;
}

.sheetmusic-inline-btn.primary {
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #ffffff);
	border-color: var(--color-primary-element, #0082c9);
}
</style>
