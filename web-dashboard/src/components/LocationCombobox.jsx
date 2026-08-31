import { useMemo, useRef, useState } from 'react'

// Searchable text input backed by a list of known place names (see
// `/api/ubicaciones`). The list isn't a fixed enum — typing a name that
// isn't in it yet is fine, it just won't show a suggestion; the backend
// registers it as a new place the moment the route is created.
export default function LocationCombobox({ label, value, onChange, options, placeholder, required }) {
  const [open, setOpen] = useState(false)
  const [highlight, setHighlight] = useState(-1)
  const blurTimeout = useRef(null)

  const filtradas = useMemo(() => {
    const term = value.trim().toLowerCase()
    const lista = term
      ? options.filter((o) => o.toLowerCase().includes(term))
      : options
    return lista.slice(0, 8)
  }, [value, options])

  function seleccionar(nombre) {
    onChange(nombre)
    setOpen(false)
    setHighlight(-1)
  }

  function handleKeyDown(e) {
    if (!open) return
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      setHighlight((i) => Math.min(i + 1, filtradas.length - 1))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setHighlight((i) => Math.max(i - 1, 0))
    } else if (e.key === 'Enter' && highlight >= 0 && filtradas[highlight]) {
      e.preventDefault()
      seleccionar(filtradas[highlight])
    } else if (e.key === 'Escape') {
      setOpen(false)
    }
  }

  return (
    <div className="relative">
      {label && (
        <label className="label">
          {label} {required && <span className="text-red-500">*</span>}
        </label>
      )}
      <input
        className="input"
        value={value}
        placeholder={placeholder}
        required={required}
        autoComplete="off"
        onChange={(e) => { onChange(e.target.value); setOpen(true); setHighlight(-1) }}
        onFocus={() => setOpen(true)}
        onBlur={() => { blurTimeout.current = setTimeout(() => setOpen(false), 150) }}
        onKeyDown={handleKeyDown}
      />
      {open && filtradas.length > 0 && (
        <ul className="absolute z-20 mt-1 w-full max-h-48 overflow-auto rounded-lg border border-slate-200 bg-white shadow-lg text-sm">
          {filtradas.map((nombre, i) => (
            <li key={nombre}>
              <button
                type="button"
                onMouseDown={(e) => { e.preventDefault(); clearTimeout(blurTimeout.current); seleccionar(nombre) }}
                className={`block w-full text-left px-3 py-2 ${
                  i === highlight ? 'bg-brand-50 text-brand-700' : 'hover:bg-slate-50 text-slate-700'
                }`}
              >
                {nombre}
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
