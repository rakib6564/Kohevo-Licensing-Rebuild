// W-F8: the Motion panel's option labels go through the editor's messages, so a French editor shows French.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { ANIMATION_TYPES, EASINGS, INTERACTION_TRIGGERS, MOTION_PRESETS, motionLabel } from '../src/core/motion.mjs';

const fr = readFileSync(new URL('../../lang/fr.php', import.meta.url), 'utf8');
const boot = readFileSync(new URL('../../admin/builder.php', import.meta.url), 'utf8');

test('every Motion option has an English message, a French entry and a boot string', () => {
  const cases = [
    ...ANIMATION_TYPES.map((o) => ['anim', o, 'label']),
    ...INTERACTION_TRIGGERS.map((o) => ['trigger', o, 'label']),
    ...EASINGS.map((o) => ['ease', o, 'label']),
    ...MOTION_PRESETS.flatMap((o) => [['preset', o, 'label'], ['preset', o, 'hint']]),
  ];
  for (const [kind, option, part] of cases) {
    const english = part === 'hint' ? option.hint : option.label;
    assert.equal(motionLabel(kind, option, part), english, `${kind} ${option.value} ${part} reads the English message`);
  }
  const keys = [...new Set([...readFileSync(new URL('../src/core/messages.mjs', import.meta.url), 'utf8').matchAll(/^ {2}(motion_(?:anim|trigger|ease|preset)_[a-z0-9_]+):/gm)].map((m) => m[1]))];
  assert.equal(keys.length, cases.length, 'one message per option label and hint');
  for (const key of keys) {
    assert.ok(fr.includes(`'studio_ui_${key}' =>`), `French has ${key}`);
    assert.ok(boot.includes(`'${key}' => __('studio_ui_${key}'`), `the builder boot list sends ${key}`);
  }
});

test('the Motion panel shows translated labels, not the English constants', () => {
  const panel = readFileSync(new URL('../src/components/inspectors/MotionInspector.jsx', import.meta.url), 'utf8');
  assert.doesNotMatch(panel, /\{(o|preset)\.(label|hint)\}/);
  assert.match(panel, /motionLabel\('trigger', o\)/);
});
