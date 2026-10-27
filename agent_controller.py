"""Agent Controller: Manages Claude, Antigravity CLI, and OpenRouter API lifecycles and 3-Stage Cascading Fallback (Claude -> Antigravity -> OpenRouter)."""

import os
import re
import uuid
import time
import threading
from typing import Dict, List, Optional
import tmux_manager as tmux
import openrouter_client

CLAUDE_LIMIT_PATTERNS = [
    r"usage limit reached",
    r"rate limit",
    r"weekly limit",
    r"session limit",
    r"please try again at",
    r"out of extra usage",
    r"credit balance too low",
    r"429 Too Many Requests",
    r"overloaded with requests",
    r"quota exceeded"
]

ANTIGRAVITY_LIMIT_PATTERNS = [
    r"quota exceeded",
    r"resource exhausted",
    r"rate limit",
    r"429 Too Many Requests",
    r"daily limit",
    r"token limit exceeded"
]

STATE_WORKING_PATTERNS = [
    r"Thinking…",
    r"Generating…",
    r"Running command",
    r"Executing tool",
    r"Searching",
    r"⠋", r"⠙", r"⠹", r"⠸", r"⠼", r"⠴", r"⠦", r"⠧", r"⠇", r"⠏"
]

STATE_DIALOG_PATTERNS = [
    r"\[y/n\]",
    r"\(y/N\)",
    r"Do you want to proceed\?",
    r"Press Enter to continue",
    r"Allow .* to execute"
]

REMOTE_URL_PATTERN = re.compile(r"https://(claude\.ai/code/[^\s\x1b]+|antigravity\.google/[^\s\x1b]+)")


class AgentSession:
    def __init__(self, data: dict):
        self.id = data.get("id") or str(uuid.uuid4())
        self.session = data.get("session") or f"Agent_{self.id[:8]}"
        self.title = data.get("title") or f"Session {self.id[:6]}"
        self.agent = data.get("agent", "claude")  # 'claude', 'antigravity', or 'openrouter'
        self.model = data.get("model", "")
        self.use_openrouter = bool(data.get("use_openrouter", False)) or (self.agent == "openrouter")
        self.effort = data.get("effort", "medium")
        self.workdir = data.get("workdir", "/root")
        self.remote_url = data.get("remote_url", "")
        self.state = data.get("state", "idle")
        self.fallback_enabled = data.get("fallback_enabled", True)
        self.fallback_triggered = data.get("fallback_triggered", False)
        self.fallback_stage = int(data.get("fallback_stage", 0))  # 0=Primary, 1=Antigravity, 2=OpenRouter
        self.last_prompt = data.get("last_prompt", "")
        self.messages = list(data.get("messages", []))
        self.created_at = data.get("created_at", time.time())

    def to_dict(self):
        return {
            "id": self.id,
            "session": self.session,
            "title": self.title,
            "agent": self.agent,
            "model": self.model,
            "use_openrouter": self.use_openrouter,
            "effort": self.effort,
            "workdir": self.workdir,
            "remote_url": self.remote_url,
            "state": self.state,
            "fallback_enabled": self.fallback_enabled,
            "fallback_triggered": self.fallback_triggered,
            "fallback_stage": self.fallback_stage,
            "last_prompt": self.last_prompt,
            "messages": self.messages,
            "created_at": self.created_at
        }


