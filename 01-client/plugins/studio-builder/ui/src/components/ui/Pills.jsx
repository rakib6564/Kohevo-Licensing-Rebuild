// Pills — the one segmented control: a row of exclusive choices drawn as a single capsule.
//
//   options   [{ value, label, hasValue?, ...buttonProps }]   hasValue draws a dot ("this state has overrides")
//   value     the active option's value
//   onChange  (value) => void   — it fires for the active option too, so a caller may treat that as "clear"
//   role      'group' (default) | 'radiogroup' | 'tablist'
//   size      'md' (default: a control) | 'sm' (a quiet switcher between views, tinted rather than solid when active)
//
// Buttons carry aria-pressed (group, radiogroup) or role="tab" + aria-selected (tablist). The markup and
// class names (`sbx-segmented-*`) are what the e2e specs and the stylesheet already know.

export function Pills({ options, value, onChange, label, role = 'group', size = 'md', className = '', ...rest }) {
  const tabs = role === 'tablist';
  return (
    <div className={`sbx-segmented-pills${size === 'sm' ? ' sbx-segmented-pills--sm' : ''}${className ? ` ${className}` : ''}`} role={role} aria-label={label} {...rest}>
      {options.map(({ value: v, label: text, hasValue = false, ...buttonProps }) => {
        const active = value === v;
        return (
          <button
            key={String(v)}
            type="button"
            className={`sbx-segmented-pill${active ? ' is-active' : ''}${hasValue ? ' has-value' : ''}`}
            {...(tabs ? { role: 'tab', 'aria-selected': active } : { 'aria-pressed': active })}
            onClick={() => onChange(v)}
            {...buttonProps}
          >
            {text}
          </button>
        );
      })}
    </div>
  );
}
