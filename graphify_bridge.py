"""Graphify Knowledge Graph Bridge for Agent Host & VPS.

Automatically queries Graphify (`graphify-out/graph.json` & `GRAPH_REPORT.md`)
on every agent prompt and LLM Council deliberation to provide instant file/line
locations and structural relationships, saving massive token overhead.
"""

import os
import json
import subprocess
from typing import Dict, Any, Optional

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
VPS_GRAPH_DIR = "/root/graphify-out"
PROJECT_GRAPH_DIR = os.path.join(BASE_DIR, "graphify-out")

GRAPHIFY_DIST = "/root/snap/antigravity-cli/common/local/lib/python3.14/dist-packages"
GRAPHIFY_BIN_CANDIDATES = [
    "/root/snap/antigravity-cli/common/local/bin/graphify",
    "/root/.local/bin/graphify",
    "/usr/local/bin/graphify",
    "/usr/bin/graphify",
]


def get_graphify_bin() -> str:
    for p in GRAPHIFY_BIN_CANDIDATES:
        if os.path.exists(p):
            return p
    return "graphify"


def _get_env() -> dict:
    env = os.environ.copy()
    if os.path.exists(GRAPHIFY_DIST):
        cur = env.get("PYTHONPATH", "")
        env["PYTHONPATH"] = f"{GRAPHIFY_DIST}:{cur}" if cur else GRAPHIFY_DIST
    return env


def resolve_graph_json(workdir: Optional[str] = None) -> str:
    """Finds the best matching graph.json for the given working directory."""
    candidates = []
    if workdir:
        candidates.append(os.path.join(workdir, "graphify-out", "graph.json"))
    candidates.append(os.path.join(PROJECT_GRAPH_DIR, "graph.json"))
    candidates.append(os.path.join(VPS_GRAPH_DIR, "graph.json"))
    for c in candidates:
        if os.path.exists(c):
            return c
    return os.path.join(PROJECT_GRAPH_DIR, "graph.json")


def query_graphify(question: str, workdir: Optional[str] = None, budget: int = 550) -> str:
    """Runs `graphify query` against the project's and/or VPS's graph.json to retrieve scoped AST nodes."""
    if not question or not question.strip():
        return ""

    bin_path = get_graphify_bin()
    graph_path = resolve_graph_json(workdir)
    if not os.path.exists(graph_path):
        return ""

    clean_q = " ".join(question.strip().split())[:240]
    try:
        res = subprocess.run(
            [bin_path, "query", clean_q, "--budget", str(budget), "--graph", graph_path],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            env=_get_env(),
            timeout=6,
        )
        out = (res.stdout or "").strip()
        if res.returncode == 0 and out and "NODE " in out:
            return out
    except Exception:
        pass

    # If question is about VPS / server / docker / ports or didn't match project graph, check VPS graph
    vps_graph = os.path.join(VPS_GRAPH_DIR, "graph.json")
    if os.path.exists(vps_graph) and vps_graph != graph_path:
        try:
            res2 = subprocess.run(
                [bin_path, "query", clean_q, "--budget", str(budget), "--graph", vps_graph],
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                env=_get_env(),
                timeout=6,
            )
            out2 = (res2.stdout or "").strip()
            if res2.returncode == 0 and out2 and "NODE " in out2:
                return out2
        except Exception:
            pass

    # Fallback: provide compact God Nodes summary from GRAPH_REPORT.md
    report_path = os.path.join(os.path.dirname(graph_path), "GRAPH_REPORT.md")
    if os.path.exists(report_path):
        try:
            with open(report_path, "r", encoding="utf-8") as f:
                lines = f.readlines()
            god_section = []
            in_god = False
            for line in lines:
                if line.startswith("## God Nodes"):
                    in_god = True
                    god_section.append(line.strip())
                    continue
                if in_god:
                    if line.startswith("## "):
                        break
                    if line.strip():
                        god_section.append(line.strip())
            if god_section:
                return "\n".join(god_section[:12])
        except Exception:
            pass

    return ""


def enrich_prompt_with_graphify(prompt: str, workdir: Optional[str] = None, compact_for_pty: bool = False) -> str:
    """Attaches Graphify knowledge-graph node locations to an agent prompt to save tokens."""
    if not prompt or "[GRAPHIFY" in prompt:
        return prompt

    subgraph = query_graphify(prompt, workdir=workdir, budget=350 if compact_for_pty else 550)
    if not subgraph:
        return prompt

    if compact_for_pty:
        # Compact single-paragraph hint for interactive CLI/PTY sessions so it doesn't clutter terminal input
        node_lines = [
            line.strip()
            for line in subgraph.splitlines()
            if line.strip().startswith("NODE ")
        ][:8]
        if not node_lines:
            return prompt
        nodes_compact = " ; ".join(node_lines)
        return (
            f"[GRAPHIFY CONTEXT (use these exact file/line nodes instead of broad grep to save tokens): "
            f"{nodes_compact}]\n{prompt}"
        )

    return (
        "=== GRAPHIFY KNOWLEDGE GRAPH CONTEXT (TOKEN-SAVING MAP) ===\n"
        "Use the following deterministic AST graph nodes & file/line locations first instead of scanning the repo:\n"
        f"{subgraph}\n"
        "===========================================================\n\n"
        f"{prompt}"
    )


def get_graphify_status() -> Dict[str, Any]:
    """Returns metadata on both the Project Graph and VPS Graph for the Agent Host UI."""
    status: Dict[str, Any] = {
        "installed": True,
        "enabled": True,
        "project_graph": {"exists": False, "nodes": 0, "edges": 0, "communities": 0},
        "vps_graph": {"exists": False, "nodes": 0, "edges": 0, "communities": 0},
    }

    for key, gdir in [("project_graph", PROJECT_GRAPH_DIR), ("vps_graph", VPS_GRAPH_DIR)]:
        gfile = os.path.join(gdir, "graph.json")
        if os.path.exists(gfile):
            try:
                with open(gfile, "r", encoding="utf-8") as f:
                    data = json.load(f)
                nodes = data.get("nodes", [])
                links = data.get("links", data.get("edges", []))
                comms = {n.get("community") for n in nodes if n.get("community") is not None}
                status[key] = {
                    "exists": True,
                    "nodes": len(nodes),
                    "edges": len(links),
                    "communities": len(comms),
                    "path": gfile,
                }
            except Exception:
                pass
    return status


def rebuild_graphs() -> Dict[str, Any]:
    """Rebuilds both the Agent Host project graph and the VPS graph using AST extraction (0 LLM tokens)."""
    bin_path = get_graphify_bin()
    env = _get_env()
    try:
        subprocess.run(
            [bin_path, "extract", BASE_DIR, "--code-only"],
            cwd=BASE_DIR,
            env=env,
            timeout=30,
            check=False,
        )
        subprocess.run(
            [bin_path, "cluster-only", BASE_DIR, "--no-label"],
            cwd=BASE_DIR,
            env=env,
            timeout=30,
            check=False,
        )
        if os.path.exists("/root/vps-graphify"):
            subprocess.run(
                [bin_path, "extract", "/root/vps-graphify", "--code-only", "--out", "/root"],
                cwd="/root",
                env=env,
                timeout=30,
                check=False,
            )
            subprocess.run(
                [bin_path, "cluster-only", "/root", "--no-label"],
                cwd="/root",
                env=env,
                timeout=30,
                check=False,
            )
    except Exception as e:
        return {"ok": False, "error": str(e)}
    return {"ok": True, "status": get_graphify_status()}
