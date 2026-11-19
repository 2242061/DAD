export type WalletOperation = {
  id: string
  kind: 'deposit' | 'withdrawal' | 'entry' | 'refund' | 'prize' | 'adjustment'
  status: 'processing' | 'review' | 'completed' | 'failed'
  method: string
  reference: string
  amount_euros: number
  gross_coins: number
  fee_coins: number
  net_coins: number
  debit_coins: number
  delta: number | null
  resulting_balance: number | null
  failure_code: string | null
  created_at: string
  finished_at: string | null
  resolved: boolean
}
export type WalletRules = {
  currency: string
  symbol: string
  coins_per_euro: number
  fee_percent: number
  minimum_euros: number
  maximum_euros: number
  simulated: boolean
}
export type WalletResponse = {
  wallet: { balance: number; reserved: number; available: number }
  rules: WalletRules
  stats: { deposited: number; withdrawn: number; fees: number }
  data: WalletOperation[]
  unresolved: boolean
  meta: { current_page: number; last_page: number; total: number }
}
export const walletStatus: Record<string, string> = {
  processing: 'A processar',
  review: 'A verificar',
  completed: 'Concluída',
  failed: 'Recusada',
}
export const paymentMethods: Record<string, { name: string; hint: string; sample: string }> = {
  MBWAY: { name: 'MB WAY', hint: '9 algarismos, a começar por 9.', sample: '915785345' },
  PAYPAL: { name: 'PayPal', hint: 'Endereço de email válido.', sample: 'jogador@example.test' },
  IBAN: {
    name: 'IBAN',
    hint: '2 letras maiúsculas seguidas de 23 algarismos.',
    sample: 'PT50123456781234567812349',
  },
  MB: {
    name: 'Multibanco',
    hint: '5 algarismos, hífen e 9 algarismos.',
    sample: '45634-123456789',
  },
  VISA: { name: 'Visa', hint: '16 algarismos, a começar por 4.', sample: '4321567812345678' },
}
export function coins(value: number) {
  return new Intl.NumberFormat('pt-PT').format(value)
}
export function euros(value: number) {
  return new Intl.NumberFormat('pt-PT', { style: 'currency', currency: 'EUR' }).format(value)
}
export function walletDate(value: string) {
  return new Date(value).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' })
}
export function operationMessage(op: WalletOperation) {
  if (op.method === 'CLUB') return `${walletKinds[op.kind]} · ${op.reference}`
  if (op.status === 'completed')
    return op.kind === 'deposit'
      ? `Carregamento concluído. Recebeste ${coins(op.net_coins)} Damas.`
      : `Levantamento simulado de ${euros(op.amount_euros)} concluído. Saíram ${coins(op.debit_coins)} Damas, incluindo a comissão.`
  if (op.status === 'failed')
    return 'O serviço simulado recusou a operação. O saldo foi preservado e qualquer reserva foi libertada.'
  if (op.status === 'review')
    return 'Não foi possível confirmar o resultado. A operação ficou a verificar; não será repetida automaticamente. Contacta um administrador com o identificador do pedido.'
  return 'O pedido está a processar. Atualiza a carteira para consultar o resultado antes de tentar outra operação.'
}

export const walletKinds = {
  deposit: 'Carregamento',
  withdrawal: 'Levantamento',
  entry: 'Entrada',
  refund: 'Reembolso',
  prize: 'Prémio',
  adjustment: 'Ajuste de saldo',
}
