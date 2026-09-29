import { computed, ref } from 'vue'

export function useSongSelection() {
	const selectedSongIds = ref<number[]>([])

	function isSelected(id: number): boolean {
		return selectedSongIds.value.includes(id)
	}

	function toggleSelect(id: number): void {
		if (isSelected(id)) {
			selectedSongIds.value = selectedSongIds.value.filter((i) => i !== id)
		} else {
			selectedSongIds.value = [...selectedSongIds.value, id]
		}
	}

	function selectAll(ids: number[]): void {
		const allSelected = isAllSelected(ids)
		if (allSelected) {
			clearSelection()
		} else {
			selectedSongIds.value = [...new Set([...selectedSongIds.value, ...ids])]
		}
	}

	function isAllSelected(ids: number[]): boolean {
		if (ids.length === 0) return false
		return ids.every((id) => selectedSongIds.value.includes(id))
	}

	function clearSelection(): void {
		selectedSongIds.value = []
	}

	const selectedCount = computed(() => selectedSongIds.value.length)

	return {
		selectedSongIds,
		selectedCount,
		isSelected,
		toggleSelect,
		selectAll,
		isAllSelected,
		clearSelection,
	}
}
