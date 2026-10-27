export const AGENTS = {
    claude: { icon: '⚡', label: 'CLAUDE', fleet: 'Anthropic Claude Fleet' },
    antigravity: { icon: '✨', label: 'ANTIGRAVITY', fleet: 'Google Antigravity Fleet' },
    openrouter: { icon: '🌐', label: 'OPENROUTER', fleet: 'OpenRouter API Models' },
};

export function agentKind(session) {
    if (session.use_openrouter || session.agent === 'openrouter') {
        return 'openrouter';
    }

    if (session.agent === 'antigravity' || session.model?.toLowerCase().includes('gemini')) {
        return 'antigravity';
    }

    return 'claude';
}

export function modelsFor(models, useOpenRouter) {
    if (!models) {
        return [];
    }

    return useOpenRouter ? models.openrouter ?? [] : [...(models.claude ?? []), ...(models.antigravity ?? [])];
}

export function modelsForAgent(models, agent) {
    if (agent === 'antigravity' || agent === 'openrouter') {
        return models?.[agent] ?? [];
    }

    return models?.claude ?? [];
}

export function formatTime(value) {
    return value ? new Date(value).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '';
}
