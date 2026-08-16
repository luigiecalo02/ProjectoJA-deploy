export type EvidencePreviewKind =
  | 'youtube'
  | 'vimeo'
  | 'image'
  | 'video'
  | 'audio'
  | 'pdf'
  | 'link'
  | 'none'

export type EvidencePreview = {
  kind: EvidencePreviewKind
  src: string
  embedSrc?: string
  host?: string
  title?: string
  fromFile?: boolean
}

function normalizeUrlInput(raw: string): string {
  const trimmed = raw.trim()
  if (!trimmed) return ''
  if (/^[a-z][a-z0-9+.-]*:/i.test(trimmed)) return trimmed
  if (trimmed.startsWith('//')) return `https:${trimmed}`
  return `https://${trimmed}`
}

export function parseEvidenceUrl(raw: string): URL | null {
  const normalized = normalizeUrlInput(raw)
  if (!normalized) return null
  try {
    return new URL(normalized)
  } catch {
    return null
  }
}

function youtubeIdFromUrl(url: URL): string | null {
  const host = url.hostname.replace(/^www\./, '').toLowerCase()
  if (host === 'youtu.be') {
    const id = url.pathname.split('/').filter(Boolean)[0] || null
    return id ? id.replace(/[^a-zA-Z0-9_-]/g, '') : null
  }
  if (
    host === 'youtube.com' ||
    host === 'm.youtube.com' ||
    host === 'music.youtube.com' ||
    host === 'youtube-nocookie.com'
  ) {
    if (url.pathname.startsWith('/embed/') || url.pathname.startsWith('/shorts/') || url.pathname.startsWith('/live/')) {
      const id = url.pathname.split('/')[2] || null
      return id ? id.replace(/[^a-zA-Z0-9_-]/g, '') : null
    }
    const v = url.searchParams.get('v')
    return v ? v.replace(/[^a-zA-Z0-9_-]/g, '') : null
  }
  return null
}

function vimeoIdFromUrl(url: URL): string | null {
  const host = url.hostname.replace(/^www\./, '').toLowerCase()
  if (host !== 'vimeo.com' && host !== 'player.vimeo.com') return null
  const parts = url.pathname.split('/').filter(Boolean)
  const id = parts.find((p) => /^\d+$/.test(p))
  return id || null
}

function extensionOf(path: string): string {
  const clean = path.split('?')[0].split('#')[0]
  const idx = clean.lastIndexOf('.')
  return idx >= 0 ? clean.slice(idx + 1).toLowerCase() : ''
}

function hostLabel(hostname: string): string {
  const host = hostname.replace(/^www\./, '').toLowerCase()
  if (host.includes('facebook.com') || host === 'fb.watch') return 'Facebook'
  if (host.includes('instagram.com')) return 'Instagram'
  if (host.includes('tiktok.com')) return 'TikTok'
  if (host.includes('drive.google.com') || host.includes('docs.google.com')) return 'Google Drive'
  return host
}

/**
 * Construye una vista previa a partir de una URL (link o archivo remoto).
 */
export function previewFromEvidenceUrl(
  rawUrl: string,
  options?: {
    preferredTipo?: string | null
    title?: string | null
  },
): EvidencePreview | null {
  const trimmed = rawUrl.trim()
  if (!trimmed) return null

  const title = options?.title?.trim() || undefined
  const preferredTipo = options?.preferredTipo || undefined
  const url = parseEvidenceUrl(trimmed)
  const src = url?.href || normalizeUrlInput(trimmed) || trimmed

  if (!url) {
    return {
      kind: 'link',
      src,
      title: title || trimmed,
    }
  }

  const yt = youtubeIdFromUrl(url)
  if (yt) {
    return {
      kind: 'youtube',
      src,
      embedSrc: `https://www.youtube.com/embed/${yt}`,
      host: 'YouTube',
      title: title || 'YouTube',
    }
  }

  const vimeo = vimeoIdFromUrl(url)
  if (vimeo) {
    return {
      kind: 'vimeo',
      src,
      embedSrc: `https://player.vimeo.com/video/${vimeo}`,
      host: 'Vimeo',
      title: title || 'Vimeo',
    }
  }

  const ext = extensionOf(url.pathname)
  const imageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif']
  const videoExt = ['mp4', 'webm', 'ogg', 'ogv', 'mov', 'm4v']
  const audioExt = ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac']
  const pdfExt = ['pdf']

  if (preferredTipo === 'imagen' || imageExt.includes(ext)) {
    return { kind: 'image', src, host: hostLabel(url.hostname), title }
  }
  if (preferredTipo === 'video' || videoExt.includes(ext)) {
    return { kind: 'video', src, host: hostLabel(url.hostname), title }
  }
  if (preferredTipo === 'audio' || audioExt.includes(ext)) {
    return { kind: 'audio', src, host: hostLabel(url.hostname), title }
  }
  if (preferredTipo === 'pdf' || pdfExt.includes(ext)) {
    return { kind: 'pdf', src, host: hostLabel(url.hostname), title }
  }

  // Google Drive file → intento de vista embebida
  if (url.hostname.includes('drive.google.com')) {
    const fileMatch = url.pathname.match(/\/file\/d\/([^/]+)/)
    if (fileMatch?.[1]) {
      return {
        kind: 'pdf',
        src,
        embedSrc: `https://drive.google.com/file/d/${fileMatch[1]}/preview`,
        host: 'Google Drive',
        title: title || 'Google Drive',
      }
    }
  }

  return {
    kind: 'link',
    src,
    host: hostLabel(url.hostname),
    title: title || hostLabel(url.hostname),
  }
}

export function previewFromEvidenceFile(
  file: File,
  objectUrl: string,
  preferredTipo?: string | null,
): EvidencePreview {
  const mime = file.type || ''
  const tipo = preferredTipo || ''
  if (mime.startsWith('image/') || tipo === 'imagen') {
    return { kind: 'image', src: objectUrl, title: file.name, fromFile: true }
  }
  if (mime.startsWith('video/') || tipo === 'video') {
    return { kind: 'video', src: objectUrl, title: file.name, fromFile: true }
  }
  if (mime.startsWith('audio/') || tipo === 'audio') {
    return { kind: 'audio', src: objectUrl, title: file.name, fromFile: true }
  }
  if (mime.includes('pdf') || tipo === 'pdf' || file.name.toLowerCase().endsWith('.pdf')) {
    return { kind: 'pdf', src: objectUrl, title: file.name, fromFile: true }
  }
  return { kind: 'link', src: objectUrl, title: file.name, fromFile: true }
}
