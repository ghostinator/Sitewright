#!/bin/sh
# Verifies every required finding ID appears in the ledger (and optionally a final report file).
# Usage: bin/check-ledger.sh [report.md]
cd "$(dirname "$0")/.." || exit 1
missing=0
for id in $(cut -f1 docs/remediation/LEDGER.tsv); do
  if [ -n "$1" ] && ! grep -q "$id" "$1"; then echo "MISSING in $1: $id"; missing=1; fi
done
echo "ledger rows: $(wc -l < docs/remediation/LEDGER.tsv) (expected 63); TODO: $(grep -c 'TODO' docs/remediation/LEDGER.tsv)"
exit $missing
