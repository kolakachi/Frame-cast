// A generative one-shot cannot preserve selected timeline assets.
export function canGenerateOneShot(plan) {
  const segments = plan?.segments ?? [];
  return !!plan && plan.format !== 'text_led' &&
    segments.reduce((total, s) => total + Math.max(1, Number(s.seconds || 0)), 0) <= 30 &&
    !segments.some((s) => s.kind === 'b_roll' && s.source !== 'generate') &&
    segments.some((s) => (s.script_text || '').trim() !== '');
}
