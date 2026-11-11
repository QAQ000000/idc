import type { SnapshotPreview } from "../api/adminProducts";
import type { UpstreamCatalogItem } from "../api/adminProviders";

export type ItemState = "pending" | "previewing" | "ready" | "submitting" | "success" | "existing" | "rejected" | "confirm" | "unknown";
export interface ImportInput {
  provider_id: number; remote_pid: number; name: string; type: string;
  markup_type: string; markup_value: string; billing_cycles: string[];
  config_options?: Record<string, string>;
  category_id?: number | null;
}
export interface BatchItem {
  input: ImportInput; state: ItemState; message: string; preview?: SnapshotPreview;
  payload?: ImportInput & { preview_token: string; evidence: string };
  productId?: number; outcome?: "created" | "replayed" | "recovered";
}
export class BatchRequestError extends Error {
  constructor(message: string, public status: number, public code: number, public authoritative: boolean) { super(message); }
}
export interface BatchTransport {
  guard(readOnly?: boolean): Promise<void>;
  preview(input: ImportInput): Promise<SnapshotPreview>;
  submit(input: NonNullable<BatchItem["payload"]>): Promise<{ product_id: number; outcome?: string }>;
  reconcile(item: BatchItem): Promise<{ product_id?: number; matched: boolean }>;
}
const clone = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T;

