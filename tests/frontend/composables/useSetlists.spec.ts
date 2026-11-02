import { flushPromises } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import axios from '@nextcloud/axios'
import { useSetlists } from '../../../src/composables/useSetlists'

vi.mock('@nextcloud/axios')
vi.mock('@nextcloud/l10n', () => ({
  translate: (_app: string, text: string) => text,
}))

describe('useSetlists composable', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('loads setlists and computes visibleSetlists based on search query', async () => {
    const mockSetlists = [
      { id: 1, name: 'Jazz Standards', itemCount: 2 },
      { id: 2, name: 'Classical Sonatas', itemCount: 0 },
    ]
    vi.mocked(axios.get).mockResolvedValue({
      data: { ocs: { data: mockSetlists } },
    } as any)

    const { setlists, visibleSetlists, searchQuery, loadSetlists } = useSetlists(() => 1)

    await loadSetlists()
    await flushPromises()

    expect(setlists.value).toHaveLength(2)
    expect(visibleSetlists.value).toHaveLength(2)

    searchQuery.value = 'jazz'
    expect(visibleSetlists.value).toHaveLength(1)
    expect(visibleSetlists.value[0].name).toBe('Jazz Standards')

    searchQuery.value = 'nonexistent'
    expect(visibleSetlists.value).toHaveLength(0)
  })

  it('computes totalDurationFormatted correctly from items', () => {
    const { selectedSetlistItems, totalDurationFormatted } = useSetlists(() => 1)

    expect(totalDurationFormatted.value).toBe('')

    selectedSetlistItems.value = [
      { id: 1, setlistId: 1, songId: 10, position: 0, duration: 125 },
      { id: 2, setlistId: 1, songId: 11, position: 1, duration: 80 },
    ]

    expect(totalDurationFormatted.value).toBe('3:25')
  })

  it('updates item bookmark via updateItemBookmark', async () => {
    const { selectedSetlist, selectedSetlistItems, updateItemBookmark } = useSetlists(() => 1)
    selectedSetlist.value = { id: 1, name: 'Live' }
    selectedSetlistItems.value = [
      { id: 101, setlistId: 1, songId: 10, position: 0, bookmarkId: null },
    ]

    vi.mocked(axios.post).mockResolvedValue({
      data: {
        ocs: {
          data: {
            id: 101,
            setlistId: 1,
            songId: 10,
            bookmarkId: 5,
            bookmarkName: 'Adagio',
            pageNum: 3,
            position: 0,
          },
        },
      },
    } as any)

    await updateItemBookmark(101, 5)
    await flushPromises()

    expect(selectedSetlistItems.value[0].bookmarkId).toBe(5)
    expect(selectedSetlistItems.value[0].bookmarkName).toBe('Adagio')
    expect(selectedSetlistItems.value[0].pageNum).toBe(3)
  })
})
