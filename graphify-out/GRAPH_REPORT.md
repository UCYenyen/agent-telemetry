# Graph Report - agent-telemetry  (2026-10-28)

## Corpus Check
- 156 files · ~31,657 words
- Verdict: corpus is large enough that graph structure adds value.
- Unclassified: 16 file(s) not represented in the graph (top: .css 7, (none) 6, .example 1)

## Summary
- 547 nodes · 1122 edges · 65 communities (13 shown, 52 thin omitted)
- Extraction: 96% EXTRACTED · 4% INFERRED · 0% AMBIGUOUS · INFERRED: 44 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `8f274339`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- AgentSession
- Frontend Design Skill
- AGENTS.md
- CLAUDE.md
- GEMINI.md
- Agent Host
- useApp
- AgentSession
- Fallback
- CouncilRoom
- composer.json
- Illuminate\Database\Schema\Blueprint
- CouncilTest.php
- CouncilStream.jsx
- CouncilService.php
- package.json
- AppServiceProvider
- 4c4c2d8e5dc07c9240ce992614041412.php

## God Nodes (most connected - your core abstractions)
1. `useApp()` - 37 edges
2. `AgentSession` - 30 edges
3. `AgentSessionManager` - 26 edges
4. `CouncilRoom` - 19 edges
5. `react` - 18 edges
6. `Setting` - 17 edges
7. `CouncilService` - 17 edges
8. `Fallback` - 16 edges
9. `OpenRouter` - 16 edges
10. `api` - 14 edges

## Surprising Connections (you probably didn't know these)
- `AgentSessionController` --references--> `AgentSessionManager`  [EXTRACTED]
  app/Http/Controllers/AgentSessionController.php → app/Services/AgentSessionManager.php
- `CouncilRoomController` --inherits--> `Controller`  [EXTRACTED]
  app/Http/Controllers/CouncilRoomController.php → app/Http/Controllers/Controller.php
- `RunCouncilDeliberation` --references--> `CouncilRoom`  [EXTRACTED]
  app/Jobs/RunCouncilDeliberation.php → app/Models/CouncilRoom.php
- `{closure#9}()` --references--> `Advisor`  [EXTRACTED]
  app/Services/CouncilService.php → app/Models/Advisor.php
- `{closure#1}()` --references--> `AgentSession`  [EXTRACTED]
  app/Services/AgentSessionManager.php → app/Models/AgentSession.php

## Import Cycles
- None detected.

## Communities (65 total, 52 thin omitted)

### Community 0 - "AgentSession"
Cohesion: 0.09
Nodes (7): MonitorAgents, ReplyViaOpenRouter, RunCouncilDeliberation, AgentSession, AgentSessionManager, {closure#1}(), Tmux

### Community 6 - "Frontend Design Skill"
Cohesion: 0.50
Nodes (3): Design Thinking, Frontend Aesthetics Guidelines, Frontend Design Skill

### Community 12 - "Agent Host"
Cohesion: 0.29
Nodes (6): Agent Host, Layout, Run (development), Run (production), Setup, Tests

### Community 13 - "useApp"
Cohesion: 0.07
Nodes (57): react, react-dom, App(), AgentBadge(), ChatComposer(), Modal(), ModelSelect(), OpenRouterToggle() (+49 more)

### Community 14 - "AgentSession"
Cohesion: 0.08
Nodes (14): AdvisorController, AgentSessionController, Controller, CouncilSettingsController, DashboardController, FallbackController, OpenRouterModelController, SessionMessageController (+6 more)

### Community 15 - "Fallback"
Cohesion: 0.13
Nodes (6): {closure#1}(), Fallback, LlmCascade, {closure#1}(), {closure#2}(), OpenRouter

### Community 16 - "CouncilRoom"
Cohesion: 0.11
Nodes (5): CouncilRoomController, CouncilMessage, CouncilRoom, SessionMessage, CouncilService

### Community 17 - "composer.json"
Cohesion: 0.04
Nodes (45): pestphp/pest-plugin, php-http/discovery, autoload, autoload-dev, psr-4, psr-4, config, allow-plugins (+37 more)

### Community 18 - "Illuminate\Database\Schema\Blueprint"
Cohesion: 0.11
Nodes (9): {closure#1}(), {closure#2}(), {closure#1}(), {closure#1}(), {closure#1}(), {closure#2}(), {closure#1}(), {closure#1}() (+1 more)

### Community 19 - "CouncilTest.php"
Cohesion: 0.08
Nodes (7): AgentSessionTest, {closure#2}(), CouncilTest, FallbackChainTest, {closure#2}(), MonitorAgentsTest, TestCase

### Community 20 - "CouncilStream.jsx"
Cohesion: 0.11
Nodes (21): EmptyState(), WorkingIndicator(), ChatMessage(), ChatPane(), CouncilEmptyState(), PROMPTS, CouncilStream(), DIVIDERS (+13 more)

### Community 22 - "CouncilService.php"
Cohesion: 0.08
Nodes (3): Advisor, {closure#9}(), DatabaseSeeder

### Community 23 - "package.json"
Cohesion: 0.09
Nodes (21): dependencies, react, react-dom, devDependencies, concurrently, laravel-vite-plugin, vite, @vitejs/plugin-react (+13 more)

## Knowledge Gaps
- **2 isolated node(s):** `concurrently`, `@laravel/multiplex`
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 187 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **52 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `AgentSession` connect `AgentSession` to `CouncilRoom`, `CouncilTest.php`, `AgentSession`?**
  _High betweenness centrality (0.032) - this node is a cross-community bridge._
- **What connects `concurrently`, `@laravel/multiplex` to the rest of the system?**
  _2 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `AgentSession` be split into smaller, more focused modules?**
  _Cohesion score 0.09224489795918367 - nodes in this community are weakly interconnected._
- **Why does `Setting` connect `AgentSession` to `AgentSession`, `Fallback`, `CouncilRoom`, `CouncilTest.php`, `CouncilService.php`?**
  _High betweenness centrality (0.028) - this node is a cross-community bridge._
- **Should `useApp` be split into smaller, more focused modules?**
  _Cohesion score 0.07366838487972509 - nodes in this community are weakly interconnected._
- **Why does `react` connect `useApp` to `CouncilStream.jsx`, `package.json`?**
  _High betweenness centrality (0.024) - this node is a cross-community bridge._
- **Should `AgentSession` be split into smaller, more focused modules?**
  _Cohesion score 0.07518796992481203 - nodes in this community are weakly interconnected._