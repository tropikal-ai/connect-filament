#!/usr/bin/env bash
set -euo pipefail

[[ "${QUALITY_RESULT:-}" == success ]] || exit 1
[[ "${INTENT_RESULT:-}" == success ]] || exit 1
case "${HAS_INTENTS:-}" in
  true) [[ "${PAYLOAD_RESULT:-}" == success ]] || exit 1 ;;
  false) [[ "${PAYLOAD_RESULT:-}" == skipped ]] || exit 1 ;;
  *) exit 1 ;;
esac
