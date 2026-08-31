// Formats as the user types (miles con punto, sin decimales — "1.500.000")
// while keeping the underlying value a plain integer of pesos colombianos.
const miles = new Intl.NumberFormat('es-CO')

export default function MoneyInput({ value, onChange, required, placeholder }) {
  const mostrado = value === '' || value === null || value === undefined
    ? ''
    : miles.format(value)

  function handleChange(e) {
    const soloDigitos = e.target.value.replace(/\D/g, '')
    onChange(soloDigitos === '' ? '' : Number(soloDigitos))
  }

  return (
    <div className="relative">
      <span className="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400">$</span>
      <input
        className="input pl-7"
        inputMode="numeric"
        value={mostrado}
        onChange={handleChange}
        required={required}
        placeholder={placeholder}
      />
      <span className="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-medium text-slate-400">COP</span>
    </div>
  )
}
