// Exercises the real scheduler without Vue, HTTP, credentials, or a business database.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from '../web/node_modules/typescript/lib/typescript.js';
const source = readFileSync(new URL('../web/src/catalog/importBatch.ts', import.meta.url), 'utf8');
const { outputText } = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } });
const { ImportBatch, BatchRequestError } = await import('data:text/javascript;base64,' + Buffer.from(outputText).toString('base64'));
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; console.log('PASS batch:', label); }
const selected = (count = 3) => Array.from({ length: count }, (_, i) => ({ remote_pid: i + 1, name: 'Fixture ' + (i + 1), type: 'cloud', imported: false }));
const plan = { markup_type: 'none', markup_value: '0.00', billing_cycles: ['monthly'], evidence: 'isolated batch acceptance' };
function setup(count = 3) {
  const log = []; let clock = 1794355200;
  const transport = {
    guard: async () => { log.push(['guard']); },
    preview: async input => {
      log.push(['preview', input.remote_pid]);
      return { fields: [], defaults: {}, prices: { monthly: '1.23' }, recurring_prices: { monthly: '2.34' }, billing_cycles: ['monthly'], preview_token: `${clock + 300}.${String(input.remote_pid).padStart(64, 'a')}`, expires_at: '2026-11-11 12:05:00' };
    },
    submit: async input => { log.push(['submit', structuredClone(input)]); return { product_id: input.remote_pid + 100, outcome: 'created' }; },
    reconcile: async () => ({ matched: false }),
  };
  const batch = new ImportBatch(transport, () => clock);
  check(batch.start(9, selected(count), plan), 'valid selection builds frozen per-product inputs');
  return { batch, transport, log, advance: seconds => clock += seconds };
}
const dispatches = log => log.filter(row => row[0] === 'submit');
{
  const { batch, log } = setup(7);
  await batch.previewNext();
  check(log.filter(row => row[0] === 'preview').length === 5 && batch.ready.length === 5, 'one precheck group is bounded to five');
  check(dispatches(log).length === 0, 'prechecks never import without confirmation');
  await batch.confirm();
  check(batch.items.filter(row => row.state === 'success').length === 5 && batch.items[5].state === 'pending', 'confirmation submits only this prepared group');
  check(dispatches(log).every((row, i) => row[1].remote_pid === i + 1 && row[1].provider_id === 9 && row[1].config_options && row[1].evidence === plan.evidence), 'dispatch order and exact frozen input preserved');
  await batch.previewNext(); await batch.confirm();
  check(batch.items.every(row => row.state === 'success'), 'remaining group completes on separate explicit confirmation');
  await batch.confirm(); check(dispatches(log).length === 7, 'repeat confirmation cannot import success again');
}
{
  const { batch, transport, log } = setup();
  const original = transport.preview;
  transport.preview = async input => { if (input.remote_pid === 2) throw new BatchRequestError('Unsupported configuration', 409, 40914, true); return original(input); };
  await batch.previewNext();
  check(batch.items[1].state === 'rejected' && batch.ready.length === 2 && !batch.paused, 'pure product rule failure does not stop sibling prechecks');
  transport.submit = async input => { log.push(['submit', structuredClone(input)]); if (input.remote_pid === 1) throw new BatchRequestError('Invalid item', 422, 422, true); return { product_id: 103, outcome: 'created' }; };
  await batch.confirm(); check(batch.items[0].state === 'rejected' && batch.items[2].state === 'success', 'authoritative item rejection can continue submission');
}
for (const [status, code] of [[401, 401], [403, 403], [409, 40910], [409, 409], [503, 503]]) {
  const { batch, transport, log } = setup();
  transport.preview = async () => { throw new BatchRequestError('Connection failure', status, code, true); };
  await batch.previewNext();
  check(batch.paused && batch.items.every(row => row.state === 'pending') && !dispatches(log).length, `precheck ${status}/${code} pauses entire batch`);
}
for (const [status, code] of [[401, 401], [403, 403], [409, 40910], [409, 409]]) {
  const { batch, transport, log } = setup(); await batch.previewNext();
  transport.submit = async input => { log.push(['submit', input]); throw new BatchRequestError('Stop entire batch', status, code, true); };
  await batch.confirm();
  check(batch.paused && dispatches(log).length === 1 && batch.items[1].state === 'ready', `submission ${status}/${code} pauses all later items`);
}
for (const code of [40911, 40912]) {
  const { batch, transport, log } = setup(2); await batch.previewNext();
  const token = batch.items[0].payload.preview_token;
  transport.submit = async input => { log.push(['submit', structuredClone(input)]); if (input.remote_pid === 1) throw new BatchRequestError('Reconfirm', 409, code, true); return { product_id: 102, outcome: 'created' }; };
  await batch.confirm();
  check(batch.items[0].state === 'confirm' && batch.items[1].state === 'success', `expiry/drift ${code} waits for new manual confirmation`);
  await batch.previewNext(batch.items[0]);
  check(batch.items[0].state === 'ready' && dispatches(log).length === 2 && batch.items[0].payload.preview_token === token, 'repreview never automatically resubmits even if token content happens to match');
}
{
  const { batch, advance, log } = setup(2); await batch.previewNext(); advance(301); await batch.confirm();
  check(batch.items.every(row => row.state === 'confirm') && !dispatches(log).length, 'locally expired group does not dispatch or silently refresh');
}
{
  const { batch, transport, advance, log } = setup(2); await batch.previewNext(); transport.guard = async () => advance(301); await batch.confirm();
  check(!dispatches(log).length && batch.items.every(row => row.state === 'confirm'), 'slow identity check cannot race past expiry');
}
for (const failure of [new Error('Response lost'), new BatchRequestError('Gateway', 504, 504, false), new BatchRequestError('Internal', 500, 500, true), new BatchRequestError('Untrusted 409', 409, 40912, false)]) {
  const { batch, transport, log } = setup(); await batch.previewNext();
  transport.submit = async input => { log.push(['submit', structuredClone(input)]); throw failure; };
  await batch.confirm();
  check(batch.items[0].state === 'unknown' && batch.items[1].state === 'ready' && dispatches(log).length === 1 && batch.paused, `${failure.message} stops subsequent dispatch`);
  await batch.confirm(); await batch.previewNext(batch.items[0]);
  check(dispatches(log).length === 1 && !batch.clear() && !batch.start(9, selected(), plan), 'unknown blocks new confirmation, replacement token, clearing or another batch');
  const original = JSON.stringify(batch.items[0].payload);
  transport.submit = async input => { log.push(['submit', structuredClone(input)]); return { product_id: 101, outcome: 'replayed' }; };
  await batch.retry(batch.items[0]);
  check(batch.items[0].outcome === 'replayed' && JSON.stringify(dispatches(log)[1][1]) === original && dispatches(log).length === 2, 'explicit original retry preserves bytes and never resumes siblings');
}
{
  const { batch, transport, advance, log } = setup(); await batch.previewNext();
  transport.submit = async () => { throw new Error('Lost committed response'); }; await batch.confirm(); advance(301);
  await batch.retry(batch.items[0]); check(batch.items[0].state === 'unknown' && !dispatches(log).length, 'expired unknown cannot be replayed or replaced');
  transport.reconcile = async () => ({ product_id: 199, matched: false });
  await batch.reconcile(batch.items[0]); check(batch.items[0].state === 'unknown' && batch.items[0].productId === 199, 'mere existing binding never proves this operation succeeded');
  transport.reconcile = async () => ({ product_id: 101, matched: true });
  await batch.reconcile(batch.items[0]); check(batch.items[0].state === 'success' && batch.items[0].outcome === 'recovered' && batch.paused, 'matching persistent audit recovers expired unknown without submitting or auto-resuming');
}
{
  const { batch, transport } = setup(2); await batch.previewNext();
  transport.submit = async () => { throw new Error('Unknown first request'); }; await batch.confirm();
  transport.submit = async () => { throw new BatchRequestError('Expired', 409, 40911, true); }; await batch.retry(batch.items[0]);
  check(batch.items[0].state === 'unknown', 'definitive failure of retry cannot disprove original commit');
}
{
  const { batch, transport, advance, log } = setup(2); await batch.previewNext();
  transport.submit = async input => { log.push(['submit', input]); throw new Error('Unknown commit'); }; await batch.confirm();
  transport.guard = async () => advance(301); await batch.retry(batch.items[0]);
  check(dispatches(log).length === 1 && batch.items[0].state === 'unknown', 'slow retry guard cannot dispatch a now-expired original confirmation');
}
{
  const { batch, transport } = setup(2); await batch.previewNext();
  transport.submit = async () => ({ product_id: 9 }); await batch.confirm();
  check(batch.items[0].state === 'unknown', 'partial success envelope is not recorded as success');
}
{
  const { batch, transport, log } = setup(2); await batch.previewNext();
  transport.submit = async input => { log.push(['submit', input]); throw new BatchRequestError('Existing product', 409, 40913, true); };
  await batch.confirm(); check(batch.items.every(row => row.state === 'existing') && !batch.unknown, 'existing bindings are skipped rather than counted as this batch success');
}
for (const stage of ['preview', 'submit']) {
  const { batch, transport, log } = setup();
  if (stage === 'submit') await batch.previewNext();
  let finish; let started;
  const entered = new Promise(resolve => started = resolve);
  const wait = new Promise(resolve => finish = resolve);
  const original = transport[stage];
  transport[stage] = async input => { started(); await wait; return original(input); };
  const active = stage === 'submit' ? batch.confirm() : batch.previewNext(); await entered;
  check(batch.running, `single in-flight ownership during ${stage}`);
  await batch.confirm(); await batch.previewNext();
  batch.stop(); finish(); await active;
  check(batch.paused && (stage === 'submit' ? dispatches(log).length === 1 && batch.items[0].state === 'success' : batch.ready.length === 1), `stop during ${stage} allows response but stops subsequent dispatch`);
}
{
  const { batch, transport, log } = setup(2); await batch.previewNext();
  transport.guard = async () => { batch.dispose(); }; await batch.confirm();
  check(!dispatches(log).length, 'unmount while awaiting guard cannot dispatch');
}
{
  const { batch, transport, log } = setup(2); await batch.previewNext();
  transport.submit = async input => { log.push(['submit', input]); throw new Error('Unknown commit'); }; await batch.confirm();
  let readGuard = false;
  transport.guard = async readOnly => { if (!readOnly) throw new BatchRequestError('Provider disabled', 409, 40910, true); readGuard = true; };
  await batch.retry(batch.items[0]);
  check(batch.items[0].state === 'unknown' && dispatches(log).length === 1, 'disabled provider cannot retry unknown import');
  transport.reconcile = async () => ({ product_id: 101, matched: true }); await batch.reconcile(batch.items[0]);
  check(readGuard && batch.items[0].state === 'success' && dispatches(log).length === 1, 'read-only recovery is independent of supplier availability');
}
{
  const { batch } = setup();
  check(!batch.start(9, selected(21), plan) && !batch.start(9, [selected()[0], selected()[0]], plan), 'batch count and duplicate selection bounded');
  check(!batch.start(9, [{ ...selected()[0], imported: true }], plan) && !batch.start(9, [{ ...selected()[0], type: 'unsupported' }], plan), 'imported or unsupported candidates cannot be batched');
  check(!batch.start(9, selected(), { ...plan, evidence: 'tiny' }), 'batch still requires evidence');
}
// Category assignment belongs to the frozen per-item request, not the live selector.
{
  const { batch, transport, log } = setup(1);
  const assignedPlan = { ...plan, category_id: 42 };
  batch.start(9, selected(1), assignedPlan);
  assignedPlan.category_id = 99;
  const originalPreview = transport.preview;
  transport.preview = async input => ({ ...await originalPreview(input), category_id: input.category_id, category_path: 'Group A / Category C' });
  await batch.previewNext();
  check(batch.items[0].preview.category_path === 'Group A / Category C', 'prepared category path retained for confirmation summary');
  await batch.confirm();
  check(dispatches(log)[0][1].category_id === 42, 'changing selector plan cannot replace frozen category assignment');
}
{
  const source = readFileSync(new URL('../web/src/utils/categoryOptions.ts', import.meta.url), 'utf8');
  const { outputText } = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.ES2022 } });
  const { categoryOptionsFor } = await import('data:text/javascript;base64,' + Buffer.from(outputText).toString('base64'));
  const groups = [
    { id: 1, parent_id: null, name: 'Empty', status: 1, children: [] },
    { id: 2, parent_id: null, name: 'Only hidden children', status: 1, children: [{ id: 3, parent_id: 2, name: 'Hidden', status: 0 }] },
    { id: 4, parent_id: null, name: 'Available', status: 1, children: [{ id: 5, parent_id: 4, name: 'Visible', status: 1 }] },
    { id: 6, parent_id: null, name: 'Hidden group', status: 0, children: [{ id: 7, parent_id: 6, name: 'Old leaf', status: 1 }] },
  ];
  const options = categoryOptionsFor(groups);
  check(options.length === 1 && options[0].children[0].value === 5, 'new assignment excludes empty groups and hidden paths');
  const retained = categoryOptionsFor(groups, 3).find(g => g.value === 2);
  check(retained.children[0].disabled === true && retained.children[0].label.includes('隐藏'), 'hidden old leaf remains labeled and cannot be newly selected');
  check(categoryOptionsFor(groups, 7).find(g => g.value === 6).children[0].disabled, 'hidden old parent remains displayable without becoming assignable');
}
console.log(`Catalog batch checks: ${checks}`);
