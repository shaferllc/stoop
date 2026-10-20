#!/usr/bin/env bash
# File a Stoop dispatch from your terminal.
#
#   curl -fsSL {{ route('terminal') }} | STOOP_TOKEN=your-token bash -s -- "Red Line is delayed at Dupont" --line red --stop A03
#
# Create a token in Settings → Terminal & API. You can also save it in ~/.stoop-token.
set -euo pipefail

token="${STOOP_TOKEN:-}"
if [ -z "$token" ] && [ -f "$HOME/.stoop-token" ]; then token="$(tr -d '[:space:]' < "$HOME/.stoop-token")"; fi
if [ -z "$token" ]; then echo "Set STOOP_TOKEN or save a token in ~/.stoop-token (Settings → Terminal & API)." >&2; exit 1; fi

body="${1:-}"
if [ -z "$body" ]; then echo "Usage: … bash -s -- \"what's happening\" [--line red] [--stop A03] [--confirm]" >&2; exit 1; fi
shift

line="red"; stop=""; confirm="false"
while [ $# -gt 0 ]; do
  case "$1" in
    --line) line="$2"; shift 2 ;;
    --stop) stop="$2"; shift 2 ;;
    --confirm) confirm="true"; shift ;;
    *) echo "Unknown option: $1" >&2; exit 1 ;;
  esac
done

json_escape() { printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e ':a;N;$!ba;s/\n/\\n/g'; }
payload="{\"body\":\"$(json_escape "$body")\",\"line\":\"$(json_escape "$line")\",\"stop\":\"$(json_escape "$stop")\",\"confirm\":$confirm}"

curl -fsS -X POST "{{ url('/api/dispatches') }}" \
  -H "Authorization: Bearer $token" -H "Accept: application/json" -H "Content-Type: application/json" \
  --data "$payload" -o /dev/null -w "Filed. (%{http_code})\n" || {
    echo "Couldn't file that. Check your token, or run again with --confirm if Stoop asked you to reconsider." >&2; exit 1; }
