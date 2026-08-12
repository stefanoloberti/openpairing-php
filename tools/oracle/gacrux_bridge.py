#!/usr/bin/env python3
"""Development-only canonical JSON to Gacrux Dutch adapter.

This file is original glue code. It imports an unmodified local Gacrux checkout
and does not form part of the PHP runtime.
"""

from __future__ import annotations

import argparse
import contextlib
from decimal import Decimal
import io
import json
from pathlib import Path
import sys
import traceback


GACRUX_COMMIT = "14a34a2c2f36509b110e4f25d6247f31fc4bf2f5"


def score_value(score: dict[str, int]) -> Decimal:
    return Decimal(score["units"]) / Decimal(score["scale"])


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--upstream", required=True)
    parser.add_argument("--input", required=True)
    args = parser.parse_args()

    upstream = Path(args.upstream).resolve()
    source = json.loads(Path(args.input).read_text(encoding="utf-8"))
    if source.get("schema") != "openpairing.tournament/1" or not isinstance(source.get("round"), int):
        print(json.dumps({"status": "unsupported_input", "pairs": [], "byePlayerId": None}))
        return 0

    sys.path.insert(0, str(upstream))
    from chessjson import chessjson  # type: ignore[import-not-found]
    from pairingdutch import pairing_dutch  # type: ignore[import-not-found]

    active = sorted(
        (player for player in source["players"] if player["active"]),
        key=lambda player: player["pairingNumber"],
    )
    canonical_by_cid: dict[int, int] = {}
    cid_by_canonical: dict[int, int] = {}
    chess = chessjson()
    tournament = chess.add_tournament(1, False, source["numberOfRounds"])
    tournament["topColor"] = "w" if source["configuration"]["topColour"] == "white" else "b"
    tournament["pairingSystem"] = ["dutch"]
    tournament["maxMeets"] = 1
    tournament["currentRound"] = source["round"]
    scoring = source["scoring"]
    tournament["scoreSystem"]["game"].update(
        {
            "W": score_value(scoring["win"]),
            "D": score_value(scoring["draw"]),
            "L": score_value(scoring.get("loss", {"units": 0, "scale": 1})),
            "Z": score_value(scoring.get("zeroPointBye", {"units": 0, "scale": 1})),
            "P": score_value(scoring.get("pairingAllocatedBye", scoring["win"])),
            "A": score_value(scoring.get("unknown", scoring["draw"])),
        }
    )

    for cid, player in enumerate(active, start=1):
        canonical_by_cid[cid] = player["id"]
        cid_by_canonical[player["id"]] = cid
        tournament["competitors"].append(
            {
                "cid": cid,
                "profileId": cid,
                "present": True,
                "gamePoints": score_value(player["score"]),
                "orgrank": player["pairingNumber"],
                "rank": player["pairingNumber"],
                "realRating": player["rating"],
                "rating": player["rating"],
                "random": 0,
            }
        )

    result_points = {
        "1": "W", "W": "W", "+": "W", "F": "W",
        "=": "D", "D": "D", "H": "D",
        "0": "L", "L": "L", "-": "Z", "Z": "Z", "U": "P",
    }
    players_by_id = {player["id"]: player for player in active}
    next_game_id = 1
    for player in active:
        for result in player.get("results", []):
            opponent_id = result.get("opponentId")
            if opponent_id is not None and player["id"] > opponent_id:
                continue
            code = str(result["result"]).upper()
            if code not in result_points:
                print(json.dumps({"status": "unsupported_input", "pairs": [], "byePlayerId": None}))
                return 0
            if opponent_id is None:
                game = {
                    "id": next_game_id,
                    "round": result["round"],
                    "white": cid_by_canonical[player["id"]],
                    "black": 0,
                    "played": code == "U",
                    "rated": False,
                    "wResult": result_points[code],
                }
            else:
                opponent = players_by_id[opponent_id]
                reciprocal = next(
                    item for item in opponent.get("results", [])
                    if item["round"] == result["round"] and item.get("opponentId") == player["id"]
                )
                if result.get("colour") == "black":
                    white_player, black_player = opponent, player
                    white_result, black_result = reciprocal, result
                else:
                    white_player, black_player = player, opponent
                    white_result, black_result = result, reciprocal
                game = {
                    "id": next_game_id,
                    "round": result["round"],
                    "white": cid_by_canonical[white_player["id"]],
                    "black": cid_by_canonical[black_player["id"]],
                    "played": bool(result["played"]),
                    "rated": bool(result["played"]),
                    "wResult": result_points[str(white_result["result"]).upper()],
                    "bResult": result_points[str(black_result["result"]).upper()],
                }
            tournament["gameList"].append(game)
            next_game_id += 1

    params = {"top_color": tournament["topColor"], "experimental": [], "verbose": 0}
    with contextlib.redirect_stdout(io.StringIO()):
        engine = pairing_dutch(tournament, source["round"], params)
        brackets = engine.compute_pairing(False, 0)

    if not brackets:
        print(json.dumps({"status": "no_pairing", "pairs": [], "byePlayerId": None}))
        return 0

    board_pairs: list[tuple[int, dict[str, int]]] = []
    bye: int | None = None
    for bracket in brackets:
        for pair in bracket.get("pairs", []):
            white = int(pair["w"])
            black = int(pair["b"])
            if white == 0 or black == 0:
                bye = canonical_by_cid[black if white == 0 else white]
                continue
            board_pairs.append(
                (
                    int(pair["board"]),
                    {
                    "whitePlayerId": canonical_by_cid[white],
                    "blackPlayerId": canonical_by_cid[black],
                    },
                )
            )

    pairs = [pair for _, pair in sorted(board_pairs, key=lambda item: item[0])]

    print(
        json.dumps(
            {
                "status": "valid",
                "pairs": pairs,
                "byePlayerId": bye,
                "upstreamCommit": GACRUX_COMMIT,
            },
            separators=(",", ":"),
        )
    )
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception:
        traceback.print_exc(file=sys.stderr)
        print(json.dumps({"status": "oracle_error", "pairs": [], "byePlayerId": None}))
        raise SystemExit(2)