class SessionManager:
    def __init__(self, config: dict):
        self.config = config
        self.sessions: Dict[str, AgentSession] = {}
        self.stopped_conversations: List[dict] = []
        self._load_saved()

    def _load_saved(self):
        saved = self.config.get("saved_sessions", [])
        for item in saved:
            s = AgentSession(item)
            if s.use_openrouter or tmux.has_session(s.session):
                self.sessions[s.session] = s
            else:
                self.stopped_conversations.append(s.to_dict())

    def sync_existing_tmux_sessions(self):
        """Discovers existing Claude or Antigravity sessions in tmux and running OS processes."""
        active = tmux.list_sessions()
        for name in active:
            if name not in self.sessions:
                pane = tmux.capture_pane(name, 50)
                agent = "antigravity" if ("agy" in pane or "Antigravity" in pane) else "claude"
                s = AgentSession({
                    "id": name.split("_")[-1] if "_" in name else str(uuid.uuid4()),
                    "session": name,
                    "title": name,
                    "agent": agent,
                    "model": "auto"
                })
                self.sessions[name] = s

        try:
            import subprocess
            res = subprocess.run(["ps", "-eo", "pid,args"], stdout=subprocess.PIPE, stdin=subprocess.DEVNULL, text=True)
            for line in res.stdout.splitlines():
                if "claude " in line and "--permission-mode" in line:
                    parts = line.split(maxsplit=1)
                    pid = parts[0]
                    args = parts[1].split()
                    if "-n" in args and "--remote-control" in args:
                        n_idx = args.index("-n")
                        rc_idx = args.index("--remote-control")
                        title = " ".join(args[n_idx + 1:rc_idx])
                        model = args[args.index("--model") + 1] if "--model" in args else "claude-opus-5-5-medium"
                        resume = args[args.index("--resume") + 1] if "--resume" in args else pid
                        session_key = f"proc_{pid}"

                        if not any(s.title == title or s.id == resume for s in self.sessions.values()):
                            s = AgentSession({
                                "id": resume,
                                "session": session_key,
                                "title": title,
                                "agent": "claude",
                                "model": model,
                                "effort": "medium",
                                "state": "idle",
                                "fallback_enabled": True
                            })
                            self.sessions[session_key] = s
                elif ("agy" in line or "antigravity" in line) and ("--mode" in line or "--remote-control" in line or "/snap/antigravity" in line):
                    parts = line.split(maxsplit=1)
                    pid = parts[0]
                    session_key = f"proc_{pid}"
                    if not any(s.session == session_key or s.id == pid for s in self.sessions.values()):
                        model = "gemini-3.8-flash-medium"
                        if "--model" in line:
                            m_match = re.search(r'--model\s+["\']?([^"\'\s]+)["\']?', line)
                            if m_match:
                                model = m_match.group(1)
                        s = AgentSession({
                            "id": pid,
                            "session": session_key,
                            "title": f"Antigravity CLI ({pid})",
                            "agent": "antigravity",
                            "model": model,
                            "effort": "medium",
                            "state": "idle",
                            "fallback_enabled": True
                        })
                        self.sessions[session_key] = s
        except Exception as e:
            print(f"[sync] Process scan error: {e}")

    def start_session(
        self,
        title: str,
        agent: str = "auto",
        model: str = None,
        workdir: str = "/root",
        fallback: bool = True,
        use_openrouter: bool = False,
    ):
        sid = str(uuid.uuid4())
        session_name = f"Agent_{sid[:8]}"
        defaults = self.config.get("defaults", {})

        if use_openrouter or agent == "openrouter":
            chosen_agent = "openrouter"
            chosen_model = model or defaults.get("openrouter_model", "anthropic/claude-3.7-sonnet")
            session = AgentSession({
                "id": sid,
                "session": session_name,
                "title": title,
                "agent": chosen_agent,
                "model": chosen_model,
                "use_openrouter": True,
                "workdir": workdir,
                "fallback_enabled": fallback,
                "fallback_triggered": False,
                "fallback_stage": 0
            })
            self.sessions[session_name] = session
            return session, None

        if agent == "auto":
            chosen_agent = "claude"
            chosen_model = model or defaults.get("claude_model", "claude-sonnet-5-5-medium")
        elif agent == "antigravity":
            chosen_agent = "antigravity"
            chosen_model = model or defaults.get("antigravity_model", "gemini-3.8-flash-medium")
        else:
            chosen_agent = "claude"
            chosen_model = model or defaults.get("claude_model", "claude-sonnet-5-5-medium")

        cmd = self._build_cmd(chosen_agent, chosen_model, title, sid, workdir)
        ok = tmux.new_session(session_name, cmd, cwd=workdir)
        if not ok:
            # If tmux creation fails and fallback/openrouter is available, still register session so user can chat via OpenRouter or CLI
            print(f"[session] Warning: tmux session creation returned false for {session_name}")

        session = AgentSession({
            "id": sid,
            "session": session_name,
            "title": title,
            "agent": chosen_agent,
            "model": chosen_model,
            "use_openrouter": False,
            "workdir": workdir,
            "fallback_enabled": fallback,
            "fallback_triggered": False,
            "fallback_stage": 0
        })
        self.sessions[session_name] = session
        return session, None

    def _build_cmd(self, agent: str, model: str, title: str, conv_id: str, workdir: str) -> str:
        defaults = self.config.get("defaults", {})
        perm = defaults.get("permission_mode", "auto")
        effort = defaults.get("claude_effort", "medium")

        if agent == "antigravity":
            agy_bin = "agy"
            cmd = f"{agy_bin} --mode accept-edits --model \"{model}\" --remote-control"
            return cmd
        else:
            claude_bin = "claude"
            cmd = f"{claude_bin} --permission-mode {perm} --model {model} --effort {effort} --autocompact auto -n \"{title}\" --remote-control \"{title}\""
            return cmd

    def _get_openrouter_fallback_list(self, primary_model: str = "") -> List[str]:
        or_cfg = self.config.get("openrouter", {})
        defaults = self.config.get("defaults", {})
        fb_entries = self.config.get("fallback_list", [])
        chain = []
        if primary_model and "/" in primary_model:
            chain.append(primary_model)
        for item in fb_entries:
            if item.get("provider") == "openrouter" and item.get("model"):
                if item["model"] not in chain:
                    chain.append(item["model"])
        for m in [
            defaults.get("openrouter_model"),
            or_cfg.get("default_model"),
        ] + list(or_cfg.get("fallback_models", [])):
            if m and m not in chain:
                chain.append(m)
        return chain

    def _get_effective_openrouter_key(self, model: str = "") -> str:
        fb_entries = self.config.get("fallback_list", [])
        for item in fb_entries:
            if item.get("model") == model and (item.get("api_key") or "").strip():
                return item["api_key"].strip()
        global_key = (self.config.get("openrouter", {}).get("api_key") or "").strip()
        if global_key:
            return global_key
        for item in fb_entries:
            if (item.get("api_key") or "").strip():
                return item["api_key"].strip()
        return ""

    def _dispatch_openrouter_async(self, session: AgentSession, prompt_text: str):
        """Runs an OpenRouter API completion in a background thread and appends the assistant response."""
        session.state = "working"

        def worker():
            api_key = self._get_effective_openrouter_key(session.model)
            api_msgs = []
            for m in session.messages[-16:]:
                if m.get("role") in ("user", "assistant", "system"):
                    api_msgs.append({"role": m["role"], "content": m["content"]})
            if not api_msgs or api_msgs[-1].get("content") != prompt_text:
                api_msgs.append({"role": "user", "content": prompt_text})

            fb_list = self._get_openrouter_fallback_list(session.model)
            ok, reply_text, used_model = openrouter_client.call_openrouter(
                api_key=api_key,
                model=session.model,
                messages=api_msgs,
                fallback_models=fb_list,
            )
            session.model = used_model
            session.messages.append({
                "role": "assistant",
                "content": reply_text,
                "model": f"openrouter // {used_model}",
                "timestamp": time.time(),
            })
            session.state = "idle"

        t = threading.Thread(target=worker, daemon=True)
        t.start()

    def send_prompt(self, session_name: str, text: str, model: Optional[str] = None):
        session = self.sessions.get(session_name)
        if not session:
            return False, "Session not found"
        if model and model != session.model:
            self.switch_model(session_name, model)

        session.last_prompt = text
        session.messages.append({
            "role": "user",
            "content": text,
            "model": session.model,
            "timestamp": time.time()
        })

        # If OpenRouter mode is toggled ON for this agent, route directly through OpenRouter API!
        if session.use_openrouter or session.agent == "openrouter":
            self._dispatch_openrouter_async(session, text)
            return True, None

        ok = tmux.send_keys(session_name, text, enter=True)
        if not ok and session.fallback_enabled:
            # If tmux send fails and OpenRouter key is configured, cascade to OpenRouter automatically
            api_key = self.config.get("openrouter", {}).get("api_key", "").strip()
            if api_key and self.config.get("defaults", {}).get("openrouter_fallback_enabled", True):
                self._trigger_openrouter_fallback(session, reason="PTY/Tmux unreachable — cascaded to OpenRouter API")
                return True, None
        return ok, None if ok else "Failed to send keystrokes to tmux"

    def switch_model(self, session_name: str, model: str):
        session = self.sessions.get(session_name)
        if not session:
            return False, "Session not found"
        session.model = model
        # If model has a slash (e.g. anthropic/claude-3.7-sonnet or deepseek/deepseek-r1), it's an OpenRouter model
        if "/" in model:
            session.use_openrouter = True
            session.agent = "openrouter"
            return True, None

        if "gemini" in model.lower() and session.agent != "antigravity":
            session.agent = "antigravity"
            session.use_openrouter = False
        elif "claude" in model.lower() and session.agent != "claude":
            session.agent = "claude"
            session.use_openrouter = False

        if not session.use_openrouter:
            tmux.send_keys(session_name, f"/model {model}", enter=True)
        return True, None

    def toggle_openrouter(self, session_name: str, enabled: Optional[bool] = None):
        """Toggles whether this specific agent session uses the OpenRouter API."""
        session = self.sessions.get(session_name)
        if not session:
            return False, "Session not found"
        if enabled is None:
            session.use_openrouter = not session.use_openrouter
        else:
            session.use_openrouter = bool(enabled)

        defaults = self.config.get("defaults", {})
        if session.use_openrouter:
            session.agent = "openrouter"
            if "/" not in (session.model or ""):
                session.model = defaults.get("openrouter_model", "anthropic/claude-3.7-sonnet")
        else:
            session.agent = "claude"
            if "/" in (session.model or ""):
                session.model = defaults.get("claude_model", "claude-sonnet-5-5-medium")
        return True, None

    def clear_history(self, session_name: str):
        session = self.sessions.get(session_name)
        if session:
            session.messages = []
        return True, None

    def get_history(self, session_name: str, lines: int = 150):
        session = self.sessions.get(session_name)
        if not session:
            return None, "Session not found"
        pane = "" if session.use_openrouter else tmux.capture_pane(session_name, lines=lines)
        return {
            "session": session.to_dict(),
            "messages": session.messages,
            "terminal_output": pane,
            "model": session.model,
            "agent": session.agent,
            "use_openrouter": session.use_openrouter,
            "state": session.state
        }, None

    def toggle_fallback(self, session_name: str, enabled: Optional[bool] = None):
        session = self.sessions.get(session_name)
        if not session:
            return False, "Session not found"
        if enabled is None:
            session.fallback_enabled = not session.fallback_enabled
        else:
            session.fallback_enabled = bool(enabled)
        return True, None

    def stop_session(self, session_name: str):
        session = self.sessions.get(session_name)
        if not session:
            return False, "Session not found"
        tmux.kill_session(session_name)
        self.stopped_conversations.append(session.to_dict())
        del self.sessions[session_name]
        return True, None

    def resume_session(self, conv_id: str):
        found = None
        for i, c in enumerate(self.stopped_conversations):
            if c["id"] == conv_id:
                found = self.stopped_conversations.pop(i)
                break
        if not found:
            return None, "Conversation not found"
        return self.start_session(
            title=found.get("title", "Resumed Session"),
            agent=found.get("agent", "claude"),
            model=found.get("model"),
            workdir=found.get("workdir", "/root"),
            fallback=found.get("fallback_enabled", True),
            use_openrouter=found.get("use_openrouter", False)
        )

    def update_states(self):
        """Polls terminal outputs, parses state, detects remote URLs, and executes 3-stage cascading fallback."""
        for s_name, s in list(self.sessions.items()):
            if s.use_openrouter or s.agent == "openrouter":
                continue

            if not tmux.has_session(s_name):
                s.state = "idle"
                continue

            pane = tmux.capture_pane(s_name, lines=100)
            if not pane:
                continue

            # 1. Detect Remote Control URL
            m = REMOTE_URL_PATTERN.search(pane)
            if m:
                s.remote_url = m.group(0)

            # 2. 3-Stage Cascading Failover:
            # Stage 0 -> Stage 1: Claude hits limit -> Switch to Antigravity
            if s.agent == "claude" and s.fallback_enabled and s.fallback_stage == 0:
                for pat in CLAUDE_LIMIT_PATTERNS:
                    if re.search(pat, pane, re.IGNORECASE):
                        self._trigger_fallback(s, reason=pat)
                        break

            # Stage 1 -> Stage 2: Antigravity hits limit -> Switch to OpenRouter API
            elif s.agent == "antigravity" and s.fallback_enabled and s.fallback_stage <= 1:
                if self.config.get("defaults", {}).get("openrouter_fallback_enabled", True):
                    for pat in ANTIGRAVITY_LIMIT_PATTERNS:
                        if re.search(pat, pane, re.IGNORECASE):
                            self._trigger_openrouter_fallback(s, reason=pat)
                            break

            # 3. Determine running state
            if s.fallback_triggered:
                s.state = "fallback_active"
            elif any(re.search(pat, pane) for pat in STATE_WORKING_PATTERNS):
                s.state = "working"
            elif any(re.search(pat, pane) for pat in STATE_DIALOG_PATTERNS):
                s.state = "dialog"
            else:
                s.state = "idle"

    def _trigger_fallback(self, session: AgentSession, reason: str):
        """Failover Tier 1 -> Tier 2: Switches session from Claude to Antigravity CLI."""
        print(f"[fallback-stage-1] Triggering Antigravity fallback on '{session.title}' due to: {reason}")
        session.fallback_triggered = True
        session.fallback_stage = 1
        session.agent = "antigravity"

        defaults = self.config.get("defaults", {})
        fallback_model = defaults.get("antigravity_model") or defaults.get("fallback_model", "gemini-3.8-flash-medium")
        session.model = fallback_model

        tmux.send_keys(session.session, "\x03", enter=False)
        time.sleep(0.5)
        tmux.send_keys(session.session, "/exit", enter=True)
        time.sleep(0.5)

        agy_cmd = f"agy --mode accept-edits --model \"{fallback_model}\" --remote-control"
        tmux.send_keys(session.session, agy_cmd, enter=True)
        time.sleep(1.0)

        if session.last_prompt:
            notice = f"[Failover Stage 1: Claude limit reached -> Switched to Antigravity ({fallback_model})]"
            tmux.send_keys(session.session, f"{notice} {session.last_prompt}", enter=True)

    def _trigger_openrouter_fallback(self, session: AgentSession, reason: str):
        """Failover Tier 2 -> Tier 3: Switches session from Antigravity to OpenRouter Multi-Model API."""
        print(f"[fallback-stage-2] Triggering OpenRouter fallback on '{session.title}' due to: {reason}")
        session.fallback_triggered = True
        session.fallback_stage = 2
        session.use_openrouter = True
        session.agent = "openrouter"

        defaults = self.config.get("defaults", {})
        or_model = defaults.get("openrouter_model", "anthropic/claude-3.7-sonnet")
        session.model = or_model

        session.messages.append({
            "role": "assistant",
            "content": f"⚡ [Cascading Failover Stage 2 Activated ({reason}): Switched to OpenRouter API ({or_model})]",
            "model": f"openrouter // {or_model}",
            "timestamp": time.time(),
        })

        if session.last_prompt:
            self._dispatch_openrouter_async(session, session.last_prompt)
