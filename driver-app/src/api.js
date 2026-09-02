import axios from 'axios'

export const api = axios.create({ baseURL: '/api', headers: { Accept: 'application/json' } })

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

// A dead/invalid token (e.g. the backend's database was reset, or the
// token just expired) makes every request 401 forever. Without this, sync
// failures were only logged to the console — the app kept silently showing
// whatever was last cached, with no visible sign anything was wrong. Force
// back to the login screen instead so the driver gets a fresh, working token.
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('token')
      localStorage.removeItem('user')
      window.location.reload()
    }
    return Promise.reject(error)
  }
)