/** One page-owned, sequential dispatcher. No persistence, timers, or automatic resubmission. */
export class ImportBatch {
  items: BatchItem[] = [];
  running = false;
  paused = false;
  notice = "";
  private disposed = false;
  constructor(private transport: BatchTransport, private now: () => number = () => Date.now() / 1000) {}
  get unknown() { return this.items.some(item => item.state === "unknown"); }
  get ready() { return this.items.filter(item => item.state === "ready"); }
  start(provider: number, selected: UpstreamCatalogItem[], plan: { markup_type: string; markup_value: string; billing_cycles: string[]; evidence: string; category_id?: number | null }) {
    if (this.running || this.unknown || this.disposed || !selected.length || selected.length > 20
      || new Set(selected.map(item => item.remote_pid)).size !== selected.length
      || selected.some(item => item.imported || item.type === "unsupported") || !plan.billing_cycles.length
      || new TextEncoder().encode(plan.evidence.trim()).length < 10 || new TextEncoder().encode(plan.evidence.trim()).length > 1000) return false;
    this.evidence = plan.evidence.trim();
    this.items = selected.map(item => ({ state: "pending", message: "尚未预检", input: {
      provider_id: provider, remote_pid: item.remote_pid, name: item.name, type: item.type,
      markup_type: plan.markup_type, markup_value: plan.markup_value, billing_cycles: [...plan.billing_cycles],
      category_id: plan.category_id ?? null,
    } }));
    this.paused = false; this.notice = "先分组预检，再核对摘要确认导入；新商品始终默认下架。";
    return true;
  }
  private evidence = "";
  stop(reason = "已停止后续派发；在途请求仍会返回，请等待其结果。") { this.paused = true; this.notice = reason; }
  dispose() { this.stop(); this.disposed = true; }
  clear() {
    if (this.running || this.unknown) return false;
    this.items = []; this.notice = ""; return true;
  }
  private expired(item: BatchItem) {
    const token = item.payload?.preview_token || "";
    return !/^\d{10}\.[a-f0-9]{64}$/.test(token) || Number(token.split(".")[0]) <= this.now();
  }
  private async guard(readOnly = false) {
    try { await this.transport.guard(readOnly); return !this.paused && !this.disposed; }
    catch (error) { this.stop(error instanceof Error ? error.message : "身份或连接校验失败，整批暂停"); return false; }
  }
  private failure(item: BatchItem, error: unknown, sent: boolean) {
    const known = error instanceof BatchRequestError && error.authoritative;
    item.message = error instanceof Error ? error.message : "未取得可信结果";
    if (sent && (!known || ![400, 401, 403, 409, 422].includes(error.status))) {
      item.state = "unknown"; this.stop("有导入结果待核对，已停止后续派发；请先只读核对或显式原确认重试。");
    } else if (known && [40911, 40912].includes(error.code)) {
      item.state = "confirm"; item.message += "；请重新预检并人工确认，不会自动提交。";
    } else if (known && error.code === 40913) {
      item.state = "existing"; item.message = "已存在，未应用本批改动；请刷新目录查看原商品。";
    } else if (known && ([400, 422].includes(error.status) || error.code === 40914)) {
      item.state = "rejected";
    } else {
      // Generic 409/503 may hide connection or supplier failures. Never guess it is item-local.
      item.state = sent ? "confirm" : "pending";
      this.stop("身份、连接或上游读取异常，整批暂停：" + item.message);
    }
  }
  async previewNext(only?: BatchItem) {
    if (this.running || this.unknown || this.disposed) return;
    const queue = only ? (this.items.includes(only) && ["pending", "ready", "rejected", "confirm"].includes(only.state) ? [only] : [])
      : this.items.filter(item => item.state === "pending").slice(0, 5);
    this.running = true; this.paused = false;
    try {
      for (const item of queue) {
        if (!await this.guard()) break;
        item.state = "previewing"; item.preview = undefined; item.payload = undefined;
        try {
          const preview = await this.transport.preview(clone(item.input));
          if (!preview || !/^\d{10}\.[a-f0-9]{64}$/.test(preview.preview_token) || !preview.defaults
            || !preview.billing_cycles?.length || preview.billing_cycles.some(c => !/^\d+\.\d{2}$/.test(preview.prices?.[c] || "") || !/^\d+\.\d{2}$/.test(preview.recurring_prices?.[c] || ""))) {
            throw new Error("预检响应不完整，请核对后重新预检");
          }
          item.preview = clone(preview);
          item.payload = { ...clone(item.input), config_options: clone(preview.defaults), evidence: this.evidence, preview_token: preview.preview_token };
          item.state = "ready"; item.message = "预检完成，等待人工确认摘要";
        } catch (error) { this.failure(item, error, false); }
        if (this.paused || this.disposed) break;
      }
    } finally { this.running = false; }
  }
  private async submit(item: BatchItem, retry = false) {
    if (!item.payload) return;
    const payload = clone(item.payload);
    item.state = "submitting";
    try {
      const result = await this.transport.submit(payload);
      if (!result || !Number.isSafeInteger(result.product_id) || result.product_id <= 0 || !["created", "replayed"].includes(result.outcome || "")) throw new Error("导入响应不完整，结果待核对");
      item.productId = result.product_id; item.outcome = result.outcome as "created" | "replayed";
      item.state = "success"; item.message = result.outcome === "replayed" ? "原确认重放成功；未重复创建" : "已导入并默认下架";
    } catch (error) {
      if (retry) { item.state = "unknown"; item.message = (error instanceof Error ? error.message : "核对未完成") + "；原操作仍待核对，不更换令牌。"; this.stop(); }
      else this.failure(item, error, true);
    }
  }
  async confirm() {
    if (this.running || this.unknown || this.disposed) return;
    const queue = [...this.ready];
    this.running = true; this.paused = false; this.notice = "正在逐件导入；停止派发不会撤销已发送的请求。";
    try {
      for (const item of queue) {
        if (this.expired(item)) { item.state = "confirm"; item.message = "确认已过期，请重新预检并人工确认"; continue; }
        if (!await this.guard()) break;
        // Guard itself may be slow; recheck expiry immediately before dispatch.
        if (this.expired(item)) { item.state = "confirm"; item.message = "确认已过期，请重新预检并人工确认"; continue; }
        await this.submit(item);
        if (this.paused || this.disposed) break;
      }
    } finally {
      this.running = false;
      if (!this.paused && !this.disposed) this.notice = "本轮顺序派发已结束；请查看逐项结果，未预检或需重新确认的项目不会自动提交。";
    }
  }
  async retry(item: BatchItem) {
    if (this.running || item.state !== "unknown" || this.disposed) return;
    if (this.expired(item)) { item.message = "原确认已过期，只能只读核对；不能换取新令牌重试未知操作。"; return; }
    this.running = true; this.paused = false;
    try {
      if (!await this.guard()) return;
      if (this.expired(item)) { item.message = "原确认已过期，只能只读核对；不能换取新令牌重试未知操作。"; return; }
      await this.submit(item, true);
    }
    finally { this.running = false; this.stop("原操作已检查；剩余项仍需核对摘要后显式继续。"); }
  }
  async reconcile(item: BatchItem) {
    if (this.running || item.state !== "unknown" || this.disposed) return;
    this.running = true; this.paused = false;
    try {
      if (!await this.guard(true)) return;
      const result = await this.transport.reconcile(clone(item));
      item.productId = result.product_id;
      if (result.matched && result.product_id) { item.state = "success"; item.outcome = "recovered"; item.message = "持久审计匹配原确认，已找回；未重新提交。"; }
      else item.message = result.product_id ? "存在本地绑定，但未匹配原确认；仍待核对，不能记为本批成功。" : "尚未找到原确认的持久记录，仍待核对；未发送导入请求。";
    } catch (error) { item.message = error instanceof Error ? error.message : "只读核对失败"; }
    finally { this.running = false; this.stop("核对结束；未自动继续派发。"); }
  }
}
