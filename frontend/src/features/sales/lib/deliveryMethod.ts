/** "Status Pengantaran" — mirrors backend App\Enums\DeliveryMethod. Named DeliveryMethod (not DeliveryStatus) to avoid colliding with the existing pending/complete/cancelled DeliveryStatus type. */
export type DeliveryMethod = 'muat_gudang' | 'antar_dengan_buruh' | 'antar_tanpa_buruh' | 'antar_proyek'

export const DELIVERY_METHOD_OPTIONS: { value: DeliveryMethod; label: string }[] = [
  { value: 'muat_gudang', label: 'Muat Gudang' },
  { value: 'antar_dengan_buruh', label: 'Antar dengan Buruh' },
  { value: 'antar_tanpa_buruh', label: 'Antar tanpa Buruh' },
  { value: 'antar_proyek', label: 'Antar Proyek' },
]

export const deliveryMethodLabel = (value: DeliveryMethod | null | undefined): string =>
  DELIVERY_METHOD_OPTIONS.find((option) => option.value === value)?.label ?? '—'
