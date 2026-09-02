import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './App'
import { fullSync } from './sync'
import './index.css'

// Only sync if someone is actually logged in — otherwise this 401s
// immediately (no token to send), which used to be silently ignored but
// now triggers the api.js interceptor's forced reload, causing a loop.
if (navigator.onLine && localStorage.getItem('token')) fullSync()

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
)
