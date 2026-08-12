#!/usr/bin/env python3
"""Development-only classification of OpenPairing historical deviations."""

from __future__ import annotations

import argparse
from decimal import Decimal
import json
from pathlib import Path
import subprocess
import sys
import tempfile
from typing import Any


QUALITY_KEYS = [
    "QC6", "QC7", "QN8", "QC8", "QC9",
    "QC10", "QC11", "QC12", "QC13", "QC14", "QC15", "QC16", "QC17",
    "QC18", "QC19", "QC20", "QC21",
    "HE1", "HE2", "HO1", "HO2", "HO3", "HO4", "HO5", "IW",
]
ORACLE_PYTHON = sys.executable


def scoring(lines: list[str]) -> dict[str, Decimal]:
    values = {"W": Decimal("1.0"), "D": Decimal("0.5"), "L": Decimal("0.0"),
              "A": Decimal("0.0"), "P": Decimal("1.0"), "X": Decimal("0.5")}
    for line in lines:
        if line.startswith("162"):
            for symbol, offset in {"W": 5, "D": 14, "L": 23, "A": 32, "P": 41, "X": 50}.items():
                values[symbol] = Decimal(line[offset + 1:offset + 5].strip())
    return values


def result_points(code: str, values: dict[str, Decimal]) -> Decimal:
    return {
        "1": values["W"], "W": values["W"], "+": values["W"], "F": values["W"],
        "=": values["D"], "D": values["D"], "H": values["D"],
        "0": values["L"], "L": values["L"], "-": values["A"], "Z": values["A"],
        "U": values["P"],
    }.get(code.upper(), values["X"])


def candidate_trf(source: Path, round_number: int, candidate: dict[str, Any]) -> str:
    lines = source.read_text(encoding="latin-1").splitlines()
    values = scoring(lines)
    assignments: dict[int, tuple[int, str]] = {}
    for pair in candidate["pairs"]:
        white, black = int(pair["white"]), int(pair["black"])
        assignments[white] = (black, "w")
        assignments[black] = (white, "b")
    bye = candidate.get("bye")

    output: list[str] = []
    for line in lines:
        if not line.startswith("001") or len(line) < 89:
            output.append(line)
            continue
        player = int(line[4:8].strip())
        blocks = [line[89 + index * 10:99 + index * 10] for index in range(round_number)]
        while len(blocks) < round_number:
            blocks.append(" " * 10)
        original = blocks[round_number - 1]
        if player in assignments:
            opponent, colour = assignments[player]
            blocks[round_number - 1] = f"  {opponent:4d} {colour} ="
        elif bye is not None and player == int(bye):
            blocks[round_number - 1] = "  0000 - U"
        elif len(original) >= 10 and (int(original[2:6].strip() or "0") > 0 or original[9].upper() == "U"):
            raise ValueError(f"candidate omitted active player {player}")

        score = sum((result_points(block[9], values) for block in blocks if len(block) >= 10 and block.strip()), Decimal("0"))
        score_text = f"{score:.1f}"
        if len(score_text) > 4:
            raise ValueError("score does not fit TRF field")
        prefix = line[:80] + f"{score_text:>4}" + line[84:89]
        output.append(prefix + "".join(blocks))
    return "\r\n".join(output) + "\r\n"


def canonical(value: Any) -> Any:
    if isinstance(value, list):
        return [canonical(item) for item in value]
    if isinstance(value, dict):
        return {key: canonical(value[key]) for key in sorted(value)}
    return value


def quality_by_level(brackets: list[dict[str, Any]]) -> list[tuple[int, dict[str, Any]]]:
    result = []
    for bracket in brackets:
        if bracket is None:
            continue
        quality = bracket.get("quality", {})
        result.append((int(bracket["scorelevel"]), {key: canonical(quality.get(key)) for key in QUALITY_KEYS}))
    return result


def quality_comparison(candidate: list[dict[str, Any]], best: list[dict[str, Any]]) -> tuple[bool, dict[str, Any] | None]:
    left, right = quality_by_level(candidate), quality_by_level(best)
    if [level for level, _ in left] != [level for level, _ in right]:
        return False, {"criterion": "score-brackets", "candidate": [item[0] for item in left], "best": [item[0] for item in right]}
    for index, ((level, candidate_quality), (_, best_quality)) in enumerate(zip(left, right)):
        for key in QUALITY_KEYS:
            if candidate_quality[key] != best_quality[key]:
                return False, {
                    "bracketIndex": index,
                    "scorelevel": level,
                    "criterion": key,
                    "candidate": candidate_quality[key],
                    "best": best_quality[key],
                }
    return True, None


