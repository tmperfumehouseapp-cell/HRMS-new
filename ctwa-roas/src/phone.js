/** Last 10 digits of any phone format (+91 98xxx, 098xxx, 9198xxx ...). null if < 10 digits. */
export function last10(raw) {
  const d = String(raw ?? '').replace(/\D/g, '');
  return d.length >= 10 ? d.slice(-10) : null;
}
