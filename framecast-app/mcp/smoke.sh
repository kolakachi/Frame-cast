#!/bin/sh
# Smoke test for the MCP sidecar over raw JSON-RPC. Nothing is created:
# quoting is free. Usage: ./smoke.sh http://localhost:3001 wyv_live_…
B="$1"; K="$2"
H='-H Content-Type:application/json -H Accept:application/json,text/event-stream'
rpc() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $K" $H -d "$1"; echo; }
echo "== bad token =="; curl -s -o /dev/null -w "%{http_code}\n" -X POST "$B/mcp" -H "Authorization: Bearer wyv_live_nope" $H -d '{"jsonrpc":"2.0","id":0,"method":"tools/list"}'
echo "== no token =="; curl -s -o /dev/null -w "%{http_code}\n" -X POST "$B/mcp" $H -d '{"jsonrpc":"2.0","id":0,"method":"tools/list"}'
echo "== initialize =="; rpc '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"smoke","version":"0"}}}' | cut -c1-300
echo "== get_capabilities =="; rpc '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"get_capabilities","arguments":{}}}' | cut -c1-400
echo "== estimate_video =="; rpc '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"estimate_video","arguments":{"source_type":"prompt","content":"Three reasons a standing desk pays for itself within a month, told as a story.","visual_mode":"stock","duration_seconds":30}}}' | cut -c1-600
echo "== bad args =="; rpc '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"estimate_video","arguments":{"source_type":"prompt","content":"short","visual_mode":"stock"}}}' | cut -c1-300
echo "== get_video_result on missing =="; rpc '{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"get_video_result","arguments":{"video_id":999999999}}}' | cut -c1-300
