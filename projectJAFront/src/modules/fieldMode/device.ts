const DEVICE_ID_KEY = 'projectja_field_device_id'

export interface FieldDeviceInfo {
  id: string
  label: string
}

function browserName(): string {
  const ua = navigator.userAgent
  if (/Edg\//.test(ua)) return 'Edge'
  if (/OPR\//.test(ua) || /Opera/.test(ua)) return 'Opera'
  if (/Chrome\//.test(ua) && !/Edg\//.test(ua)) return 'Chrome'
  if (/Safari\//.test(ua) && !/Chrome\//.test(ua)) return 'Safari'
  if (/Firefox\//.test(ua)) return 'Firefox'
  return 'Navegador'
}

function kindName(): string {
  return /Mobi|Android|iPhone|iPad/i.test(navigator.userAgent) ? 'Teléfono' : 'Computadora'
}

export function getFieldDevice(): FieldDeviceInfo {
  let id = localStorage.getItem(DEVICE_ID_KEY)
  if (!id) {
    id = crypto.randomUUID?.() ?? `dev-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
    localStorage.setItem(DEVICE_ID_KEY, id)
  }
  return {
    id,
    label: `${kindName()} · ${browserName()}`,
  }
}
