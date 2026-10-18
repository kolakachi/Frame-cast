// Builds docs/product/chatgpt-app-submission.json for OpenAI's app review from what the server really declares:
// the tool list and annotations come from a tools/list scan (scripts/list-tools.mjs), each tool's justifications
// from submission-justifications.json. Refuses when a tool is missing a justification, a justification has no tool,
// or a required hint is not an explicit boolean (OpenAI's rejection, 2026-10: "annotations ... not null").
// node scripts/submission.mjs <tools.json from list-tools> <existing submission.json> > new submission.json
import { readFile } from 'node:fs/promises'
const [scanPath, basePath] = process.argv.slice(2)
if (!scanPath || !basePath) throw new Error('Usage: node scripts/submission.mjs <tools.json> <submission.json>')
const scan = JSON.parse(await readFile(scanPath, 'utf8'))
const base = JSON.parse(await readFile(basePath, 'utf8'))
const reasons = JSON.parse(await readFile(new URL('../submission-justifications.json', import.meta.url), 'utf8'))
const HINTS = ['readOnlyHint', 'openWorldHint', 'destructiveHint']
const KEYS = ['read_only_justification', 'open_world_justification', 'destructive_justification']
const problems = []
const tools = {}
for (const t of scan) {
  for (const h of HINTS) if (typeof t.annotations?.[h] !== 'boolean') problems.push(`${t.name}: ${h} is not true or false`)
  const why = reasons[t.name]
  if (!why) { problems.push(`${t.name}: no justification`); continue }
  for (const k of KEYS) if (typeof why[k] !== 'string' || !why[k].trim()) problems.push(`${t.name}: ${k} is empty`)
  tools[t.name] = { annotations: Object.fromEntries(HINTS.map(h => [h, t.annotations?.[h]])), justifications: Object.fromEntries(KEYS.map(k => [k, why[k]])) }
}
for (const name of Object.keys(reasons)) if (!scan.some(t => t.name === name)) problems.push(`${name}: justification for a tool the server does not list`)
if (problems.length) { console.error(problems.join('\n')); process.exit(1) }
process.stdout.write(JSON.stringify({ ...base, tools }, null, 2) + '\n')