def classify(upstream: Path, source: Path, round_number: int, candidate: dict[str, Any]) -> dict[str, Any]:
    with tempfile.TemporaryDirectory(prefix="openpairing-gacrux-quality-") as temporary:
        temporary_path = Path(temporary)
        trf_path = temporary_path / "candidate.trf"
        output_path = temporary_path / "result.json"
        trf_path.write_text(candidate_trf(source, round_number, candidate), encoding="latin-1", newline="")
        command = [
            ORACLE_PYTHON, str(upstream / "pairingchecker.py"),
            "-i", str(trf_path), "-f", "TRF", "-c", "-a", "-p",
            "-F", "JSON", "-o", str(output_path),
        ]
        process = subprocess.run(command, capture_output=True, text=True, timeout=30, env={"PYTHONHASHSEED": "0"})
        if not output_path.is_file():
            return {"category": "infrastructure-error", "exitCode": process.returncode, "stderr": process.stderr[-2000:]}
        envelope = json.loads(output_path.read_text(encoding="utf-8"))
        rounds = envelope.get("pairingResult", {}).get("roundpairing", [])
        result = next((item for item in rounds if int(item.get("round", 0)) == round_number), None)
        if result is None:
            return {"category": "infrastructure-error", "message": "checker omitted requested round"}
        same_quality, first_difference = quality_comparison(result.get("analysis", []), result.get("pairing", []))
        checker_exact = bool(result.get("check", False))
        if checker_exact:
            category = "checker-exact"
        elif same_quality:
            category = "same-full-quality-but-nonexact"
        else:
            category = "inferior-to-checker-optimum"
        return {
            "category": category,
            "rules": envelope.get("pairingResult", {}).get("rules"),
            "checkerExact": checker_exact,
            "firstDifference": first_difference,
            "candidateBrackets": result.get("analysis", []),
            "bestBrackets": result.get("pairing", []),
            "candidatePairs": result.get("current", []),
            "bestPairs": result.get("pairs", []),
        }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--upstream", required=True)
    parser.add_argument("--report", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--root", help="Override the corpus root recorded by a report produced on another host.")
    parser.add_argument(
        "--sample-set", choices=["deviations", "preliminary-bye", "shadow-oracle", "shadow-regressions"], default="deviations",
        help="Classify production deviations, preliminary PAB counterfactuals, mandatory shadow cases or shadow regressions.",
    )
    args = parser.parse_args()
    upstream = Path(args.upstream).resolve()
    oracle_python = upstream / "venv" / "bin" / "python"
    global ORACLE_PYTHON
    ORACLE_PYTHON = str(oracle_python) if oracle_python.is_file() else sys.executable
    report_path = Path(args.report).resolve()
    raw_report = report_path.read_bytes()
    encoding = "utf-16" if raw_report.startswith((b"\xff\xfe", b"\xfe\xff")) else "utf-8-sig"
    report = json.loads(raw_report.decode(encoding))
    root = Path(args.root).resolve() if args.root else Path(report["root"])
    results = []
    counts: dict[str, int] = {}
    sample_key = {
        "deviations": "samples",
        "preliminary-bye": "preliminarySamples",
        "shadow-oracle": "shadowSamples",
        "shadow-regressions": "shadowSamples",
    }[args.sample_set]
    expected_codes = {
        "deviations": {"pairing-deviation"},
        "preliminary-bye": {"preliminary.bye_same_score"},
        "shadow-oracle": {"shadow.same-deviation", "shadow.divergent-deviation"},
        "shadow-regressions": {"shadow.candidate-regression"},
    }[args.sample_set]
    for sample in report.get(sample_key, []):
        if sample.get("code") not in expected_codes or not isinstance(sample.get("candidate"), dict):
            continue
        try:
            result = classify(upstream, root / sample["file"], int(sample["round"]), sample["candidate"])
        except Exception as error:
            result = {"category": "infrastructure-error", "message": f"{type(error).__name__}: {error}"}
        result = {"file": sample["file"], "round": sample["round"], **result}
        results.append(result)
        counts[result["category"]] = counts.get(result["category"], 0) + 1
    output = {
        "schema": "openpairing.gacrux-quality-classification/2",
        "sampleSet": args.sample_set,
        "sourceReport": str(report_path),
        "sourceRuntimeIdentity": report.get("runtimeIdentity"),
        "sourceCorpusIdentity": report.get("corpusIdentity"),
        "sourceHistoricalFloatSemantics": report.get("historicalFloatSemantics"),
        "upstream": str(upstream),
        "counts": counts,
        "results": results,
    }
    Path(args.output).write_text(json.dumps(output, indent=2), encoding="utf-8")
    print(json.dumps({"counts": counts, "classified": len(results)}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
