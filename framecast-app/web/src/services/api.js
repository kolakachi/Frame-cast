import axios from 'axios'

const baseURL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000'
const defaultHeaders = {
  Accept: 'application/json',
  'Content-Type': 'application/json',
}

const api = axios.create({
  baseURL: `${baseURL}/api/v1`,
  withCredentials: true,
  headers: defaultHeaders,
})

const refreshApi = axios.create({
  baseURL: `${baseURL}/api/v1`,
  withCredentials: true,
  headers: defaultHeaders,
})

let refreshPromise = null

export function setApiAccessToken(token) {
  if (token) {
    api.defaults.headers.common.Authorization = `Bearer ${token}`
    return
  }

  delete api.defaults.headers.common.Authorization
}

export function configureApiClient(authStore) {
  setApiAccessToken(authStore.accessToken)

  api.interceptors.response.use(
    (response) => response,
    async (error) => {
      const status = error.response?.status
      const originalRequest = error.config

      if (status !== 401 || originalRequest?._retry) {
        return Promise.reject(error)
      }

      if (originalRequest?.url?.includes('/auth/refresh')) {
        authStore.clearSession()
        return Promise.reject(error)
      }

      originalRequest._retry = true

      refreshPromise ??= authStore.refreshAccessToken(refreshApi).finally(() => {
        refreshPromise = null
      })

      try {
        const token = await refreshPromise
        originalRequest.headers.Authorization = `Bearer ${token}`
        return api(originalRequest)
      } catch (refreshError) {
        authStore.clearSession()
        return Promise.reject(refreshError)
      }
    },
  )
}

export { refreshApi }
export default api
