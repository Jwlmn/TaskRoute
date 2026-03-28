const TOKEN_KEY = 'taskroute_token'
const USER_KEY = 'taskroute_user'

let bootstrapPromise = null
let authResolved = false

export const readAuthToken = () => localStorage.getItem(TOKEN_KEY)

export const readCurrentUser = () => {
  const raw = localStorage.getItem(USER_KEY)
  if (!raw) {
    return null
  }
  try {
    return JSON.parse(raw)
  } catch {
    return null
  }
}

export const persistAuthSession = (token, user) => {
  if (token) {
    localStorage.setItem(TOKEN_KEY, token)
  } else {
    localStorage.removeItem(TOKEN_KEY)
  }

  if (user) {
    localStorage.setItem(USER_KEY, JSON.stringify(user))
  } else {
    localStorage.removeItem(USER_KEY)
  }

  authResolved = Boolean(token && user)
  bootstrapPromise = null
}

export const clearAuthStorage = () => {
  localStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(USER_KEY)
  authResolved = false
  bootstrapPromise = null
}

const isAuthFailureStatus = (status) => status === 401 || status === 419

export const ensureAuthSession = async (api, { force = false } = {}) => {
  const token = readAuthToken()
  if (!token) {
    clearAuthStorage()
    return null
  }

  const cachedUser = readCurrentUser()
  if (!force && authResolved && cachedUser) {
    return cachedUser
  }

  if (!force && bootstrapPromise) {
    return bootstrapPromise
  }

  bootstrapPromise = (async () => {
    try {
      const { data } = await api.get('/auth/me')
      persistAuthSession(token, data)
      return data
    } catch (error) {
      if (isAuthFailureStatus(error?.response?.status)) {
        clearAuthStorage()
        return null
      }

      return cachedUser
    } finally {
      bootstrapPromise = null
    }
  })()

  return bootstrapPromise
}

export const hasPermission = (user, permission) => {
  if (!user) {
    return false
  }
  const permissions = Array.isArray(user.permissions) ? user.permissions : []
  return permissions.includes(permission)
}
