import { formatCOP } from '../format'

// Compact anticipo-vs-gastado comparison: amounts plus a progress bar that
// turns red past 100% of the anticipo.
export default function AnticipoBar({ anticipo, gastado }) {
  const anticipoNum = Number(anticipo) || 0
  const gastadoNum = Number(gastado) || 0
  const pct = anticipoNum > 0 ? Math.min((gastadoNum / anticipoNum) * 100, 100) : 0
  const excedido = gastadoNum > anticipoNum

  return (
    <div className="min-w-[140px]">
      <div className="flex justify-between text-xs mb-1">
        <span className={excedido ? 'font-medium text-red-600' : 'text-slate-600'}>
          {formatCOP(gastadoNum)}
        </span>
        <span className="text-slate-400">de {formatCOP(anticipoNum)}</span>
      </div>
      <div className="h-1.5 w-full rounded-full bg-slate-100 overflow-hidden">
        <div
          className={`h-full rounded-full ${excedido ? 'bg-red-500' : 'bg-emerald-500'}`}
          style={{ width: `${pct}%` }}
        />
      </div>
    </div>
  )
}
