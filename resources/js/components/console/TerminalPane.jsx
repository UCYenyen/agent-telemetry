export default function TerminalPane({ output }) {
    return (
        <div className="ow-console-pane">
            <pre className="ow-terminal-pre">{output || 'Connecting to live PTY stream…'}</pre>
        </div>
    );
}
