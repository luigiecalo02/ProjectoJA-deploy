import { ref } from 'vue'
import { prepareUploadFile } from '@/utils/optimizeImage'

export function useMediaPicker(options: {
  accept: string
  maxBytes: number
  multiple?: boolean
  optimizeImages?: boolean
}) {
  const inputRef = ref<HTMLInputElement | null>(null)
  const dragging = ref(false)

  function openPicker(): void {
    inputRef.value?.click()
  }

  async function takeFiles(list: FileList | File[] | null): Promise<File[]> {
    if (!list) return []
    const next: File[] = []
    for (const file of Array.from(list)) {
      if (file.size > options.maxBytes) {
        throw Object.assign(new Error('too-large'), { code: 'too-large', file })
      }
      next.push(options.optimizeImages === false ? file : await prepareUploadFile(file))
    }
    return next
  }

  function onDragOver(event: DragEvent): void {
    event.preventDefault()
    dragging.value = true
  }

  function onDragLeave(): void {
    dragging.value = false
  }

  async function onDrop(event: DragEvent): Promise<File[]> {
    event.preventDefault()
    dragging.value = false
    return takeFiles(event.dataTransfer?.files ?? null)
  }

  return { inputRef, dragging, openPicker, takeFiles, onDragOver, onDragLeave, onDrop }
}
