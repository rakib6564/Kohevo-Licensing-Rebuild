// Check — a checkbox with its text, as one clickable row. Extra props (checked, onChange, disabled, name…) go to the input.

export function Check({ label, className = '', ...input }) {
  return (
    <label className={`sbx-field sbx-field--check${className ? ` ${className}` : ''}`}>
      <input type="checkbox" {...input} />
      <span>{label}</span>
    </label>
  );
}
