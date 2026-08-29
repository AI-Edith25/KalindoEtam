import { apiClient } from '@/shared/services/apiClient'
import type { ApiResponse } from '@/shared/types/api'
import {
  INVOICE_ADVANCED_DEFAULTS,
  type PrintColumnKey,
  type PrintOptions,
} from '@/shared/lib/printOptions'
import type { InvoicePrintSetting } from '../types'

export async function fetchInvoicePrintSetting(): Promise<InvoicePrintSetting> {
  const { data } = await apiClient.get<ApiResponse<InvoicePrintSetting>>('/invoice-print-settings')
  return data.data
}

/** Payload shape the PUT endpoint accepts — same fields as InvoicePrintSetting minus id/updated_at. */
export type InvoicePrintSettingPayload = Omit<InvoicePrintSetting, 'id' | 'updated_at'>

export async function updateInvoicePrintSetting(payload: InvoicePrintSettingPayload): Promise<InvoicePrintSetting> {
  const { data } = await apiClient.put<ApiResponse<InvoicePrintSetting>>('/invoice-print-settings', payload)
  return data.data
}

/** DB row (snake_case) -> the same shape InvoicePrintPage's session state (PrintOptions) already uses. */
export function invoicePrintSettingToOptions(setting: InvoicePrintSetting): PrintOptions {
  return {
    fontSize: setting.font_size,
    paperType: setting.paper_type,
    qtyDecimals: setting.qty_decimals,
    priceDecimals: setting.price_decimals,
    amountDecimals: setting.amount_decimals,
    showDiscount: setting.show_discount,
    orientation: setting.orientation,
    margins: setting.margins,
    scalePercent: setting.scale_percent,
    fontFamily: setting.font_family,
    numberFormat: setting.number_format,
    showCurrencySymbol: setting.show_currency_symbol,
    visibleColumns: setting.visible_columns as PrintColumnKey[],
    showLogo: setting.show_logo,
    showAddress: setting.show_address,
    showPhone: setting.show_phone,
    showEmail: setting.show_email,
    footerNotes: setting.footer_notes ?? '',
    showSignatureBlock: setting.show_signature_block,
    signatureLeftLabel: setting.signature_left_label,
    signatureRightLabel: setting.signature_right_label,
    showPageNumber: setting.show_page_number,
  }
}

/** Same shape as the InvoicePrintSettingsPage form's own defaults — used when the GET hasn't resolved yet. */
export const FALLBACK_INVOICE_PRINT_OPTIONS: PrintOptions = {
  fontSize: 'medium',
  paperType: 'a4',
  qtyDecimals: 0,
  priceDecimals: 2,
  amountDecimals: 2,
  showDiscount: false,
  ...INVOICE_ADVANCED_DEFAULTS,
}
