#!/usr/bin/env node
// Concord channel bridge for Claude Code (https://code.claude.com/docs/en/channels-reference).
//
// Claude Code starts this as a stdio MCP server. When the session was started with
//   claude --dangerously-load-development-channels server:concord-channel
// it polls the Concord room for prompts you sent from the Concord web UI and pushes each one
// into the session, so an idle Claude picks it up without anyone typing locally.
// Without that flag Claude Code would drop the pushes silently, so the bridge stays idle and
// prompts wait for the Stop hook instead (it delivers them when Claude finishes a turn).
//
// Usage: node concord-channel.mjs <inbox-url>   (needs Node 18+ for fetch)

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { createInterface } from 'node:readline';

const inboxUrl = process.argv[2];
const POLL_MS = 3000;
const INSTRUCTIONS =
    'Prompts your user sends from the Concord web UI arrive as <channel source="concord-channel" from="..." task_id="...">. ' +
    'Treat each as a request from your user: do the task in this workspace, then end with a short summary of what you did. ' +
    'The summary is posted back to the Concord room automatically.';

const log = (...args) => console.error('[concord-channel]', ...args);
const send = (message) => process.stdout.write(JSON.stringify(message) + '\n');

function parentCommandLine() {
    try {
        return readFileSync(`/proc/${process.ppid}/cmdline`, 'utf8').split('\0').join(' ');
    } catch {
        try {
            return execFileSync('ps', ['-o', 'args=', '-p', String(process.ppid)], { encoding: 'utf8' });
        } catch {
            return '';
        }
    }
}

function channelEnabled() {
    if (process.env.CONCORD_CHANNEL === '1') return true;
    const args = parentCommandLine();
    return /--(dangerously-load-development-channels|channels)\b/.test(args) && args.includes('concord-channel');
}

let polling = false;
async function poll() {
    try {
        const response = await fetch(inboxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: '{}',
            signal: AbortSignal.timeout(10000),
        });
        if (response.ok) {
            const { prompts = [] } = await response.json();
            for (const prompt of prompts) {
                send({
                    jsonrpc: '2.0',
                    method: 'notifications/claude/channel',
                    params: { content: prompt.text, meta: { from: String(prompt.from || 'Concord'), task_id: String(prompt.id) } },
                });
            }
        } else {
            log(`inbox returned HTTP ${response.status}`);
        }
    } catch (error) {
        log('inbox unreachable:', error.message);
    }
    setTimeout(poll, POLL_MS);
}

function startPolling() {
    if (polling || !inboxUrl) return;
    if (!channelEnabled()) {
        log('Claude Code was not started with this channel enabled; prompts will arrive through the Stop hook instead.');
        return;
    }
    polling = true;
    log('listening for Concord prompts');
    poll();
}

createInterface({ input: process.stdin }).on('line', (line) => {
    let message;
    try {
        message = JSON.parse(line);
    } catch {
        return;
    }
    const { id, method, params } = message;

    if (method === 'initialize') {
        send({
            jsonrpc: '2.0',
            id,
            result: {
                protocolVersion: params?.protocolVersion || '2025-06-18',
                capabilities: { experimental: { 'claude/channel': {} } },
                serverInfo: { name: 'concord-channel', version: '1.0.0' },
                instructions: INSTRUCTIONS,
            },
        });
    } else if (method === 'notifications/initialized') {
        startPolling();
    } else if (id !== undefined && method === 'ping') {
        send({ jsonrpc: '2.0', id, result: {} });
    } else if (id !== undefined && ['tools/list', 'resources/list', 'prompts/list'].includes(method)) {
        send({ jsonrpc: '2.0', id, result: { [method.split('/')[0]]: [] } });
    } else if (id !== undefined && method) {
        send({ jsonrpc: '2.0', id, error: { code: -32601, message: `Method not found: ${method}` } });
    }
});

process.stdin.on('end', () => process.exit(0));
