"""OpenRouter API Client for Agent Host & LLM Council.

Supports:
- Direct OpenRouter Chat Completions API calls (https://openrouter.ai/api/v1/chat/completions)
- Multi-model fallback list if a primary OpenRouter model is rate-limited or unavailable
- Fetching live available models from OpenRouter API
"""

import json
import urllib.request
import urllib.error
from typing import List, Dict, Optional, Tuple

OPENROUTER_CHAT_URL = "https://openrouter.ai/api/v1/chat/completions"
OPENROUTER_MODELS_URL = "https://openrouter.ai/api/v1/models"

DEFAULT_OPENROUTER_MODELS = [
    "anthropic/claude-3.7-sonnet",
    "anthropic/claude-3.5-sonnet",
    "anthropic/claude-3.5-haiku",
    "google/gemini-2.5-pro-preview",
    "google/gemini-2.5-flash-preview",
    "google/gemini-2.0-flash-001",
    "openai/gpt-4.1",
    "openai/gpt-4o",
    "openai/o3-mini",
    "deepseek/deepseek-r1",
    "deepseek/deepseek-chat-v3-0324",
    "meta-llama/llama-4-maverick",
    "meta-llama/llama-3.3-70b-instruct",
    "qwen/qwen-2.5-coder-32b-instruct",
    "x-ai/grok-3-beta",
    "mistralai/mistral-large-2411"
]


def call_openrouter(
    api_key: str,
    model: str,
    messages: List[Dict[str, str]],
    fallback_models: Optional[List[str]] = None,
    timeout: int = 90,
) -> Tuple[bool, str, str]:
    """Calls OpenRouter Chat Completions API.

    Returns:
        (success: bool, response_text: str, model_used: str)
    """
    if not api_key or not api_key.strip():
        return False, "[OpenRouter Error: No OpenRouter API Key configured. Paste your key in Settings.]", model

    clean_key = api_key.strip()
    candidates = [model] if model else []
    if fallback_models:
        for fb in fallback_models:
            if fb and fb not in candidates:
                candidates.append(fb)
    if not candidates:
        candidates = ["openai/gpt-4o-mini"]

    last_err = ""
    for candidate_model in candidates:
        payload = {
            "model": candidate_model,
            "messages": messages,
        }
        data = json.dumps(payload).encode("utf-8")
        req = urllib.request.Request(
            OPENROUTER_CHAT_URL,
            data=data,
            headers={
                "Authorization": f"Bearer {clean_key}",
                "Content-Type": "application/json",
                "HTTP-Referer": "http://localhost:3116",
                "X-Title": "Agent Host Command & LLM Council",
            },
            method="POST",
        )
        try:
            with urllib.request.urlopen(req, timeout=timeout) as resp:
                raw = resp.read().decode("utf-8")
                body = json.loads(raw)
                choices = body.get("choices", [])
                if choices:
                    msg = choices[0].get("message", {})
                    content = (msg.get("content") or "").strip()
                    actual_model = body.get("model") or candidate_model
                    if content:
                        return True, content, actual_model
                last_err = f"Empty response from {candidate_model}"
        except urllib.error.HTTPError as e:
            try:
                err_body = e.read().decode("utf-8")
            except Exception:
                err_body = str(e)
            last_err = f"HTTP {e.code} on {candidate_model}: {err_body[:240]}"
        except Exception as e:
            last_err = f"Error on {candidate_model}: {e}"

    return False, f"[OpenRouter Failed: {last_err}]", candidates[-1]


def fetch_openrouter_models(api_key: str = "", timeout: int = 15) -> List[str]:
    """Fetches available model IDs from OpenRouter API."""
    headers = {"Content-Type": "application/json"}
    if api_key and api_key.strip():
        headers["Authorization"] = f"Bearer {api_key.strip()}"
    req = urllib.request.Request(OPENROUTER_MODELS_URL, headers=headers, method="GET")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            body = json.loads(resp.read().decode("utf-8"))
            data = body.get("data", [])
            ids = [item.get("id") for item in data if item.get("id")]
            if ids:
                # Prioritize DEFAULT_OPENROUTER_MODELS at the top, followed by the rest
                prioritized = [m for m in DEFAULT_OPENROUTER_MODELS if m in ids]
                rest = [m for m in ids if m not in prioritized]
                return prioritized + rest[:150]
    except Exception as e:
        print(f"[openrouter] Model fetch error: {e}")
    return list(DEFAULT_OPENROUTER_MODELS)
