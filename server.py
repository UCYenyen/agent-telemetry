#!/usr/bin/env python3
"""Multi-Agent Host Server: Web UI and API for Claude, Antigravity, OpenRouter & LLM Council."""

import os
import sys
import json
import time
import threading
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs

import config
import openrouter_client
from agent_controller import SessionManager
from council_engine import CouncilManager

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
WEB_DIR = os.path.join(BASE_DIR, "web")

cfg = config.load_config()
mgr = SessionManager(cfg)
council_mgr = CouncilManager(cfg)


def background_monitor():
    """Background polling loop for agent states and 3-stage limit fallback."""
    while True:
        try:
            mgr.sync_existing_tmux_sessions()
            mgr.update_states()
        except Exception as e:
            print(f"[monitor] Error updating states: {e}")
        time.sleep(3.0)


class AgentHostHandler(BaseHTTPRequestHandler):
    def log_message(self, format, *args):
        pass

    def send_json(self, status: int, data: dict):
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Access-Control-Allow-Origin", "*")
        self.end_headers()
        self.wfile.write(json.dumps(data).encode("utf-8"))

    def do_GET(self):
        url = urlparse(self.path)
        path = url.path

        if path == "/api/state":
            mgr.update_states()
            sessions_data = {k: v.to_dict() for k, v in mgr.sessions.items()}
            or_cfg = cfg.get("openrouter", {})
            raw_key = or_cfg.get("api_key", "")
            masked_key = (raw_key[:9] + "••••••••" + raw_key[-4:]) if len(raw_key) > 14 else ("••••" if raw_key else "")

            raw_fb_list = cfg.get("fallback_list", [])
            safe_fb_list = []
            for item in raw_fb_list:
                item_key = (item.get("api_key") or "").strip()
                eff_key = item_key or raw_key.strip()
                item_masked = (eff_key[:9] + "••••" + eff_key[-4:]) if len(eff_key) > 14 else ("••••" if eff_key else "")
                safe_fb_list.append({
                    "id": item.get("id", ""),
                    "provider": item.get("provider", "antigravity"),
                    "model": item.get("model", ""),
                    "has_own_key": bool(item_key),
                    "has_key": bool(eff_key),
                    "masked_key": item_masked,
                })

            return self.send_json(200, {
                "ok": True,
                "data": {
                    "sessions": sessions_data,
                    "stopped": mgr.stopped_conversations,
                    "defaults": cfg.get("defaults", {}),
                    "models": cfg.get("models", {}),
                    "fallback_list": safe_fb_list,
                    "openrouter": {
                        "has_key": bool(raw_key.strip()) or any(x["has_key"] for x in safe_fb_list),
                        "masked_key": masked_key,
                        "enabled": bool(or_cfg.get("enabled", True)),
                        "default_model": or_cfg.get("default_model", "anthropic/claude-3.7-sonnet"),
                        "fallback_models": or_cfg.get("fallback_models", [])
                    },
                    "council": council_mgr.get_state()
                },
                "updated": time.time()
            })

        if path == "/api/history":
            qs = parse_qs(url.query)
            session_name = qs.get("session", [""])[0]
            if not session_name:
                return self.send_json(400, {"ok": False, "error": "Missing session parameter"})
            history, err = mgr.get_history(session_name)
            if err:
                return self.send_json(404, {"ok": False, "error": err})
            return self.send_json(200, {"ok": True, "data": history})

        if path == "/api/council/room":
            qs = parse_qs(url.query)
            room_id = qs.get("id", [""])[0]
            room = council_mgr.get_room(room_id)
            if not room:
                return self.send_json(404, {"ok": False, "error": "Room not found"})
            return self.send_json(200, {"ok": True, "data": room})

        if path == "/" or path == "/index.html":
            file_path = os.path.join(WEB_DIR, "index.html")
            if os.path.exists(file_path):
                self.send_response(200)
                self.send_header("Content-Type", "text/html; charset=utf-8")
                self.end_headers()
                with open(file_path, "rb") as f:
                    self.wfile.write(f.read())
                return

        self.send_error(404, "Not Found")

    def do_POST(self):
        url = urlparse(self.path)
        path = url.path

        if path == "/api/action":
            length = int(self.headers.get("Content-Length", 0))
            body = self.rfile.read(length).decode("utf-8") if length > 0 else "{}"
            try:
                payload = json.loads(body)
            except Exception:
                return self.send_json(400, {"ok": False, "error": "Invalid JSON"})

            op = payload.get("op")

            if op == "new":
                title = payload.get("title", "Untitled Session")
                agent = payload.get("agent", "auto")
                model = payload.get("model")
                workdir = payload.get("workdir", "/root")
                fallback = payload.get("fallback", True)
                use_openrouter = bool(payload.get("use_openrouter", False))

                session, err = mgr.start_session(
                    title=title,
                    agent=agent,
                    model=model,
                    workdir=workdir,
                    fallback=fallback,
                    use_openrouter=use_openrouter,
                )
                if err:
                    return self.send_json(500, {"ok": False, "error": err})
                return self.send_json(200, {"ok": True, "session": session.to_dict()})

            elif op == "prompt":
                session_name = payload.get("session")
                text = payload.get("text", "")
                model = payload.get("model")
                ok, err = mgr.send_prompt(session_name, text, model=model)
                if not ok:
                    return self.send_json(500, {"ok": False, "error": err or "Failed to send prompt"})
                return self.send_json(200, {"ok": True})

            elif op == "switch_model":
                session_name = payload.get("session")
                model = payload.get("model")
                ok, err = mgr.switch_model(session_name, model)
                if not ok:
                    return self.send_json(500, {"ok": False, "error": err or "Failed to switch model"})
                return self.send_json(200, {"ok": True, "model": model})

            elif op == "toggle_openrouter":
                session_name = payload.get("session")
                enabled = payload.get("enabled")
                ok, err = mgr.toggle_openrouter(session_name, enabled)
                if not ok:
                    return self.send_json(500, {"ok": False, "error": err or "Failed to toggle OpenRouter"})
                return self.send_json(200, {"ok": True})

            elif op == "clear_history":
                session_name = payload.get("session")
                ok, err = mgr.clear_history(session_name)
                return self.send_json(200, {"ok": True})

            elif op == "toggle_fallback":
                session_name = payload.get("session")
                enabled = payload.get("enabled")
                ok, err = mgr.toggle_fallback(session_name, enabled)
                if not ok:
                    return self.send_json(500, {"ok": False, "error": err or "Failed to toggle fallback"})
                return self.send_json(200, {"ok": True})

            elif op == "stop":
                session_name = payload.get("session")
                ok, err = mgr.stop_session(session_name)
                if not ok:
                    return self.send_json(500, {"ok": False, "error": err or "Failed to stop session"})
                return self.send_json(200, {"ok": True})

            elif op == "resume":
                conv_id = payload.get("conv")
                session, err = mgr.resume_session(conv_id)
                if err:
                    return self.send_json(500, {"ok": False, "error": err})
                return self.send_json(200, {"ok": True, "session": session.to_dict()})

            elif op == "defaults":
                defs = cfg.setdefault("defaults", {})
                for k in [
                    "agent",
                    "claude_model",
                    "antigravity_model",
                    "openrouter_model",
                    "openrouter_secondary_model",
                    "workdir",
                    "fallback_enabled",
                    "openrouter_fallback_enabled",
                    "use_openrouter",
                ]:
                    if k in payload:
                        defs[k] = payload[k]

                or_cfg = cfg.setdefault("openrouter", {})
                if "openrouter_api_key" in payload:
                    key_val = payload["openrouter_api_key"].strip()
                    # Only overwrite if user pasted a new key (not empty/masked placeholder)
                    if key_val and "••••" not in key_val:
                        or_cfg["api_key"] = key_val
                    elif key_val == "__CLEAR__":
                        or_cfg["api_key"] = ""
                if "openrouter_model" in payload:
                    or_cfg["default_model"] = payload["openrouter_model"]
                if "openrouter_fallback_models" in payload and isinstance(payload["openrouter_fallback_models"], list):
                    or_cfg["fallback_models"] = [m.strip() for m in payload["openrouter_fallback_models"] if m.strip()]

                config.save_config(cfg)
                return self.send_json(200, {"ok": True, "defaults": defs})

            elif op == "openrouter_sync_models":
                api_key = cfg.get("openrouter", {}).get("api_key", "")
                models = openrouter_client.fetch_openrouter_models(api_key)
                cfg.setdefault("models", {})["openrouter"] = models
                config.save_config(cfg)
                return self.send_json(200, {"ok": True, "models": models})

            elif op == "fallback_add":
                import uuid
                model_val = (payload.get("model") or "").strip()
                provider_val = (payload.get("provider") or "").strip()
                api_key_val = (payload.get("api_key") or "").strip()

                if "::" in model_val and not provider_val:
                    provider_val, model_val = model_val.split("::", 1)
                if not provider_val:
                    if "/" in model_val:
                        provider_val = "openrouter"
                    elif "gemini" in model_val.lower() or "oss" in model_val.lower():
                        provider_val = "antigravity"
                    else:
                        provider_val = "claude"

                if not model_val:
                    return self.send_json(400, {"ok": False, "error": "Model is required"})

                fb_list = cfg.setdefault("fallback_list", [])
                new_item = {
                    "id": f"fb_{uuid.uuid4().hex[:8]}",
                    "provider": provider_val,
                    "model": model_val,
                    "api_key": api_key_val if (api_key_val and "••••" not in api_key_val) else "",
                }
                fb_list.append(new_item)

                # If user provided an API key here, also store it in openrouter config so all OpenRouter calls have a key
                if new_item["api_key"]:
                    cfg.setdefault("openrouter", {})["api_key"] = new_item["api_key"]

                # Sync openrouter.fallback_models & defaults
                or_models = [x["model"] for x in fb_list if x.get("provider") == "openrouter"]
                if or_models:
                    cfg.setdefault("openrouter", {})["fallback_models"] = or_models
                    cfg.setdefault("defaults", {})["openrouter_model"] = or_models[0]
                agy_models = [x["model"] for x in fb_list if x.get("provider") == "antigravity"]
                if agy_models:
                    cfg.setdefault("defaults", {})["antigravity_model"] = agy_models[0]

                config.save_config(cfg)
                return self.send_json(200, {"ok": True, "item": new_item})

            elif op == "fallback_remove":
                fb_id = payload.get("id", "")
                fb_list = cfg.setdefault("fallback_list", [])
                cfg["fallback_list"] = [x for x in fb_list if x.get("id") != fb_id]
                config.save_config(cfg)
                return self.send_json(200, {"ok": True})

            elif op == "fallback_move":
                fb_id = payload.get("id", "")
                direction = payload.get("direction", "up")
                fb_list = cfg.setdefault("fallback_list", [])
                idx = next((i for i, x in enumerate(fb_list) if x.get("id") == fb_id), -1)
                if idx != -1:
                    swap_idx = idx - 1 if direction == "up" else idx + 1
                    if 0 <= swap_idx < len(fb_list):
                        fb_list[idx], fb_list[swap_idx] = fb_list[swap_idx], fb_list[idx]
                        config.save_config(cfg)
                return self.send_json(200, {"ok": True})

            # ----------------------------------------------------------
            # LLM Council & Autonomous Group Chat Operations
            # ----------------------------------------------------------
            elif op == "council_ask":
                question = payload.get("question", "").strip()
                room_id = payload.get("room_id")
                enable_peer_review = payload.get("enable_peer_review")
                if not question:
                    return self.send_json(400, {"ok": False, "error": "Question cannot be empty"})
                try:
                    room = council_mgr.start_or_continue_council(
                        question=question,
                        room_id=room_id,
                        enable_peer_review=enable_peer_review
                    )
                    return self.send_json(200, {"ok": True, "room": room})
                except Exception as e:
                    return self.send_json(400, {"ok": False, "error": str(e)})

            elif op == "council_add_advisor":
                name = payload.get("name", "Custom Advisor")
                prompt = payload.get("prompt", "")
                model = payload.get("model", "gemini-3.8-flash-medium")
                icon = payload.get("icon", "🧠")
                color = payload.get("color", "#a855f7")
                use_openrouter = bool(payload.get("use_openrouter", False))
                adv = council_mgr.add_advisor(
                    name=name,
                    prompt=prompt,
                    model=model,
                    icon=icon,
                    color=color,
                    use_openrouter=use_openrouter,
                )
                return self.send_json(200, {"ok": True, "advisor": adv})

            elif op == "council_update_advisor":
                adv_id = payload.get("id")
                adv = council_mgr.update_advisor(adv_id, payload)
                if not adv:
                    return self.send_json(404, {"ok": False, "error": "Advisor not found"})
                return self.send_json(200, {"ok": True, "advisor": adv})

            elif op == "council_delete_advisor":
                adv_id = payload.get("id")
                ok = council_mgr.delete_advisor(adv_id)
                if not ok:
                    return self.send_json(404, {"ok": False, "error": "Advisor not found"})
                return self.send_json(200, {"ok": True})

            elif op == "council_reset_advisors":
                advisors = council_mgr.reset_advisors()
                return self.send_json(200, {"ok": True, "advisors": advisors})

            elif op == "council_settings":
                res = council_mgr.update_settings(
                    chairman_model=payload.get("chairman_model"),
                    chairman_use_openrouter=payload.get("chairman_use_openrouter"),
                    enable_peer_review=payload.get("enable_peer_review"),
                )
                return self.send_json(200, {"ok": True, "settings": res})

            elif op == "council_delete_room":
                room_id = payload.get("room_id")
                council_mgr.delete_room(room_id)
                return self.send_json(200, {"ok": True})

            return self.send_json(400, {"ok": False, "error": f"Unknown op: {op}"})

        self.send_error(404, "Not Found")


def run():
    port = int(cfg.get("port", 3116))
    host = cfg.get("host", "0.0.0.0")

    t = threading.Thread(target=background_monitor, daemon=True)
    t.start()

    server = ThreadingHTTPServer((host, port), AgentHostHandler)
    print(f"[agent-host] Server running on http://{host}:{port}/")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\n[agent-host] Shutting down...")
        server.server_close()


if __name__ == "__main__":
    run()
