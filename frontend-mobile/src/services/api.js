import axios from 'axios'
import { clearAuthStorage, readAuthToken } from '../utils/auth'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api/v1',
  timeout: 10000,
})

const clearAuthAndRedirect = () => {
  clearAuthStorage()
  if (window.location.pathname !== '/login') {
    window.location.href = '/login'
  }
}

api.interceptors.request.use((config) => {
  const token = readAuthToken()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if ([401, 419].includes(error?.response?.status)) {
      clearAuthAndRedirect()
    }
    return Promise.reject(error)
  },
)

export default api
