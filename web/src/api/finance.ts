import type { Page } from "./admin";
export const ledgerLabels: Record<string, string> = {
  recharge: "充值到账",
  pay_invoice: "余额付款",
  late_payment_credit: "迟到／额外实收入账",
  refund_to_balance: "退至站内余额",
  refund: "退款",
  refund_rollback: "退款预扣退回",
  affiliate_to_balance: "推广金转入",
  commission_settle: "佣金结算",
  commission_clawback: "佣金冲正",
  withdrawal_freeze: "提现冻结",
  withdrawal_payout: "提现支出",
  withdrawal_reject_rollback: "提现退回",
};
export const invoiceLabels: Record<string, string> = {
  unpaid: "待付款",
  paid: "已付款",
  cancelled: "已取消",
  refund_pending: "退款处理中",
  refunded: "已退款",
};
export const invoiceTypes: Record<string, string> = {
  new: "新购",
  renew: "实例续费",
  upgrade: "变配补差",
  recharge: "余额充值",
};
export const receiptLabels: Record<string, string> = {
  pending: "待付款尝试",
  success: "已确认实收",
  failed: "失败",
  late_credited_to_balance: "独立实收已入钱包",
};
export interface WalletEntry {
  id: number;
  user_id: number;
  invoice_id: number | null;
  type: string;
  amount: string;
  balance_before: string;
  balance_after: string;
  created_at: string;
  payment_record_id: number | null;
  user_email?: string;
}
export interface FinanceInvoice {
  invoice_id: number;
  user_id: number;
  instance_id: number | null;
  type: string;
  total: string;
  status: string;
  payment_method: string | null;
  created_at: string;
  paid_at: string | null;
  user_email?: string;
}
export interface Receipt {
  id: number;
  invoice_id: number;
  user_id: number;
  channel: string;
  trade_no: string;
  gateway_trade_no: string | null;
  amount: string;
  currency: string;
  status: string;
  refund_status: string;
  refund_amount: string;
  created_at: string;
  paid_at: string | null;
  settlement_purpose: string | null;
  user_email?: string;
}
export interface FinanceDetail {
  invoice: FinanceInvoice;
  customer: { id: number; email: string; balance: string; status: string };
  receipts: Page<Receipt>;
  transactions: Page<WalletEntry>;
}
