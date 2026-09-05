const ALLOWED_TAGS = new Set(['P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'H2', 'H3', 'UL', 'OL', 'LI', 'A'])
const DROP_TAGS = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'LINK', 'META', 'IMG'])

export function looksLikeHtml(value: string): boolean {
  return /<[a-z][\s\S]*>/i.test(value)
}

export function isRichTextEmpty(html: string | null | undefined): boolean {
  if (!html) return true
  return html.replace(/<[^>]+>/g, '').replace(/&nbsp;/gi, ' ').trim().length === 0
}

export function sanitizeRichText(html: string): string {
  if (typeof DOMParser === 'undefined') return html
  const doc = new DOMParser().parseFromString(`<div>${html}</div>`, 'text/html')
  const root = doc.body.firstElementChild
  if (!root) return ''
  cleanNode(root)
  return root.innerHTML
}

export function toEditorHtml(value: string | null | undefined): string {
  if (!value) return ''
  if (looksLikeHtml(value)) return sanitizeRichText(value)
  return value
    .split(/\n+/)
    .map((line) => `<p>${escapeHtml(line)}</p>`)
    .join('')
}

export function normalizeRichText(html: string | null | undefined): string | null {
  if (!html || isRichTextEmpty(html)) return null
  return sanitizeRichText(html)
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

function cleanNode(node: Element): void {
  ;[...node.childNodes].forEach((child) => {
    if (child.nodeType === Node.COMMENT_NODE) {
      child.remove()
      return
    }
    if (child.nodeType === Node.TEXT_NODE) return
    if (!(child instanceof Element)) {
      child.remove()
      return
    }
    const tag = child.tagName
    if (DROP_TAGS.has(tag)) {
      child.remove()
      return
    }
    if (!ALLOWED_TAGS.has(tag)) {
      const parent = child.parentNode
      while (child.firstChild) parent?.insertBefore(child.firstChild, child)
      child.remove()
      return
    }
    for (const attr of [...child.attributes]) {
      if (tag === 'A' && attr.name === 'href') {
        const href = attr.value.trim()
        if (!/^(https?:|mailto:)/i.test(href)) {
          child.removeAttribute(attr.name)
          continue
        }
        child.setAttribute('href', href)
        child.setAttribute('target', '_blank')
        child.setAttribute('rel', 'noopener noreferrer')
        continue
      }
      child.removeAttribute(attr.name)
    }
    cleanNode(child)
  })
}
