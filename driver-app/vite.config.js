import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { VitePWA } from 'vite-plugin-pwa'

// Allows the proxy target to be overridden when running inside Docker
// Compose, where the backend is reachable at http://backend:8000 instead of
// http://localhost:8000.
const apiProxyTarget = process.env.VITE_API_PROXY_TARGET || 'http://localhost:8000'

export default defineConfig({
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      manifest: {
        name: 'App Conductor',
        short_name: 'Conductor',
        description: 'Registro de gastos de ruta',
        theme_color: '#111827',
        background_color: '#ffffff',
        display: 'standalone',
        lang: 'es',
        icons: [
          { src: 'icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: 'icon-512.png', sizes: '512x512', type: 'image/png' }
        ]
      },
      workbox: {
        // Include Tesseract.js's worker/wasm/traineddata files so OCR
        // keeps working with zero connectivity after the first load.
        globPatterns: ['**/*.{js,css,html,ico,png,svg,wasm,traineddata,gz}'],
        maximumFileSizeToCacheInBytes: 15 * 1024 * 1024
      }
    })
  ],
  server: {
    host: true,
    // Deployed behind Dokploy/Traefik with a random per-deploy sslip.io (or
    // later, a real custom) domain — Vite's Host-header allowlist would
    // otherwise reject every request unless that exact, changing hostname
    // were hardcoded here.
    allowedHosts: true,
    proxy: {
      '/api': { target: apiProxyTarget, changeOrigin: true },
      '/storage': { target: apiProxyTarget, changeOrigin: true }
    }
  }
})
