import type { BeanDetail } from '../../api/schemas'

export function OrderHistory({ orders }: { orders: NonNullable<BeanDetail['orders']> }) {
  return orders.length ? (
    <ol className="cj-order-history">
      {orders.map((order) => (
        <li key={order.id} className="cj-order-history__item">
          <div>
            <strong>{order.purchase_date ? new Date(`${order.purchase_date.slice(0, 10)}T12:00:00`).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : 'Date unknown'}</strong>
            <span>{[order.quantity === null ? '' : `${order.quantity}${order.quantity_unit}`, order.form_factor === 'ground' ? 'Ground' : order.form_factor ? 'Whole bean' : ''].filter(Boolean).join(' · ')}</span>
          </div>
          <span className="cj-chip">{order.finished ? 'Completed' : 'Current bag'}</span>
        </li>
      ))}
    </ol>
  ) : <p className="cj-empty-inline">No orders recorded yet.</p>
}
