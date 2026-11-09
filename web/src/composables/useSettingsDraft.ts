import { computed, onScopeDispose, ref, shallowRef } from "vue";
import { ApiError, message } from "../api/request";

export const copySettingsDraft = <T>(value: T): T => value === undefined ? value : JSON.parse(JSON.stringify(value));

// Shared by independently versioned settings forms; no persistent or global draft.
export function useSettingsDraft<T extends { version: string }, O = undefined>(
  fields: (value: T) => Record<string, unknown>,
  transport: { read: () => Promise<{ value: T; options: O }>; write: (body: Record<string, unknown>) => Promise<{ saved: boolean }>; adopt?: (next: T, draft: T) => T },
) {
  const saved = shallowRef<T>(), draft = ref<T>(), observed = shallowRef<T>();
  const recipients = shallowRef<O>(), checking = ref(false), saving = ref(false);
  const error = ref(""), notice = ref(""), readFailed = ref(false);
  const attempt = shallowRef<{ version: string; fields: string; acknowledged: boolean; rejected: boolean; secret: boolean }>();
  let disposed = false;
  onScopeDispose(() => { disposed = true; });
  const signature = (value: T) => JSON.stringify(fields(value));
  function checkSnapshot(value: T) {
    if (typeof value?.version !== "string" || value.version.length !== 64) throw new Error("未取得可信配置版本");
    return signature(value);
  }
  const dirty = computed(() => !!draft.value && !!saved.value && signature(draft.value as T) !== signature(saved.value));
  const needsReview = computed(() => readFailed.value || !!attempt.value || (!!observed.value && !!saved.value && observed.value.version !== saved.value.version));
  const canAdopt = computed(() => !readFailed.value && needsReview.value && !!observed.value && (!attempt.value || attempt.value.rejected || observed.value.version !== attempt.value.version));
  const busy = computed(() => saving.value || checking.value);

  function initialize(value: T, options: O) {
    checkSnapshot(value);
    saved.value = copySettingsDraft(value);
    draft.value = copySettingsDraft(value);
    recipients.value = copySettingsDraft(options);
    observed.value = undefined; attempt.value = undefined; readFailed.value = false;
  }
  function adopt(keepDraft: boolean) {
    if (busy.value || !canAdopt.value || !observed.value || !draft.value) return;
    const next = observed.value;
    draft.value = copySettingsDraft(keepDraft ? (transport.adopt?.(next, draft.value as T) ?? { ...next, ...fields(draft.value as T) } as T) : next);
    saved.value = copySettingsDraft(next); observed.value = undefined; attempt.value = undefined;
    error.value = ""; notice.value = keepDraft ? "已采用当前版本并保留本区草稿；如需保存，请再次明确提交。" : "已采用当前保存值；本区草稿已放弃。";
  }
  async function readCurrent() {
    checking.value = true;
    try {
      const current = await transport.read();
      if (disposed) return;
      const next = current.value;
      const nextFields = checkSnapshot(next);
      observed.value = copySettingsDraft(next); recipients.value = current.options; readFailed.value = false;
      const pending = attempt.value;
      if (pending) {
        const changed = next.version !== pending.version;
        const matches = nextFields === pending.fields;
        // A changed CAS version fences a delayed old write. Public metadata cannot verify an unknown password replacement.
        if (changed && matches && (pending.acknowledged || !pending.secret)) {
          initialize(next, current.options); error.value = "";
          notice.value = pending.acknowledged ? "保存已确认，已读取本区当前配置。" : "只读核对：当前保存值已与本次提交一致，未再次保存。";
        } else {
          error.value = pending.secret && !pending.acknowledged
            ? "密钥替换结果待人工核对：“已配置”不能证明本次替换成功，不会自动再次替换。"
            : !changed && !pending.rejected
              ? "保存结果仍未知，当前版本未变化；原请求可能仍在处理，请继续只读核对。"
              : "当前保存值与本次提交需要核对；草稿保留，请明确采用当前值或保留草稿后重新确认。";
        }
      } else if (!dirty.value) {
        initialize(next, current.options); error.value = ""; notice.value = "已核对本区当前保存值。";
      } else {
        error.value = next.version !== saved.value?.version ? "本区已保存配置有更新；未覆盖草稿，请核对后采用当前版本。" : "";
        notice.value = "已读取本区保存值，未覆盖未保存草稿。";
      }
    } catch (e) {
      if (!disposed) { readFailed.value = true; error.value = message(e) + "；核对失败，原草稿与版本保留，请先重新核对。"; }
    } finally { checking.value = false; }
  }
  async function check() { if (!busy.value) await readCurrent(); }
  async function save(extra: Record<string, unknown> = {}) {
    if (disposed || busy.value || needsReview.value || !saved.value || !draft.value) return;
    const body = { ...copySettingsDraft(fields(draft.value as T)), expected_version: saved.value.version, ...extra };
    attempt.value = { version: saved.value.version, fields: signature(draft.value as T), acknowledged: false, rejected: false, secret: extra.password_action === "replace" || extra.key_action === "replace" };
    observed.value = undefined; error.value = ""; notice.value = ""; saving.value = true;
    try {
      const result = await transport.write(body);
      if (disposed) return;
      if (result?.saved !== true) throw new Error("未取得可信保存确认");
      attempt.value!.acknowledged = true;
    } catch (e) {
      if (disposed) return;
      attempt.value!.rejected = e instanceof ApiError && e.businessResponse && [400, 403, 404, 409, 422].includes(e.status);
      error.value = message(e) + "；先只读核对保存结果，不会自动重提。";
    } finally {
      if (!disposed) await readCurrent();
      saving.value = false;
    }
  }
  return { saved, draft, observed, recipients, checking, saving, error, notice, dirty, needsReview, canAdopt, busy, initialize, adopt, check, save };
}
