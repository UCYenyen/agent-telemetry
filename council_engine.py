"""LLM Council & Autonomous Multi-Agent Group Chat Engine.

Supports per-agent OpenRouter API toggle AND 3-stage cascading failover:
Claude -> Antigravity -> OpenRouter (with multiple OpenRouter fallback models).
"""

import uuid
import time
import random
import shutil
import threading
import subprocess
from concurrent.futures import ThreadPoolExecutor, as_completed
from typing import Dict, List, Optional, Tuple
import config
import openrouter_client

AGY_BIN = shutil.which("agy") or "/snap/antigravity-cli/current/bin/agy" or "/snap/antigravity-cli/23/bin/agy"

MODEL_ALIASES = {
    "Gemini 3.8 Flash (Medium)": "gemini-3.8-flash-medium",
    "Gemini 3.8 Flash (High)": "gemini-3.8-flash-high",
    "Gemini 3.8 Flash (Low)": "gemini-3.8-flash-low",
    "Gemini 4 Argon (Medium)": "gemini-4-argon-medium",
    "Gemini 4 Argon (High)": "gemini-4-argon-high",
    "Gemini 4 Argon (Low)": "gemini-4-argon-low",
    "Gemini 3.5 Pro": "gemini-4-argon-high",
    "Gemini 3.5 Flash": "gemini-3.8-flash-medium",
    "claude-opus-5-5": "claude-opus-5-5-medium",
    "claude-opus-5": "claude-opus-5-5-medium",
    "claude-sonnet-5-5": "claude-sonnet-5-5-medium",
    "claude-sonnet-5": "claude-sonnet-5-5-medium",
    "claude-fable-5-1": "claude-sonnet-5-5-high",
    "claude-haiku-4-5": "claude-sonnet-5-5-low",
}


def resolve_model_id(model_name: str) -> str:
    if not model_name:
        return "gemini-3.8-flash-medium"
    return MODEL_ALIASES.get(model_name, model_name)


