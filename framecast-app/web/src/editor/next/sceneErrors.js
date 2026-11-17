// A scene's failure in plain words (owner, 2026-11-16: one place for an error, never the provider's raw text).
// The raw text is kept for staff behind "Details". Our own server messages are already written for people and pass
// through; anything that reads like a stack or a provider dump gets a plain sentence for its kind.

const RULES = [
  [/connection ?refused|HTTPConnectionPool|Max retries exceeded|ConnectionError|Failed to establish|ECONNRESET|getaddrinfo|could not (fetch|download)/i,
    "The AI service couldn't reach this scene's picture."],
  [/timed? ?out|timeout|did not finish|took too long/i, "The AI service took too long and stopped."],
  [/moderat|flagged|safety|sensitive|nsfw|E006|content polic|refus/i,
    "The AI provider refused this request: it flagged the picture or the words. Try another picture or wording."],
  [/insufficient credits|not enough credits|out of credits|402/i, "There weren't enough credits to finish it."],
  [/no source image|needs a still image|only a video|Generate a still image/i, "This scene needs a picture to start from."],
  [/rate limit|429|too many requests/i, "The AI service was busy. Try again in a minute."],
];

const TECHNICAL = /https?:\/\/|Errno|Exception|Traceback|object at 0x|\{.*\}|\bat line\b|SQLSTATE|stack|::|\(\s*Caused by/i;

/** { title, text, raw } for a scene error; kind is "video" or "picture". */
export function friendlyError(raw, kind = "video") {
  const text = String(raw || "").trim();
  if (!text) return null;
  const title = kind === "video" ? "This scene's video didn't finish" : "This scene's picture didn't finish";
  for (const [re, plain] of RULES) if (re.test(text)) return { title, text: plain, raw: text };
  if (TECHNICAL.test(text) || text.length > 220) {
    return { title, text: `Something went wrong making the ${kind}. Try again, or pick another picture or model.`, raw: text };
  }
  return { title, text, raw: text };
}
