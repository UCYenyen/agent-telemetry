# Graph Report - agent-host  (2026-10-27)

## Corpus Check
- 16 files · ~17,389 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 1 file(s) not represented in the graph (top: .service 1)

## Summary
- 144 nodes · 253 edges · 13 communities (7 shown, 6 thin omitted)
- Extraction: 98% EXTRACTED · 2% INFERRED · 0% AMBIGUOUS · INFERRED: 5 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- server.py
- CouncilManager
- graphify_bridge.py
- tmux_manager.py
- config.py
- SessionManager
- Frontend Design Skill
- AGENTS.md
- CLAUDE.md
- GEMINI.md
- test_mcp.mjs
- run.sh
- README.md

## God Nodes (most connected - your core abstractions)
1. `SessionManager` - 21 edges
2. `CouncilManager` - 15 edges
3. `AgentSession` - 9 edges
4. `query_graphify()` - 9 edges
5. `run_tmux()` - 9 edges
6. `query_llm_with_cascade()` - 8 edges
7. `rebuild_graphs()` - 8 edges
8. `send_keys()` - 7 edges
9. `get_graphify_status()` - 6 edges
10. `AgentHostHandler` - 6 edges

## Surprising Connections (you probably didn't know these)
- `worker()` --calls--> `enrich_prompt_with_graphify()`  [EXTRACTED]
  agent_controller.py → graphify_bridge.py
- `worker()` --calls--> `call_openrouter()`  [EXTRACTED]
  agent_controller.py → openrouter_client.py
- `query_llm_with_cascade()` --calls--> `call_openrouter()`  [EXTRACTED]
  council_engine.py → openrouter_client.py

## Import Cycles
- None detected.

## Communities (13 total, 6 thin omitted)

### Community 0 - "server.py"
Cohesion: 0.12
Nodes (3): resolve_model_id(), background_monitor(), run()

### Community 1 - "CouncilManager"
Cohesion: 0.20
Nodes (4): CouncilManager, run_single_advisor(), run_single_reviewer(), query_llm_with_cascade()

### Community 2 - "graphify_bridge.py"
Cohesion: 0.15
Nodes (8): enrich_prompt_with_graphify(), _get_env(), get_graphify_bin(), get_graphify_status(), query_graphify(), rebuild_graphs(), resolve_graph_json(), AgentHostHandler

### Community 3 - "tmux_manager.py"
Cohesion: 0.14
Nodes (6): capture_pane(), has_session(), kill_session(), list_sessions(), new_session(), run_tmux()

### Community 4 - "config.py"
Cohesion: 0.21
Nodes (6): hash_password(), load_config(), save_config(), verify_password(), call_openrouter(), fetch_openrouter_models()

### Community 5 - "SessionManager"
Cohesion: 0.13
Nodes (4): AgentSession, SessionManager, worker(), send_keys()

### Community 6 - "Frontend Design Skill"
Cohesion: 0.50
Nodes (3): Design Thinking, Frontend Aesthetics Guidelines, Frontend Design Skill

## Knowledge Gaps
- **1 isolated node(s):** `PATH`
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 61 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **6 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `SessionManager` connect `SessionManager` to `server.py`, `tmux_manager.py`?**
  _High betweenness centrality (0.226) - this node is a cross-community bridge._
- **What connects `PATH` to the rest of the system?**
  _1 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `server.py` be split into smaller, more focused modules?**
  _Cohesion score 0.12333333333333334 - nodes in this community are weakly interconnected._
- **Why does `CouncilManager` connect `CouncilManager` to `server.py`?**
  _High betweenness centrality (0.126) - this node is a cross-community bridge._
- **Should `tmux_manager.py` be split into smaller, more focused modules?**
  _Cohesion score 0.14210526315789473 - nodes in this community are weakly interconnected._
- **Why does `AgentHostHandler` connect `graphify_bridge.py` to `server.py`?**
  _High betweenness centrality (0.041) - this node is a cross-community bridge._
- **Should `SessionManager` be split into smaller, more focused modules?**
  _Cohesion score 0.12615384615384614 - nodes in this community are weakly interconnected._