def query_llm_with_cascade(
    prompt: str,
    model: str,
    use_openrouter: bool,
    cfg: dict,
    timeout: int = 90,
) -> Tuple[str, str]:
    """Executes an LLM call with per-agent OpenRouter toggle and 3-Stage Cascading Fallback:
    Primary (Claude/Antigravity) -> Antigravity Fallback -> OpenRouter Multi-Model API.

    Returns:
        (response_text, effective_model_label)
    """
    or_cfg = cfg.get("openrouter", {})
    defaults = cfg.get("defaults", {})
    global_api_key = (or_cfg.get("api_key") or "").strip()
    fb_list = cfg.get("fallback_list", [])

    # Also check if any fallback_list entry has an api_key if global_api_key is empty
    if not global_api_key:
        for item in fb_list:
            if (item.get("api_key") or "").strip():
                global_api_key = item["api_key"].strip()
                break

    # Build OpenRouter fallback chain from fallback_list + openrouter config
    or_chain = []
    for item in fb_list:
        if item.get("provider") == "openrouter" and item.get("model"):
            if item["model"] not in or_chain:
                or_chain.append(item["model"])
    for m in [
        model if (use_openrouter or "/" in (model or "")) else None,
        defaults.get("openrouter_model"),
        or_cfg.get("default_model"),
    ] + list(or_cfg.get("fallback_models", [])):
        if m and m not in or_chain:
            or_chain.append(m)

    # 1. Direct OpenRouter Mode (if Use OpenRouter toggle is ON for this agent)
    if use_openrouter or "/" in (model or ""):
        target_or_model = model if "/" in (model or "") else (or_chain[0] if or_chain else "anthropic/claude-3.7-sonnet")
        # Find specific key for target_or_model if present in fallback_list
        entry_key = global_api_key
        for item in fb_list:
            if item.get("model") == target_or_model and (item.get("api_key") or "").strip():
                entry_key = item["api_key"].strip()
                break
        ok, text, used_model = openrouter_client.call_openrouter(
            api_key=entry_key,
            model=target_or_model,
            messages=[{"role": "user", "content": prompt}],
            fallback_models=or_chain,
            timeout=timeout,
        )
        return text, f"openrouter // {used_model}"

    # 2. Tier 1: Primary CLI Model (Claude or Antigravity)
    resolved = resolve_model_id(model)
    cmd = [
        AGY_BIN,
        "--disable-slash-commands",
        "--model",
        resolved,
        "-p",
        prompt,
    ]
    try:
        res = subprocess.run(
            cmd,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            stdin=subprocess.DEVNULL,
            text=True,
            timeout=timeout,
        )
        out = (res.stdout or "").strip()
        if res.returncode == 0 and out:
            return out, resolved
    except Exception as e:
        print(f"[cascade] Primary ({resolved}) exception: {e}")

    # 3. Iterate through dynamic user-configured fallback_list in exact order
    for idx, fb in enumerate(fb_list):
        fb_provider = fb.get("provider", "antigravity")
        fb_model = fb.get("model", "")
        fb_key = (fb.get("api_key") or "").strip() or global_api_key
        if not fb_model:
            continue

        if fb_provider == "openrouter" or "/" in fb_model:
            if not fb_key:
                continue
            ok, text, used_or = openrouter_client.call_openrouter(
                api_key=fb_key,
                model=fb_model,
                messages=[{"role": "user", "content": prompt}],
                fallback_models=[],
                timeout=timeout,
            )
            if ok:
                return text, f"fallback #{idx+1} (openrouter) // {used_or}"
        else:
            fb_resolved = resolve_model_id(fb_model)
            if fb_resolved == resolved:
                continue
            try:
                fb_cmd = [
                    AGY_BIN,
                    "--disable-slash-commands",
                    "--model",
                    fb_resolved,
                    "-p",
                    prompt,
                ]
                res_fb = subprocess.run(
                    fb_cmd,
                    stdout=subprocess.PIPE,
                    stderr=subprocess.PIPE,
                    stdin=subprocess.DEVNULL,
                    text=True,
                    timeout=timeout,
                )
                out_fb = (res_fb.stdout or "").strip()
                if res_fb.returncode == 0 and out_fb:
                    return out_fb, f"fallback #{idx+1} ({fb_provider}) // {fb_resolved}"
            except Exception as e:
                print(f"[cascade] Fallback #{idx+1} ({fb_resolved}) exception: {e}")

    return f"[Error: Model {resolved} and all {len(fb_list)} fallbacks unavailable]", resolved


