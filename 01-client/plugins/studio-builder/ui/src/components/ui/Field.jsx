// Field — a labelled control row. One place for the label, hint and error layout so every panel lines up.
//
//   label     the visible label
//   htmlFor   id of the control the label names (omit for a group: the label is then plain text, and the control
//             should carry its own aria-label)
//   variant   'row' (default) | 'choice' (label left, control right) | 'stack' (label over a wide control)
//   hint, error   optional lines under the control

export function Field({ label, htmlFor, variant = 'row', hint, error, className = '', children, ...rest }) {
  const mod = variant === 'row' ? '' : ` sbx-field--${variant}`;
  return (
    <div className={`sbx-field${mod}${className ? ` ${className}` : ''}`} {...rest}>
      {label != null && (htmlFor
        ? <label className="sbx-field__label" htmlFor={htmlFor}>{label}</label>
        : <span className="sbx-field__label">{label}</span>)}
      {children}
      {hint ? <p className="sbx-hint">{hint}</p> : null}
      {error ? <p className="sbx-field__error" role="alert">{error}</p> : null}
    </div>
  );
}
