// Plain-English descriptions for the built-in Gemini voices. Their names are
// Google's star names (Achird, Kore...), which mean nothing to a new user, so
// pickers lead with these and show the name small.
export const VOICE_DESCRIPTIONS = {
  Kore: "Firm, clear — a confident default narrator.",
  Charon: "Informative and steady — great for explainers.",
  Puck: "Upbeat and punchy — reads young and energetic.",
  Zephyr: "Bright and lively.",
  Leda: "Youthful and bright — reads like a young woman / teen.",
  Fenrir: "Excitable, high-energy — good for hype.",
  Aoede: "Breezy and easy — relaxed and friendly.",
  Sulafat: "Warm and gentle — soothing.",
  Gacrux: "Mature and warm — reads older.",
  Algenib: "Gravelly and textured — reads as an older man.",
  Vindemiatrix: "Gentle and soft — a kind, mature woman.",
  Achernar: "Soft and calm.",
  Orus: "Firm and grounded.",
  Achird: "Friendly and approachable.",
  Enceladus: "Breathy and intimate.",
  Schedar: "Even and measured.",
};

// Gender first (Male/Female), then the description; cloned/neutral skip gender.
export function voiceDescription(p) {
  const blurb = VOICE_DESCRIPTIONS[p.provider_voice_key] || p.accent || "";
  const g = p.gender_label && !["Neutral", "Cloned"].includes(p.gender_label) ? p.gender_label : "";
  return [g, blurb].filter(Boolean).join(" · ") || "Voice";
}

// Short headline for a picker row: the first clause of the description.
export function voiceHeadline(p) {
  const blurb = VOICE_DESCRIPTIONS[p.provider_voice_key] || p.accent || "";
  return (blurb.split(" — ")[0] || p.name || "Voice").replace(/\.$/, "");
}
