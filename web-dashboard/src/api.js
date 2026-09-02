import axios from 'axios'

export const api = axios.create({ baseURL: '/api', headers: { Accept: 'application/json' } })

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

// A dead/invalid token (e.g. the backend's database was reset, or the
// token just expired) makes every request 401 forever with no visible
// error. Force back to the login screen instead so the user gets a fresh,
// working token.
//
// Only do this for a request that actually SENT a token — an anonymous
// request 401ing is normal, not a dead session, and reloading for that
// would just 401 the same way again forever.
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401 && error.config?.headers?.Authorization) {
      localStorage.removeItem('token')
      localStorage.removeItem('user')
      window.location.reload()
    }
    return Promise.reject(error)
  }
)
