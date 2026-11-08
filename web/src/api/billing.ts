import type { Invoice } from "./request";
export type InvoiceRow = Pick<
  Invoice,
  | "invoice_id"
  | "instance_id"
  | "instance_name"
  | "type"
  | "total"
  | "status"
  | "payment_method"
  | "created_at"
  | "paid_at"
  | "quote_expired"
  | "fulfillment_status"
  | "wallet_credit_total"
  | "late_credit_total"
>;
export const fulfillmentLabels: Record<string, string> = {
  provisioning: "履约处理中",
  investigating: "结果调查中",
  needs_review: "待管理员核验",
  delivered: "本单已交付",
  renewed: "本单续费已确认",
  updated: "本单变更已确认",
};
export function fulfillmentTitle(
  invoice: Pick<Invoice, "type" | "fulfillment_status">,
): string {
  if (invoice.fulfillment_status === "provisioning")
    return invoice.type === "renew"
      ? "续费确认中"
      : invoice.type === "upgrade"
        ? "变更处理中"
        : "交付中";
  return fulfillmentLabels[invoice.fulfillment_status || ""] || "进度待查询";
}
export function statusTone(
  status: string,
): "success" | "warning" | "danger" | "info" {
  return status === "paid"
    ? "success"
    : status === "unpaid"
      ? "warning"
      : "info";
}
export function fulfillmentTone(
  status: string,
): "success" | "warning" | "danger" | "info" {
  return ["delivered", "renewed", "updated"].includes(status)
    ? "success"
    : status === "needs_review"
      ? "danger"
      : "warning";
}
// Money remains a decimal string, including UI tests; no floating point conversion.
export function hasCredit(value?: string): boolean {
  return (
    typeof value === "string" &&
    /^\d+\.\d{2}$/.test(value) &&
    /[1-9]/.test(value)
  );
}
export function fulfillmentMessage(invoice: InvoiceRow): string {
  if (invoice.status !== "paid") return "";
  if (invoice.type === "recharge")
    return "充值款项已记入钱包，可查看当前余额和本单入账流水。";
  switch (invoice.fulfillment_status) {
    case "delivered":
      return "本单开通已确认。请进入实例查看连接信息与当前状态，账期从成功交付起算。";
    case "renewed":
      return "本单上游续费已确认。请进入实例核对账期和当前状态。";
    case "updated":
      return "本单变更已确认，请进入实例查看当前信息。";
    case "investigating":
      return "款项已结算，上游执行结果正在调查。请保留原账单，勿重复付款或重新购买。";
    case "needs_review":
      return "款项已结算，履约结果需要管理员核验。请联系管理员并提供本账单编号；不代表已经退款。";
    case "provisioning":
      return invoice.type === "renew"
        ? "续费权益已记账，正在等待上游确认；暂停状态不会仅凭付款解除。"
        : "付款已结算，正在等待资源交付。已付款不代表已经开通，请在对应实例跟踪结果。";
    default:
      return "款项已结算，履约进度暂不可用。请刷新原账单或联系管理员核验，勿重复付款。";
  }
}
