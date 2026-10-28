// DeviceProp — a block prop that can hold its own value on tablet and mobile (DeviceProps.php).
//
// Desktop shows the field's own control, as before. Tablet and mobile show its choices with "Auto" first: Auto leaves the
// device to the renderer's default (a Gallery's columns drop on a phone), a choice is stored under
// `block.responsive.<device>.props.<path>`. The device switch is the editor viewport, as for per-device styles.

import { useEditor } from '../EditorContext.jsx';
import { IconMonitor, IconTablet, IconSmartphone } from '../Icons.jsx';
import { IconButton } from '../inspectors/InspectorIcons.jsx';
import { Field, Pills } from '../ui/index.js';
import { DEVICE_KEYS } from '../../core/responsiveStyle.mjs';
import { deviceChoices, readDeviceProp, writeDeviceProp } from '../../core/deviceProps.mjs';
import { optionLabel } from '../../core/optionLabels.mjs';
import { t } from '../../core/messages.mjs';

const DEVICE_ICONS = { desktop: IconMonitor, tablet: IconTablet, mobile: IconSmartphone };

/** `device`: { responsive, saveResponsive(next) } for the block; `path`: the prop's path; `children`: the desktop control. */
export function DeviceProp({ field, path, device, children }) {
  const { viewport, setViewport } = useEditor();
  const current = DEVICE_KEYS.includes(viewport && viewport.key) ? viewport.key : 'desktop';
  const value = readDeviceProp(device.responsive, current, path);
  const set = (v) => device.saveResponsive(writeDeviceProp(device.responsive, current, path, v));
  const choices = deviceChoices(field);
  return (
    <div className="sbx-devprop" data-device-prop={path}>
      <div className="sbx-devstyle__bar">
        <div className="sbx-responsive__devices" role="group" aria-label={`${field.label} — ${t('viewport')}`}>
          {DEVICE_KEYS.map((key) => {
            const Dev = DEVICE_ICONS[key];
            const isSet = key !== 'desktop' && readDeviceProp(device.responsive, key, path) !== undefined;
            return (
              <button
                key={key}
                type="button"
                className={`sbx-responsive__device${current === key ? ' is-active' : ''}${isSet ? ' is-set' : ''}`}
                aria-pressed={current === key}
                aria-label={t(key)}
                title={isSet ? `${t(key)} · ${t('device_style_overridden')}` : t(key)}
                onClick={() => setViewport && setViewport(key)}
              >
                <Dev size={14} />
              </button>
            );
          })}
        </div>
        {value !== undefined && <IconButton icon="reset" label={t('device_prop_reset')} onClick={() => set(undefined)} />}
      </div>
      {current === 'desktop' ? children : (
        <Field label={`${field.label} · ${t(current)}`}>
          <Pills
            label={`${field.label} · ${t(current)}`}
            value={value === undefined ? '' : String(value)}
            options={[{ value: '', label: t('device_prop_auto') }, ...choices.map((c) => ({ value: String(c), label: optionLabel(String(c)) }))]}
            onChange={(v) => set(v === '' ? undefined : (field.type === 'enum' ? v : Number(v)))}
          />
          <p className="sbx-hint">{t('device_prop_hint')}</p>
        </Field>
      )}
    </div>
  );
}
