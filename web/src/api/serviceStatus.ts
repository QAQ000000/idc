export interface ServiceObservation {
  state: string; suspension: string; checked_at: string | null; remote_due_date: string | null;
  review_required: boolean; code: string;
}
export const entitlementLabels: Record<string,string> = { valid:'本地权益有效', expired:'本地权益已到期', not_started:'尚未起租', not_applicable:'一次性权益' };
export const upstreamServiceLabels: Record<string,string> = { not_checked:'尚未核对', active:'上游确认服务启用', suspended:'上游确认服务暂停', terminated:'上游报告已终止（待核验）', cancelled:'上游报告已取消（待核验）', pending:'上游报告待处理（待核验）', unknown:'本次查询无法确认' };
