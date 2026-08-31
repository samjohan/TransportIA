import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './App'
import { fullSync } from './sync'
import './index.css'

if (navigator.onLine) fullSync()

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
)
