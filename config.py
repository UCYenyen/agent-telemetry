"""Configuration and persistent settings for Agent Host & LLM Council."""

import os
import json
import hashlib
import secrets
import copy
from openrouter_client import DEFAULT_OPENROUTER_MODELS

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_FILE = os.path.join(BASE_DIR, "config.json")

DEFAULT_ADVISORS = [
    {
        "id": "contrarian",
        "name": "The Contrarian",
        "icon": "⚔️",
        "color": "#ff3366",
        "model": "claude-opus-5-5-medium",
        "use_openrouter": False,
        "enabled": True,
        "built_in": True,
        "prompt": (
            "Actively looks for what's wrong, what's missing, what will fail. Assumes the idea has a fatal flaw "
            "and tries to find it. If everything looks solid, digs deeper. You are not a pessimist — you are the "
            "friend who saves the user from a bad deal by asking the hard questions they are avoiding."
        )
    },
    {
        "id": "first_principles",
        "name": "The First Principles Thinker",
        "icon": "🧬",
        "color": "#00d2ff",
        "model": "gemini-4-argon-high",
        "use_openrouter": False,
        "enabled": True,
        "built_in": True,
        "prompt": (
            "Ignores the surface-level question and asks 'what are we actually trying to solve here?' Strips away "
            "assumptions and rebuilds the problem from the ground up. Call out if the user is asking the wrong "
            "question entirely."
        )
    },
    {
        "id": "expansionist",
        "name": "The Expansionist",
        "icon": "🚀",
        "color": "#00e676",
        "model": "gemini-3.8-flash-high",
        "use_openrouter": False,
        "enabled": True,
        "built_in": True,
        "prompt": (
            "Looks for upside everyone else is missing. What could be 10x bigger? What adjacent opportunity is "
            "hiding? What is being undervalued? Ignore downside risk (that is the Contrarian's job) and focus "
            "purely on what happens if this works even better than expected."
        )
    },
    {
        "id": "outsider",
        "name": "The Outsider",
        "icon": "👁️",
        "color": "#ffb300",
        "model": "gpt-oss-120b-medium",
        "use_openrouter": False,
        "enabled": True,
        "built_in": True,
        "prompt": (
            "Has zero context about the user's jargon, industry insiders, or history. Responds purely to what is "
            "in front of them. Catches expert blind spots and the curse of knowledge: things that seem obvious to "
            "insiders but are confusing or unconvincing to normal people."
        )
    },
    {
        "id": "executor",
        "name": "The Executor",
        "icon": "⚡",
        "color": "#ff5722",
        "model": "claude-sonnet-5-5-high",
        "use_openrouter": False,
        "enabled": True,
        "built_in": True,
        "prompt": (
            "Only cares about one thing: can this actually be done, and what is the fastest path to doing it? "
            "Ignores abstract theory and strategy. Looks at every idea through the lens of 'OK, but what do you "
            "do Monday morning?' If an idea has no concrete first step, say so."
        )
    }
]

DEFAULT_CONFIG = {
    "port": 3116,
    "host": "0.0.0.0",
    "password_hash": "",
    "session_secret": secrets.token_hex(32),
    "openrouter": {
        "api_key": "",
        "enabled": True,
        "default_model": "anthropic/claude-3.7-sonnet",
        "fallback_models": [
            "deepseek/deepseek-r1",
            "google/gemini-2.5-pro-preview",
            "openai/gpt-4.1"
        ]
    },
    "defaults": {
        "agent": "auto",
        "claude_model": "claude-sonnet-5-5-medium",
        "antigravity_model": "gemini-3.8-flash-medium",
        "openrouter_model": "anthropic/claude-3.7-sonnet",
        "openrouter_secondary_model": "deepseek/deepseek-r1",
        "use_openrouter": False,
        "claude_effort": "medium",
        "permission_mode": "auto",
        "autocompact": "auto",
        "workdir": "/root",
        "title_prefix": "",
        "fallback_enabled": True,
        "openrouter_fallback_enabled": True,
        "fallback_agent": "antigravity",
        "fallback_model": "gemini-3.8-flash-medium",
        "fallback_chain": ["claude", "antigravity", "openrouter"]
    },
    "models": {
        "claude": [
            "claude-opus-5-5-high",
            "claude-opus-5-5-medium",
            "claude-opus-5-5-low",
            "claude-sonnet-5-5-high",
            "claude-sonnet-5-5-medium",
            "claude-sonnet-5-5-low",
            "claude-haiku-4-5"
        ],
        "antigravity": [
            "gemini-4-argon-high",
            "gemini-4-argon-medium",
            "gemini-4-argon-low",
            "gemini-3.8-flash-high",
            "gemini-3.8-flash-medium",
            "gemini-3.8-flash-low",
            "gpt-oss-120b-medium"
        ],
        "openrouter": list(DEFAULT_OPENROUTER_MODELS)
    },
    "fallback_list": [
        {
            "id": "fb_default_agy",
            "provider": "antigravity",
            "model": "gemini-3.8-flash-medium",
            "api_key": ""
        },
        {
            "id": "fb_default_or1",
            "provider": "openrouter",
            "model": "anthropic/claude-3.7-sonnet",
            "api_key": ""
        },
        {
            "id": "fb_default_or2",
            "provider": "openrouter",
            "model": "deepseek/deepseek-r1",
            "api_key": ""
        }
    ],
    "council": {
        "chairman_model": "claude-opus-5-5-high",
        "chairman_use_openrouter": False,
        "enable_peer_review": True,
        "advisors": copy.deepcopy(DEFAULT_ADVISORS),
        "rooms": []
    },
    "saved_sessions": []
}


def load_config():
    if not os.path.exists(CONFIG_FILE):
        save_config(DEFAULT_CONFIG)
        return copy.deepcopy(DEFAULT_CONFIG)
    try:
        with open(CONFIG_FILE, "r", encoding="utf-8") as f:
            data = json.load(f)
            for k, v in DEFAULT_CONFIG.items():
                if k not in data:
                    data[k] = copy.deepcopy(v)
            # Ensure nested defaults/models/openrouter keys exist
            for k, v in DEFAULT_CONFIG["defaults"].items():
                data["defaults"].setdefault(k, copy.deepcopy(v))
            for k, v in DEFAULT_CONFIG["openrouter"].items():
                data["openrouter"].setdefault(k, copy.deepcopy(v))
            for k, v in DEFAULT_CONFIG["models"].items():
                if k not in data["models"] or not data["models"][k]:
                    data["models"][k] = copy.deepcopy(v)
            if "fallback_list" not in data or not isinstance(data["fallback_list"], list):
                data["fallback_list"] = copy.deepcopy(DEFAULT_CONFIG["fallback_list"])
            if "council" not in data or not data["council"].get("advisors"):
                data["council"] = copy.deepcopy(DEFAULT_CONFIG["council"])
            else:
                data["council"].setdefault("chairman_use_openrouter", False)
                for adv in data["council"].get("advisors", []):
                    adv.setdefault("use_openrouter", False)
            return data
    except Exception as e:
        print(f"[config] Failed to load config, using defaults: {e}")
        return copy.deepcopy(DEFAULT_CONFIG)


def save_config(cfg):
    with open(CONFIG_FILE, "w", encoding="utf-8") as f:
        json.dump(cfg, f, indent=2)


def hash_password(pwd: str) -> str:
    return hashlib.sha256(pwd.encode("utf-8")).hexdigest()


def verify_password(pwd: str, stored_hash: str) -> bool:
    if not stored_hash:
        return True
    return hash_password(pwd) == stored_hash
