<template>
	<div :class="$style.container">
		<NcTextField v-model="query"
		             :label="filterLabel"
		             :placeholder="filterPlaceholder"
		             :disabled="disabled || loading" />

		<div v-if="loading" :class="$style.loadingRow">
			<NcLoadingIcon :size="20" />
			<span>{{ loadingText }}</span>
		</div>

		<div v-else :class="$style.list" role="listbox" :aria-label="listLabel">
			<button v-for="item in filteredItems"
			        :key="item.id"
			        type="button"
			        role="option"
			        :aria-selected="modelValue === item.id"
			        :disabled="disabled"
			        :class="[$style.row, modelValue === item.id && $style.rowSelected]"
			        @click="emit('update:modelValue', item.id)">
				<span :class="$style.title">{{ item.label }}</span>
				<span v-if="item.subtitle" :class="$style.subtitle">{{ item.subtitle }}</span>
			</button>
			<p v-if="filteredItems.length === 0" :class="$style.empty">
				{{ emptyText }}
			</p>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import { normalizeSearchString } from '../../utils/string'

export interface FilterableOptionItem {
	id: number
	label: string
	subtitle?: string
}

const props = withDefaults(defineProps<{
	items: FilterableOptionItem[]
	modelValue: number
	filterLabel: string
	filterPlaceholder?: string
	listLabel: string
	emptyText: string
	loadingText?: string
	loading?: boolean
	disabled?: boolean
}>(), {
	filterPlaceholder: '',
	loadingText: '',
	loading: false,
	disabled: false,
})

const emit = defineEmits<{
	'update:modelValue': [id: number]
}>()

const query = ref('')

const filteredItems = computed(() => {
	const needle = normalizeSearchString(query.value)
	if (!needle) {
		return props.items
	}
	return props.items.filter(item =>
		normalizeSearchString(item.label).includes(needle)
		|| (item.subtitle ? normalizeSearchString(item.subtitle).includes(needle) : false),
	)
})
</script>

<style module>
.container {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.loadingRow {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px 0;
	color: var(--color-text-maxcontrast);
}

.list {
	display: flex;
	flex-direction: column;
	max-height: 240px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.row {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 2px;
	width: 100%;
	padding: 10px 12px;
	border: 0;
	border-bottom: 1px solid var(--color-border);
	background: transparent;
	color: inherit;
	font: inherit;
	text-align: start;
	cursor: pointer;
}

.row:last-child {
	border-bottom: 0;
}

.row:hover:not(:disabled) {
	background: var(--color-background-hover);
}

.rowSelected {
	background: var(--color-primary-light);
	font-weight: 600;
}

.title {
	width: 100%;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.subtitle {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.empty {
	margin: 0;
	padding: 16px 12px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}
</style>
