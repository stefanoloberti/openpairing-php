#!/usr/bin/env python3
"""Cross shadow freeze decisions with Gacrux's first quality difference."""

from __future__ import annotations

import argparse
from collections import Counter
import json
from pathlib import Path
from typing import Any


def read_json(path: Path) -> dict[str, Any]:
    raw = path.read_bytes()
    encoding = "utf-16" if raw.startswith((b"\xff\xfe", b"\xfe\xff")) else "utf-8-sig"
    return json.loads(raw.decode(encoding))


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--report", required=True)
    parser.add_argument("--classification", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()

    report = read_json(Path(args.report))
    classification = read_json(Path(args.classification))
    samples = {
        (sample["file"], int(sample["round"])): sample
        for sample in report.get("shadowSamples", [])
        if sample.get("code") == "shadow.candidate-regression"
    }
    rows = []
    for result in classification.get("results", []):
        key = (result["file"], int(result["round"]))
        sample = samples[key]
        freeze = sample.get("firstNonReferenceFreeze")
        difference = result.get("firstDifference")
        freeze_index = freeze.get("bracketIndex") if freeze else None
        difference_index = difference.get("bracketIndex") if difference else None
        if difference_index is None:
            relation = "unmeasurable-no-quality-difference"
        elif freeze_index is None:
            relation = "missing-freeze"
        elif freeze_index < difference_index:
            relation = "early-freeze"
        elif freeze_index == difference_index:
            relation = "same-bracket"
        else:
            relation = "freeze-after-difference"
        rows.append({**result, "firstFreezeBracketIndex": freeze_index, "relation": relation})

    counts = Counter(row["relation"] for row in rows)
    criteria = Counter((row.get("firstDifference") or {}).get("criterion", "none") for row in rows)
    output = {
        "schema": "openpairing.shadow-early-freeze-audit/1",
        "sourceReportRuntimeIdentity": report.get("runtimeIdentity"),
        "sourceCorpusIdentity": report.get("corpusIdentity"),
        "sourceClassification": str(Path(args.classification).resolve()),
        "counts": dict(counts),
        "firstDifferenceCriteria": dict(criteria),
        "rows": rows,
    }
    Path(args.output).write_text(json.dumps(output, indent=2), encoding="utf-8")
    print(json.dumps({"counts": counts, "criteria": criteria, "audited": len(rows)}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
