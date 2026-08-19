const MAX_EDGE = 1600
const JPEG_QUALITY = 0.82

/**
 * Punto único para aligerar fotos antes de subirlas.
 * Usar `prepareUploadFile` en cualquier servicio o pantalla nueva.
 */
export async function optimizeImageFile(file: File): Promise<File> {
  if (!file.type.startsWith('image/') || file.type === 'image/gif' || file.type === 'image/svg+xml') {
    return file
  }

  const bitmap = await createImageBitmap(file)
  const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height))
  const width = Math.max(1, Math.round(bitmap.width * scale))
  const height = Math.max(1, Math.round(bitmap.height * scale))

  if (scale === 1 && file.size < 350_000) {
    bitmap.close()
    return file
  }

  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  const context = canvas.getContext('2d')
  if (!context) {
    bitmap.close()
    return file
  }

  context.drawImage(bitmap, 0, 0, width, height)
  bitmap.close()

  const blob = await new Promise<Blob | null>((resolve) => {
    canvas.toBlob(resolve, 'image/jpeg', JPEG_QUALITY)
  })
  if (!blob) return file

  const name = file.name.replace(/\.[^.]+$/, '') + '.jpg'
  return new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() })
}

export async function prepareUploadFile(file: File): Promise<File> {
  if (!file.type.startsWith('image/')) {
    return file
  }

  return optimizeImageFile(file)
}
