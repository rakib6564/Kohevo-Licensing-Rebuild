// DeviceStyle — wraps a group of style controls so they can hold a different value per device.
//
// Desktop edits the block style as before. Tablet and mobile edit `block.responsive.<device>.style`, which the server
// renders as @media rules; empty fields there inherit from the larger device. The device switch is the same
// "current device" as the top bar (the editor viewport), so the canvas and the control never disagree.

import { useEditor } from '../EditorContext.jsx';
import { IconMonitor, IconTablet, IconSmartphone } from '../Icons.jsx';
import { IconButton } from './InspectorIcons.jsx';
import { DEVICE_KEYS, OVERRIDE_DEVICES, clearOverride, deviceView, hasOverride, writeOverride } from '../../core/responsiveStyle.mjs';
import { t } from '../../core/messages.mjs';

const DEVICE_ICONS = { desktop: IconMonitor, tablet: IconTablet, mobile: IconSmartphone };

/**
 * `scope`: { style, responsive, saveStyle(nextStyle), saveResponsive(nextResponsive) } for one block.
 * `paths`: the style paths this group of controls edits. `children({ style, onChange })` renders the controls.
 */
export function DeviceStyle({ scope, paths, label, children }) {
  const { viewport, setViewport } = useEditor();
  const device = DEVICE_KEYS.includes(viewport && viewport.key) ? viewport.key : 'desktop';
  const overridden = device !== 'desktop' && hasOverride(scope.responsive, device, paths);
  const view = deviceView(scope.style, scope.responsive, device, paths);
  const onChange = (next) => {
    if (device === 'desktop') scope.saveStyle(next);
    else scope.saveResponsive(writeOverride(scope.responsive, device, paths, next));
  };
  return (
    <div className="sbx-devstyle">
      <div className="sbx-devstyle__bar">
        <div className="sbx-responsive__devices" role="group" aria-label={`${label} — ${t('viewport')}`}>
          {DEVICE_KEYS.map((key) => {
            const Dev = DEVICE_ICONS[key];
            const set = OVERRIDE_DEVICES.includes(key) && hasOverride(scope.responsive, key, paths);
            return (
              <button
                key={key}
                type="button"
                className={`sbx-responsive__device${device === key ? ' is-active' : ''}${set ? ' is-set' : ''}`}
                aria-pressed={device === key}
                aria-label={t(key)}
                title={set ? `${t(key)} · ${t('device_style_overridden')}` : t(key)}
                onClick={() => setViewport && setViewport(key)}
              >
                <Dev size={14} />
              </button>
            );
          })}
        </div>
        {overridden && (
          <IconButton icon="reset" label={t('device_style_reset')} onClick={() => scope.saveResponsive(clearOverride(scope.responsive, device, paths))} />
        )}
      </div>
      {device !== 'desktop' && <p className="sbx-hint">{t('device_style_hint')}</p>}
      {children({ style: view, onChange })}
    </div>
  );
}
