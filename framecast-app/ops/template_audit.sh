#!/usr/bin/env bash
# Template render audit on the local stack (docs/product/template-audit.md).
#   ops/template_audit.sh <template id>    audit the template's code and record the result (free if it passed)
#   ops/template_audit.sh <revision uuid>  audit a version before it becomes a template; nothing is recorded
# Free: no AI calls. Rebuild the sandbox image after agent/ changes (docker compose -f hyperframes-worker/compose.local.yml build smoke).
set -euo pipefail
cd "$(dirname "$0")/.."
ID="${1:?Usage: ops/template_audit.sh <template id | revision uuid>}"
[[ "$ID" =~ ^[a-zA-Z0-9_-]{1,80}$ ]] || { echo "Bad id"; exit 1; }
TINKER() { docker compose exec -T api php artisan tinker --execute "$1" 2>/dev/null | tail -1; }

if [[ "$ID" =~ ^[0-9a-f]{8}-[0-9a-f]{4}- ]]; then REV="$ID"; TEMPLATE=""
else
  REV=$(TINKER "echo DB::table('create_templates')->where('id','$ID')->value('revision_id');")
  TEMPLATE="$ID"
  [ -n "$REV" ] || { echo "No template $ID with its own code"; exit 1; }
fi

STAGE=/tmp/taudit/$ID OUT=hyperframes-worker/artifacts/audit/$ID
docker compose exec -T api sh -c "rm -rf $STAGE && mkdir -p /tmp/taudit"
docker compose exec -T api php artisan create:version export "$REV" --dir="$STAGE" >/dev/null
rm -rf "$OUT" && mkdir -p "$(dirname "$OUT")"
docker compose cp "api:$STAGE" "$OUT" >/dev/null 2>&1

# Brand fonts are fetched here: the sandbox has no network, and a missing font changes how long text fits.
node --input-type=module -e "
import {readFile,mkdir} from 'node:fs/promises';
import {familiesIn,googleFamilies,fetchBrandFonts} from './hyperframes-worker/agent/brand-fonts.mjs';
const m=JSON.parse(await readFile('$OUT/manifest.json','utf8'));
try{const f=familiesIn(m.bundle,await googleFamilies());if(f.length){await mkdir('$OUT/fonts',{recursive:true});await fetchBrandFonts(f,'$OUT/fonts');}}catch(e){console.error('fonts: '+e.message);}
"

docker compose -f hyperframes-worker/compose.local.yml run --rm smoke node agent/template-audit.mjs "$ID"
python3 -c "
import json;r=json.load(open('$OUT/result.json'))
print('PASSED' if r['passed'] else 'FAILED', '$ID')
for k,l in (('leftovers','Written into the code'),('unseen','Fields never on screen'),('overflow','Long text runs out of its box')):
  if r[k]: print('  '+l+': '+' | '.join((x['text']+' @'+str(x['at'])+'s') if isinstance(x,dict) else x for x in r[k][:12]))
"

if [ -n "$TEMPLATE" ]; then
  docker compose cp "$OUT/result.json" "api:$STAGE/result.json" >/dev/null 2>&1
  docker compose exec -T -u root api chmod a+r "$STAGE/result.json"
  docker compose exec -T api php artisan create:template-audit "$TEMPLATE" "$STAGE/result.json"
fi
