// App exclusiva para operación en Colombia: todos los montos están en
// pesos colombianos (COP), que no usan decimales en el uso cotidiano.
const cop = new Intl.NumberFormat('es-CO', {
  style: 'currency',
  currency: 'COP',
  maximumFractionDigits: 0,
})

const copCompact = new Intl.NumberFormat('es-CO', {
  style: 'currency',
  currency: 'COP',
  notation: 'compact',
  maximumFractionDigits: 1,
})

export function formatCOP(value) {
  const n = Number(value)
  return Number.isFinite(n) ? cop.format(n) : '—'
}

export function formatCOPCompact(value) {
  const n = Number(value)
  return Number.isFinite(n) ? copCompact.format(n) : '—'
}
