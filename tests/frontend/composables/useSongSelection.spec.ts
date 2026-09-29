import { describe, expect, it } from 'vitest'
import { useSongSelection } from '../../../src/composables/useSongSelection'

describe('useSongSelection composable', () => {
	it('manages song selection state correctly', () => {
		const { selectedSongIds, selectedCount, isSelected, toggleSelect, selectAll, isAllSelected, clearSelection } = useSongSelection()

		expect(selectedCount.value).toBe(0)
		expect(isSelected(1)).toBe(false)

		toggleSelect(1)
		expect(isSelected(1)).toBe(true)
		expect(selectedCount.value).toBe(1)

		toggleSelect(2)
		expect(selectedSongIds.value).toEqual([1, 2])
		expect(selectedCount.value).toBe(2)

		expect(isAllSelected([1, 2])).toBe(true)
		expect(isAllSelected([1, 2, 3])).toBe(false)

		// Toggle selectAll when not all selected adds missing
		selectAll([1, 2, 3])
		expect(selectedSongIds.value).toEqual([1, 2, 3])

		// Toggle selectAll when all selected clears selection
		selectAll([1, 2, 3])
		expect(selectedSongIds.value).toEqual([])

		toggleSelect(5)
		clearSelection()
		expect(selectedSongIds.value).toEqual([])
	})
})
