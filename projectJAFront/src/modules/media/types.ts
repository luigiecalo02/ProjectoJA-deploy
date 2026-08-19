export type MediaUploadKind = 'profile' | 'cover' | 'gallery' | 'documents'

export const MEDIA_UPLOAD_KIND = {
  profile: 'profile',
  cover: 'cover',
  gallery: 'gallery',
  documents: 'documents',
} as const satisfies Record<MediaUploadKind, MediaUploadKind>

export type MediaDocumentKind = 'pdf' | 'doc' | 'xls' | 'ppt' | 'zip' | 'audio' | 'video' | 'file'

export interface MediaGalleryItem {
  id: string | number
  src: string
  name?: string
}

export interface MediaDocumentItem {
  id: string | number
  name: string
  sizeLabel?: string
  dateLabel?: string
  kind?: MediaDocumentKind
  url?: string
}

export function documentKindFromName(name: string): MediaDocumentKind {
  const ext = name.split('.').pop()?.toLowerCase() ?? ''
  if (ext === 'pdf') return 'pdf'
  if (['doc', 'docx'].includes(ext)) return 'doc'
  if (['xls', 'xlsx'].includes(ext)) return 'xls'
  if (['ppt', 'pptx'].includes(ext)) return 'ppt'
  if (ext === 'zip') return 'zip'
  if (['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'oga', 'opus'].includes(ext)) return 'audio'
  if (['mp4', 'mov', 'webm', 'mkv', 'avi'].includes(ext)) return 'video'
  return 'file'
}

export function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
