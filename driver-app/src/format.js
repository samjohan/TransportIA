// App exclusiva para operación en Colombia: todos los montos están en
// pesos colombianos (COP), que no usan decimales en el uso cotidiano.
const cop = new Intl.NumberFormat('es-CO', {
  style: 'currency',
  currency: 'COP',
  maximumFractionDigits: 0,
})

export function formatCOP(value) {
  const n = Number(value)
  return Number.isFinite(n) ? cop.format(n) : '—'
}
