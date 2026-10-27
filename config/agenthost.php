<?php

return [

    'agy_bin' => env('AGY_BIN', 'agy'),

    'defaults' => [
        'agent' => 'auto',
        'claude_model' => 'claude-sonnet-5-5-medium',
        'antigravity_model' => 'gemini-3.8-flash-medium',
        'openrouter_model' => 'anthropic/claude-3.7-sonnet',
        'claude_effort' => 'medium',
        'permission_mode' => 'auto',
        'workdir' => '/root',
        'fallback_enabled' => true,
        'openrouter_fallback_enabled' => true,
    ],

    'openrouter' => [
        'default_model' => 'anthropic/claude-3.7-sonnet',
        'fallback_models' => [
            'deepseek/deepseek-r1',
            'google/gemini-2.5-pro-preview',
            'openai/gpt-4.1',
        ],
    ],

    'models' => [
        'claude' => [
            'claude-opus-5-5-high',
            'claude-opus-5-5-medium',
            'claude-opus-5-5-low',
            'claude-sonnet-5-5-high',
            'claude-sonnet-5-5-medium',
            'claude-sonnet-5-5-low',
            'claude-haiku-4-5',
        ],
        'antigravity' => [
            'gemini-4-argon-high',
            'gemini-4-argon-medium',
            'gemini-4-argon-low',
            'gemini-3.8-flash-high',
            'gemini-3.8-flash-medium',
            'gemini-3.8-flash-low',
            'gpt-oss-120b-medium',
        ],
        'openrouter' => [
            'anthropic/claude-3.7-sonnet',
            'anthropic/claude-3.5-sonnet',
            'anthropic/claude-3.5-haiku',
            'google/gemini-2.5-pro-preview',
            'google/gemini-2.5-flash-preview',
            'google/gemini-2.0-flash-001',
            'openai/gpt-4.1',
            'openai/gpt-4o',
            'openai/o3-mini',
            'deepseek/deepseek-r1',
            'deepseek/deepseek-chat-v3-0324',
            'meta-llama/llama-4-maverick',
            'meta-llama/llama-3.3-70b-instruct',
            'qwen/qwen-2.5-coder-32b-instruct',
            'x-ai/grok-3-beta',
            'mistralai/mistral-large-2411',
        ],
    ],

    'fallbacks' => [
        ['provider' => 'antigravity', 'model' => 'gemini-3.8-flash-medium'],
        ['provider' => 'openrouter', 'model' => 'anthropic/claude-3.7-sonnet'],
        ['provider' => 'openrouter', 'model' => 'deepseek/deepseek-r1'],
    ],

    'council' => [
        'chairman_model' => 'claude-opus-5-5-high',
        'chairman_use_openrouter' => false,
        'enable_peer_review' => true,
    ],

    'advisors' => [
        [
            'name' => 'The Contrarian',
            'icon' => '⚔️',
            'color' => '#ff3366',
            'model' => 'claude-opus-5-5-medium',
            'prompt' => "Actively looks for what's wrong, what's missing, what will fail. Assumes the idea has a fatal flaw and tries to find it. If everything looks solid, digs deeper. You are not a pessimist — you are the friend who saves the user from a bad deal by asking the hard questions they are avoiding.",
        ],
        [
            'name' => 'The First Principles Thinker',
            'icon' => '🧬',
            'color' => '#00d2ff',
            'model' => 'gemini-4-argon-high',
            'prompt' => "Ignores the surface-level question and asks 'what are we actually trying to solve here?' Strips away assumptions and rebuilds the problem from the ground up. Call out if the user is asking the wrong question entirely.",
        ],
        [
            'name' => 'The Expansionist',
            'icon' => '🚀',
            'color' => '#00e676',
            'model' => 'gemini-3.8-flash-high',
            'prompt' => "Looks for upside everyone else is missing. What could be 10x bigger? What adjacent opportunity is hiding? What is being undervalued? Ignore downside risk (that is the Contrarian's job) and focus purely on what happens if this works even better than expected.",
        ],
        [
            'name' => 'The Outsider',
            'icon' => '👁️',
            'color' => '#ffb300',
            'model' => 'gpt-oss-120b-medium',
            'prompt' => "Has zero context about the user's jargon, industry insiders, or history. Responds purely to what is in front of them. Catches expert blind spots and the curse of knowledge: things that seem obvious to insiders but are confusing or unconvincing to normal people.",
        ],
        [
            'name' => 'The Executor',
            'icon' => '⚡',
            'color' => '#ff5722',
            'model' => 'claude-sonnet-5-5-high',
            'prompt' => "Only cares about one thing: can this actually be done, and what is the fastest path to doing it? Ignores abstract theory and strategy. Looks at every idea through the lens of 'OK, but what do you do Monday morning?' If an idea has no concrete first step, say so.",
        ],
    ],

    'model_aliases' => [
        'Gemini 3.8 Flash (Medium)' => 'gemini-3.8-flash-medium',
        'Gemini 3.8 Flash (High)' => 'gemini-3.8-flash-high',
        'Gemini 3.8 Flash (Low)' => 'gemini-3.8-flash-low',
        'Gemini 4 Argon (Medium)' => 'gemini-4-argon-medium',
        'Gemini 4 Argon (High)' => 'gemini-4-argon-high',
        'Gemini 4 Argon (Low)' => 'gemini-4-argon-low',
        'Gemini 3.5 Pro' => 'gemini-4-argon-high',
        'Gemini 3.5 Flash' => 'gemini-3.8-flash-medium',
        'claude-opus-5-5' => 'claude-opus-5-5-medium',
        'claude-opus-5' => 'claude-opus-5-5-medium',
        'claude-sonnet-5-5' => 'claude-sonnet-5-5-medium',
        'claude-sonnet-5' => 'claude-sonnet-5-5-medium',
        'claude-fable-5-1' => 'claude-sonnet-5-5-high',
        'claude-haiku-4-5' => 'claude-sonnet-5-5-low',
    ],

];
