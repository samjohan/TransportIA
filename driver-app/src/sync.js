import { db } from './db'
import { api } from './api'

// Push every queued gasto to the server, oldest first. Stops on first
// failure so nothing gets pushed out of order if connectivity drops mid-sync.
export async function pushQueuedMutations() {
  const queue = await db.syncQueue.orderBy('created_at').toArray()

  for (const job of queue) {
    try {
      const form = new FormData()
      Object.entries(job.payload).forEach(([key, value]) => {
        if (value !== null && value !== undefined) form.append(key, value)
      })
      if (job.fotoBlob) {
        form.append('recibo', job.fotoBlob, `${job.payload.uuid}.jpg`)
      }

      await api.post(`/${job.entity}`, form, {
        headers: { 'Content-Type': 'multipart/form-data' }
      })

      await db.syncQueue.delete(job.id)
      await db.table(job.entity).update(job.payload.uuid, { synced: 1 })
    } catch (err) {
      console.warn('Sincronización pausada:', err.message)
      break
    }
  }
}

// Pull the driver's assigned routes so they're viewable offline.
export async function pullRutasAsignadas() {
  const { data } = await api.get('/rutas')
  for (const ruta of data) {
    await db.rutas.put(ruta)
  }
}

export async function fullSync() {
  await pushQueuedMutations()
  await pullRutasAsignadas()
}
