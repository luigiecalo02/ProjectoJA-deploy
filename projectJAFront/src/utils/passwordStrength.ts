export const PASSWORD_MAX_LENGTH = 64
export const PASSWORD_MIN_LENGTH = 6

export type PasswordStrengthLevel = 'mala' | 'facil' | 'media' | 'dificil'

export type PasswordStrength = {
  level: PasswordStrengthLevel
  canSave: boolean
  hasUpper: boolean
  hasSymbol: boolean
  withinMax: boolean
}

export function evaluatePasswordStrength(password: string): PasswordStrength {
  const length = password.length
  const hasUpper = /[A-Z]/.test(password)
  const hasSymbol = /[^A-Za-z0-9]/.test(password)
  const hasLower = /[a-z]/.test(password)
  const hasNumber = /\d/.test(password)
  const withinMax = length <= PASSWORD_MAX_LENGTH
  const meetsBasics =
    length >= PASSWORD_MIN_LENGTH && withinMax && hasUpper && hasSymbol

  if (!meetsBasics) {
    return {
      level: 'mala',
      canSave: false,
      hasUpper,
      hasSymbol,
      withinMax,
    }
  }

  let extras = 0
  if (length >= 10) extras += 1
  if (length >= 12) extras += 1
  if (hasLower) extras += 1
  if (hasNumber) extras += 1

  const level: PasswordStrengthLevel =
    extras >= 3 ? 'dificil' : extras >= 1 ? 'media' : 'facil'

  return {
    level,
    canSave: true,
    hasUpper,
    hasSymbol,
    withinMax,
  }
}
