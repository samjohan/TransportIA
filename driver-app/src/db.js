import Dexie from 'dexie'

export const db = new Dexie('appConductorDB')

db.version(1).stores({
  // Rutas asignadas al conductor, cacheadas localmente para verlas offline
  rutas: 'uuid, estado',
  // Gastos registrados (offline o online)
  gastos: 'uuid, ruta_uuid, synced, created_at',
  // Cola de sincronización genérica
  syncQueue: '++id, entity, created_at'
})

export async function queueMutation(entity, payload, fotoBlob = null, fotoBlob2 = null) {
  return db.syncQueue.add({
    entity,
    payload,
    // Blobs (receipt photos) go straight into IndexedDB — no size limit
    // issue like localStorage would have.
    fotoBlob,
    fotoBlob2,
    created_at: new Date().toISOString()
  })
}
