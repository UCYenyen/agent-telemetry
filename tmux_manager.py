"""Tmux process and session manager."""

import subprocess
import shutil

TMUX_BIN = shutil.which("tmux") or "/usr/bin/tmux" or "/var/lib/snapd/hostfs/usr/bin/tmux"


def run_tmux(*args, check=False) -> subprocess.CompletedProcess:
    cmd = [TMUX_BIN] + list(args)
    try:
        return subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, check=check)
    except FileNotFoundError:
        return subprocess.CompletedProcess(args=cmd, returncode=127, stdout="", stderr="tmux not found")


def list_sessions():
    """Returns list of active tmux session names."""
    res = run_tmux("list-sessions", "-F", "#{session_name}")
    if res.returncode != 0:
        return []
    return [s.strip() for s in res.stdout.splitlines() if s.strip()]


def has_session(name: str) -> bool:
    res = run_tmux("has-session", "-t", name)
    return res.returncode == 0


def new_session(name: str, cmd: str, cwd: str = "/root"):
    """Spawns a new detached tmux session running the given command."""
    if has_session(name):
        return False
    # Create session with bash shell, then send command
    res = run_tmux("new-session", "-d", "-s", name, "-n", name, "-c", cwd, "bash")
    if res.returncode != 0:
        return False
    # Send the startup command
    send_keys(name, cmd, enter=True)
    return True


def send_keys(name: str, text: str, enter: bool = True):
    """Sends text or keystrokes to a tmux session."""
    args = ["send-keys", "-t", name, text]
    if enter:
        args.append("Enter")
    res = run_tmux(*args)
    return res.returncode == 0


def capture_pane(name: str, lines: int = 150) -> str:
    """Captures the visible terminal output of a pane."""
    res = run_tmux("capture-pane", "-p", "-t", name, "-S", f"-{lines}")
    if res.returncode != 0:
        return ""
    return res.stdout


def kill_session(name: str):
    """Kills a tmux session."""
    if not has_session(name):
        return True
    res = run_tmux("kill-session", "-t", name)
    return res.returncode == 0
