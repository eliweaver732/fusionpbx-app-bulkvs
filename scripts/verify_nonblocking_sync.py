#!/usr/bin/env python3
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PAGES = [
    "bulkvs_numbers.php",
    "bulkvs_e911.php",
    "bulkvs_number_edit.php",
    "bulkvs_e911_edit.php",
]
JS = ROOT / "resources/javascript/bulkvs_sync.js"


def code(path):
    text = (ROOT / path).read_text()
    text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
    return re.sub(r"(?m)^\s*//.*$", "", text)


def checks():
    sync_php = code("bulkvs_sync.php")
    auth_at = sync_php.find("check_auth.php")
    release_at = sync_php.find("session_write_close()")
    after_release = sync_php[release_at:] if release_at >= 0 else ""
    cache_php = code("resources/classes/bulkvs_cache.php")
    js = JS.read_text() if JS.exists() else ""

    yield "bulkvs_sync.php calls session_write_close() after check_auth", 0 <= auth_at < release_at
    yield "bulkvs_sync.php writes no session state after release", release_at >= 0 and not re.search(
        r"message::add|token|session_start", after_release
    )
    yield "bulkvs_sync.php calls set_time_limit(0) after release", "set_time_limit(0)" in after_release
    yield "bulkvs_sync.php has no force_reset", "force_reset" not in sync_php
    for page in PAGES:
        calls = re.findall(r"->\s*(syncNumbers|syncE911|sync)\s*\(", code(page))
        yield f"{page} runs no catalog sync in-request (found {calls})", not calls
    yield "bulkvs_cache.php defines public function sync(", "public function sync(" in cache_php
    yield "bulkvs_cache.php has no syncNumbers/syncE911", not re.search(
        r"function\s+(syncNumbers|syncE911)\b", cache_php
    )
    yield "bulkvs_cache.php guards sync with pg_try_advisory_lock", "pg_try_advisory_lock" in cache_php
    yield "bulkvs_cache.php defines refreshAvailable and acknowledgeRefresh", (
        "public function refreshAvailable(" in cache_php
        and "public function acknowledgeRefresh(" in cache_php
    )
    yield "resources/javascript/bulkvs_sync.js exists", JS.exists()
    yield "bulkvs_sync.js aborts on pagehide only", "pagehide" in js and "visibilitychange" not in js
    for page in ("bulkvs_numbers.php", "bulkvs_e911.php"):
        yield f"{page} loads bulkvs_sync.js", "resources/javascript/bulkvs_sync.js" in code(page)


def main():
    failed = 0
    for name, ok in checks():
        print(("PASS " if ok else "FAIL ") + name)
        failed += not ok
    print(f"{failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