class CouncilManager:
    def __init__(self, cfg: dict):
        self.cfg = cfg
        self.lock = threading.Lock()
        council_cfg = self.cfg.setdefault("council", {})
        if not council_cfg.get("advisors"):
            council_cfg["advisors"] = [dict(a) for a in config.DEFAULT_ADVISORS]
        for a in council_cfg.get("advisors", []):
            a.setdefault("use_openrouter", False)
        council_cfg.setdefault("chairman_model", "claude-opus-5-5-high")
        council_cfg.setdefault("chairman_use_openrouter", False)
        council_cfg.setdefault("enable_peer_review", True)
        council_cfg.setdefault("rooms", [])

        self.rooms: Dict[str, dict] = {}
        for r in council_cfg.get("rooms", []):
            if r.get("status") in ("stage1_deliberating", "stage2_peer_review", "stage3_synthesizing"):
                r["status"] = "interrupted"
                r["status_detail"] = "Deliberation was interrupted by a previous server restart. Ask again to reconvene."
            self.rooms[r["id"]] = r

    def _persist(self):
        with self.lock:
            self.cfg["council"]["rooms"] = list(self.rooms.values())[-25:]
            config.save_config(self.cfg)

    def get_state(self) -> dict:
        c = self.cfg.get("council", {})
        rooms_sorted = sorted(
            self.rooms.values(),
            key=lambda x: x.get("updated_at", x.get("created_at", 0)),
            reverse=True,
        )
        return {
            "chairman_model": c.get("chairman_model", "claude-opus-5-5-high"),
            "chairman_use_openrouter": bool(c.get("chairman_use_openrouter", False)),
            "enable_peer_review": bool(c.get("enable_peer_review", True)),
            "advisors": c.get("advisors", []),
            "rooms": rooms_sorted,
        }

    def get_room(self, room_id: str) -> Optional[dict]:
        return self.rooms.get(room_id)

    # ------------------------------------------------------------------
    # Advisor Personality, Model & OpenRouter Toggle CRUD
    # ------------------------------------------------------------------
    def add_advisor(
        self,
        name: str,
        prompt: str,
        model: str,
        icon: str = "🧠",
        color: str = "#a855f7",
        use_openrouter: bool = False,
    ) -> dict:
        advisors = self.cfg["council"].setdefault("advisors", [])
        adv_id = f"custom_{uuid.uuid4().hex[:8]}"
        new_adv = {
            "id": adv_id,
            "name": name.strip() or "Custom Advisor",
            "icon": icon.strip() or "🧠",
            "color": color.strip() or "#a855f7",
            "model": model or ("anthropic/claude-3.7-sonnet" if use_openrouter else "gemini-3.8-flash-medium"),
            "use_openrouter": bool(use_openrouter),
            "enabled": True,
            "built_in": False,
            "prompt": prompt.strip() or "Analyze the problem critically and offer a distinct perspective.",
        }
        advisors.append(new_adv)
        self._persist()
        return new_adv

    def update_advisor(self, adv_id: str, updates: dict) -> Optional[dict]:
        advisors = self.cfg["council"].get("advisors", [])
        defaults = self.cfg.get("defaults", {})
        for adv in advisors:
            if adv["id"] == adv_id:
                for k in ["name", "icon", "color", "model", "enabled", "prompt", "use_openrouter"]:
                    if k in updates and updates[k] is not None:
                        adv[k] = updates[k]
                # If use_openrouter was toggled ON and current model isn't an OpenRouter model, switch to default OpenRouter model
                if adv.get("use_openrouter") and "/" not in (adv.get("model") or ""):
                    adv["model"] = defaults.get("openrouter_model", "anthropic/claude-3.7-sonnet")
                elif not adv.get("use_openrouter") and "/" in (adv.get("model") or ""):
                    adv["model"] = defaults.get("claude_model", "claude-sonnet-5-5-medium")
                self._persist()
                return adv
        return None

    def delete_advisor(self, adv_id: str) -> bool:
        advisors = self.cfg["council"].get("advisors", [])
        before = len(advisors)
        self.cfg["council"]["advisors"] = [a for a in advisors if a["id"] != adv_id]
        if len(self.cfg["council"]["advisors"]) < before:
            self._persist()
            return True
        return False

    def reset_advisors(self) -> List[dict]:
        import copy
        self.cfg["council"]["advisors"] = copy.deepcopy(config.DEFAULT_ADVISORS)
        self._persist()
        return self.cfg["council"]["advisors"]

    def update_settings(
        self,
        chairman_model: Optional[str] = None,
        chairman_use_openrouter: Optional[bool] = None,
        enable_peer_review: Optional[bool] = None,
    ) -> dict:
        defaults = self.cfg.get("defaults", {})
        if chairman_use_openrouter is not None:
            self.cfg["council"]["chairman_use_openrouter"] = bool(chairman_use_openrouter)
            if chairman_use_openrouter and "/" not in (self.cfg["council"].get("chairman_model") or ""):
                self.cfg["council"]["chairman_model"] = defaults.get("openrouter_model", "anthropic/claude-3.7-sonnet")
            elif not chairman_use_openrouter and "/" in (self.cfg["council"].get("chairman_model") or ""):
                self.cfg["council"]["chairman_model"] = "claude-opus-5-5-high"
        if chairman_model is not None:
            self.cfg["council"]["chairman_model"] = chairman_model
        if enable_peer_review is not None:
            self.cfg["council"]["enable_peer_review"] = bool(enable_peer_review)
        self._persist()
        return {
            "chairman_model": self.cfg["council"]["chairman_model"],
            "chairman_use_openrouter": self.cfg["council"].get("chairman_use_openrouter", False),
            "enable_peer_review": self.cfg["council"]["enable_peer_review"],
        }

    def delete_room(self, room_id: str) -> bool:
        if room_id in self.rooms:
            del self.rooms[room_id]
            self._persist()
            return True
        return False

    # ------------------------------------------------------------------
    # Council Deliberation & Autonomous Group Chat Execution
    # ------------------------------------------------------------------
    def start_or_continue_council(
        self,
        question: str,
        room_id: Optional[str] = None,
        enable_peer_review: Optional[bool] = None,
    ) -> dict:
        advisors = [a for a in self.cfg["council"].get("advisors", []) if a.get("enabled", True)]
        if not advisors:
            raise ValueError("No enabled advisors in the council. Enable at least one personality.")

        now = time.time()
        if room_id and room_id in self.rooms:
            room = self.rooms[room_id]
        else:
            rid = f"council_{uuid.uuid4().hex[:8]}"
            short_title = question.strip().split("\n")[0][:58]
            if len(question.strip()) > 58:
                short_title += "…"
            room = {
                "id": rid,
                "title": short_title,
                "created_at": now,
                "updated_at": now,
                "status": "stage1_deliberating",
                "status_detail": f"Stage 1: {len(advisors)} advisors thinking independently in parallel…",
                "active_advisors": [dict(a) for a in advisors],
                "chairman_model": self.cfg["council"].get("chairman_model", "claude-opus-5-5-high"),
                "messages": [],
            }
            self.rooms[rid] = room

        do_peer = (
            bool(enable_peer_review)
            if enable_peer_review is not None
            else bool(self.cfg["council"].get("enable_peer_review", True))
        )

        user_msg = {
            "id": uuid.uuid4().hex[:8],
            "type": "user_question",
            "role": "user",
            "sender": "OPERATOR",
            "content": question.strip(),
            "timestamp": now,
        }
        room["messages"].append(user_msg)
        room["status"] = "stage1_deliberating"
        room["status_detail"] = f"Stage 1: Convening {len(advisors)} independent advisors in parallel…"
        room["active_advisors"] = [dict(a) for a in advisors]
        room["chairman_model"] = self.cfg["council"].get("chairman_model", "claude-opus-5-5-high")
        room["progress"] = {
            "stage": 1,
            "stage_name": "Stage 1: Independent Deliberations",
            "total_advisors": len(advisors),
            "completed_advisors": 0,
            "total_reviewers": len(advisors) if do_peer else 0,
            "completed_reviewers": 0,
            "advisors": {
                a["id"]: {
                    "id": a["id"],
                    "name": a["name"],
                    "icon": a.get("icon", "🧠"),
                    "color": a.get("color", "#00d2ff"),
                    "model": a.get("model", "gemini-3.8-flash-low"),
                    "status": "thinking",
                    "action": f"Analyzing question from {a['name']} perspective…",
                    "stage1_elapsed": None,
                    "peer_status": "waiting" if do_peer else "skipped",
                    "peer_action": "Waiting for Stage 1 completion…" if do_peer else "Skipped",
                    "peer_elapsed": None,
                }
                for a in advisors
            },
            "chairman": {
                "name": "The Chairman",
                "icon": "⚖️",
                "model": self.cfg["council"].get("chairman_model", "claude-opus-5-5-high"),
                "status": "waiting",
                "action": "Waiting for advisor takes and peer reviews…",
                "elapsed": None,
            }
        }
        room["updated_at"] = now
        self._persist()

        t = threading.Thread(
            target=self._run_council_pipeline,
            args=(room["id"], question.strip(), [dict(a) for a in advisors], do_peer),
            daemon=True,
        )
        t.start()
        return room

    def _append_msg(self, room_id: str, msg: dict):
        room = self.rooms.get(room_id)
        if not room:
            return
        with self.lock:
            room["messages"].append(msg)
            room["updated_at"] = time.time()
        self._persist()

    def _run_council_pipeline(self, room_id: str, question: str, advisors: List[dict], do_peer_review: bool):
        room = self.rooms.get(room_id)
        if not room:
            return

        prior_verdicts = [
            m["content"] for m in room.get("messages", [])
            if m.get("type") == "chairman_verdict"
        ]
        context_block = ""
        if prior_verdicts:
            context_block = (
                "\n\nPrior Council Verdict in this session (for context):\n"
                + prior_verdicts[-1][:1200]
                + "\n"
            )

        # STAGE 1: INDEPENDENT PARALLEL ADVISOR DELIBERATION
        stage1_results: List[dict] = []

        def run_single_advisor(adv: dict) -> dict:
            t0 = time.time()
            with self.lock:
                if "progress" in room and "advisors" in room["progress"] and adv["id"] in room["progress"]["advisors"]:
                    room["progress"]["advisors"][adv["id"]]["status"] = "thinking"
                    room["progress"]["advisors"][adv["id"]]["action"] = f"Analyzing question from {adv['name']} lens…"
                    room["updated_at"] = time.time()
            self._persist()

            adv_prompt = (
                f"You are {adv['name']} on an LLM Council.\n\n"
                f"Your thinking style: {adv['prompt']}\n\n"
                f"A user has brought this question/decision to the council:\n"
                f"---\n{question}{context_block}\n---\n\n"
                f"Respond strictly from your assigned perspective. Be direct, sharp, and specific. "
                f"Do NOT hedge or try to be balanced — lean 100% into your thinking lens. "
                f"The other advisors will cover the angles you are not covering.\n\n"
                f"Keep your response between 140 and 260 words. No preamble. Go straight into your analysis."
            )
            ans, effective_model = query_llm_with_cascade(
                prompt=adv_prompt,
                model=adv.get("model", "gemini-3.8-flash-medium"),
                use_openrouter=bool(adv.get("use_openrouter", False)),
                cfg=self.cfg,
            )
            elapsed = round(time.time() - t0, 1)
            msg = {
                "id": uuid.uuid4().hex[:8],
                "type": "advisor_take",
                "stage": 1,
                "advisor_id": adv["id"],
                "sender": adv["name"],
                "icon": adv.get("icon", "🧠"),
                "color": adv.get("color", "#00d2ff"),
                "model": effective_model,
                "use_openrouter": bool(adv.get("use_openrouter", False)),
                "role_desc": adv.get("prompt", ""),
                "content": ans,
                "elapsed_sec": elapsed,
                "timestamp": time.time(),
            }
            self._append_msg(room_id, msg)

            with self.lock:
                if "progress" in room and "advisors" in room["progress"] and adv["id"] in room["progress"]["advisors"]:
                    room["progress"]["advisors"][adv["id"]]["status"] = "done"
                    room["progress"]["advisors"][adv["id"]]["action"] = f"Take completed in {elapsed}s"
                    room["progress"]["advisors"][adv["id"]]["stage1_elapsed"] = elapsed
                    done_count = len([m for m in room["messages"] if m.get("type") == "advisor_take"])
                    room["progress"]["completed_advisors"] = done_count
                    room["status_detail"] = f"Stage 1: {done_count}/{len(advisors)} advisors complete"
                    room["updated_at"] = time.time()
            self._persist()
            return msg

        with ThreadPoolExecutor(max_workers=max(1, len(advisors))) as pool:
            futures = [pool.submit(run_single_advisor, adv) for adv in advisors]
            for fut in as_completed(futures):
                try:
                    stage1_results.append(fut.result())
                except Exception as e:
                    print(f"[council] Stage 1 error: {e}")

        adv_order = {a["id"]: idx for idx, a in enumerate(advisors)}
        stage1_results.sort(key=lambda x: adv_order.get(x["advisor_id"], 99))

        # STAGE 2: ANONYMOUS PEER REVIEW ROUND
        peer_reviews: List[dict] = []
        if do_peer_review and len(stage1_results) >= 2:
            with self.lock:
                room["status"] = "stage2_peer_review"
                room["status_detail"] = "Stage 2: Advisors conducting anonymous peer review (Responses A–E)…"
                if "progress" in room:
                    room["progress"]["stage"] = 2
                    room["progress"]["stage_name"] = "Stage 2: Anonymous Peer Review (Responses A–E)"
                    room["progress"]["completed_reviewers"] = 0
                    for adv in advisors:
                        if adv["id"] in room["progress"].get("advisors", {}):
                            room["progress"]["advisors"][adv["id"]]["peer_status"] = "thinking"
                            room["progress"]["advisors"][adv["id"]]["peer_action"] = "Reading & critiquing anonymized takes A–E…"
                    room["updated_at"] = time.time()
            self._persist()

            shuffled = list(stage1_results)
            random.shuffle(shuffled)
            letters = [chr(ord("A") + i) for i in range(len(shuffled))]
            anon_blocks = []
            letter_to_advisor = {}
            for letter, item in zip(letters, shuffled):
                anon_blocks.append(f"**Response {letter}:**\n{item['content']}")
                letter_to_advisor[f"Response {letter}"] = item["sender"]

            joined_anon = "\n\n".join(anon_blocks)
            key_legend = ", ".join([f"{k} = {v}" for k, v in letter_to_advisor.items()])

            def run_single_reviewer(adv: dict) -> dict:
                t0 = time.time()
                rev_prompt = (
                    f"You are {adv['name']} ({adv['prompt']}), reviewing the anonymized outputs of an LLM Council.\n"
                    f"The advisors independently answered this question:\n"
                    f"---\n{question}\n---\n\n"
                    f"Here are their anonymized responses:\n\n{joined_anon}\n\n"
                    f"Answer these three questions directly and concisely (reference responses by letter A, B, C, etc.):\n"
                    f"1. **Strongest Response**: Which response is the strongest and why?\n"
                    f"2. **Biggest Blind Spot**: Which response has the biggest blind spot and what is it missing?\n"
                    f"3. **What Everyone Missed**: What did ALL responses miss that the council must consider?\n\n"
                    f"Keep your peer review under 160 words. Be incisive."
                )
                ans, effective_model = query_llm_with_cascade(
                    prompt=rev_prompt,
                    model=adv.get("model", "gemini-3.8-flash-medium"),
                    use_openrouter=bool(adv.get("use_openrouter", False)),
                    cfg=self.cfg,
                )
                elapsed = round(time.time() - t0, 1)
                msg = {
                    "id": uuid.uuid4().hex[:8],
                    "type": "peer_review",
                    "stage": 2,
                    "advisor_id": adv["id"],
                    "sender": f"{adv['name']} (Peer Review)",
                    "icon": adv.get("icon", "🧠"),
                    "color": adv.get("color", "#00d2ff"),
                    "model": effective_model,
                    "anon_legend": key_legend,
                    "content": ans,
                    "elapsed_sec": elapsed,
                    "timestamp": time.time(),
                }
                self._append_msg(room_id, msg)

                with self.lock:
                    if "progress" in room and "advisors" in room["progress"] and adv["id"] in room["progress"]["advisors"]:
                        room["progress"]["advisors"][adv["id"]]["peer_status"] = "done"
                        room["progress"]["advisors"][adv["id"]]["peer_action"] = f"Critique complete in {elapsed}s"
                        room["progress"]["advisors"][adv["id"]]["peer_elapsed"] = elapsed
                        done_rev = len([m for m in room["messages"] if m.get("type") == "peer_review"])
                        room["progress"]["completed_reviewers"] = done_rev
                        room["status_detail"] = f"Stage 2: {done_rev}/{len(advisors)} peer reviews complete"
                        room["updated_at"] = time.time()
                self._persist()
                return msg

            with ThreadPoolExecutor(max_workers=max(1, len(advisors))) as pool:
                futures = [pool.submit(run_single_reviewer, adv) for adv in advisors]
                for fut in as_completed(futures):
                    try:
                        peer_reviews.append(fut.result())
                    except Exception as e:
                        print(f"[council] Stage 2 error: {e}")

        # STAGE 3: CHAIRMAN SYNTHESIS & FINAL VERDICT
        chairman_model = self.cfg["council"].get("chairman_model", "claude-opus-5-5-high")
        chairman_use_or = bool(self.cfg["council"].get("chairman_use_openrouter", False))
        with self.lock:
            room["status"] = "stage3_synthesizing"
            room["status_detail"] = f"Stage 3: Chairman ({chairman_model}) synthesizing final verdict…"
            if "progress" in room:
                room["progress"]["stage"] = 3
                room["progress"]["stage_name"] = "Stage 3: Chairman Synthesis & Final Verdict"
                if "chairman" in room["progress"]:
                    room["progress"]["chairman"]["status"] = "synthesizing"
                    room["progress"]["chairman"]["action"] = f"Synthesizing consensus, clashes, and recommendation ({chairman_model})…"
                room["updated_at"] = time.time()
        self._persist()

        t0 = time.time()
        deanon_takes = "\n\n".join(
            [f"**{r['sender']} ({r['model']}):**\n{r['content']}" for r in stage1_results]
        )
        reviews_text = (
            "\n\n".join(
                [
                    f"**Review by {r['sender']} [Key: {r.get('anon_legend', '')}]:**\n{r['content']}"
                    for r in peer_reviews
                ]
            )
            if peer_reviews
            else "Peer review round skipped."
        )

        chairman_prompt = (
            f"You are the Chairman of an LLM Council. Your job is to synthesize the independent analyses "
            f"of {len(stage1_results)} advisors and their peer reviews into a decisive, high-clarity final verdict.\n\n"
            f"The question brought to the council:\n"
            f"---\n{question}\n---\n\n"
            f"ADVISOR RESPONSES:\n{deanon_takes}\n\n"
            f"PEER REVIEWS:\n{reviews_text}\n\n"
            f"Produce the council verdict using this exact structure:\n\n"
            f"## 1. Where the Council Agrees\n"
            f"[Points multiple advisors converged on independently. High-confidence signals.]\n\n"
            f"## 2. Where the Council Clashes\n"
            f"[Genuine disagreements. Present both sides clearly and explain why reasonable perspectives clash here.]\n\n"
            f"## 3. Blind Spots the Council Caught\n"
            f"[Crucial insights that emerged from contrasting perspectives or peer review.]\n\n"
            f"## 4. The Chairman's Recommendation\n"
            f"[A clear, direct, actionable recommendation. Do NOT say 'it depends'. Take a definitive stance backed by reasoning. You may side with a single dissenter if their logic is strongest.]\n\n"
            f"## 5. The One Thing to Do First\n"
            f"[A single concrete, immediate next step the user should take Monday morning.]\n\n"
            f"Be direct, authoritative, and concrete."
        )
        verdict_text, effective_chair_model = query_llm_with_cascade(
            prompt=chairman_prompt,
            model=chairman_model,
            use_openrouter=chairman_use_or,
            cfg=self.cfg,
            timeout=120,
        )
        elapsed = round(time.time() - t0, 1)

        verdict_msg = {
            "id": uuid.uuid4().hex[:8],
            "type": "chairman_verdict",
            "stage": 3,
            "sender": "THE CHAIRMAN // FINAL VERDICT",
            "icon": "⚖️",
            "color": "#00e676",
            "model": effective_chair_model,
            "content": verdict_text,
            "elapsed_sec": elapsed,
            "timestamp": time.time(),
        }
        self._append_msg(room_id, verdict_msg)

        with self.lock:
            room["status"] = "completed"
            room["status_detail"] = "Verdict delivered — Ready for follow-up debate"
            if "progress" in room:
                room["progress"]["stage"] = 4
                room["progress"]["stage_name"] = "Deliberation Completed"
                if "chairman" in room["progress"]:
                    room["progress"]["chairman"]["status"] = "done"
                    room["progress"]["chairman"]["elapsed"] = elapsed
                    room["progress"]["chairman"]["action"] = f"Final verdict delivered in {elapsed}s"
            room["updated_at"] = time.time()
        self._persist()
