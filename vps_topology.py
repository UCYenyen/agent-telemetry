"""VPS Infrastructure, Services, Ports, and Project Topology Map for Graphify Knowledge Graph."""


class VPSHostInfrastructure:
    """Primary Ubuntu Linux VPS Host Configuration and Runtime Environment."""
    HOSTNAME = "vps-root-node"
    OS = "Ubuntu Linux (x86_64)"
    ROOT_WORKSPACE = "/root"
    WEB_PROJECTS_ROOT = "/var/www/PersonalProject"
    LLM_PROJECT_PATH = "/var/www/PersonalProject/llm/agent-host"

    def get_active_ports(self) -> dict:
        return {
            3115: "claude-host (/root/.claude/skills/claude-host/web/server.py)",
            3116: "agent-host (/root/agent-host/server.py -> /var/www/PersonalProject/llm/agent-host)",
            5432: "PostgreSQL Database Server",
            80: "Nginx Reverse Proxy & Web Server",
            443: "Nginx SSL/TLS Endpoint",
        }


class VPSDockerStack:
    """Active Docker & Containerized Services running on the VPS."""

    def portainer_management(self):
        """Portainer container management UI (/portainer)."""
        return {"service": "portainer", "runtime": "containerd-shim"}

    def nextjs_applications(self):
        """Next.js frontend/fullstack server instances running under user bryan."""
        return {"service": "next-server", "user": "bryan", "instances": 2}

    def database_and_cache_cluster(self):
        """PostgreSQL (2 instances), Redis server, and Soketi WebSocket server."""
        return {
            "postgres": "PostgreSQL 5432",
            "redis": "redis-server",
            "websocket": "soketi-server",
        }

    def messaging_and_workers(self):
        """S6-supervised backend/frontend/nginx stack, Ruby/Rake Sidekiq workers, and WhatsApp service."""
        return {
            "s6_stack": ["nginx", "backend (node)", "frontend"],
            "ruby_workers": "ubuntu rake/ruby background workers",
            "whatsapp_bridge": "whatsapp container service (uid 20001)",
        }


class VPSAgentProjects:
    """AI Agent Projects, MCP Servers, and Skills hosted on this VPS."""

    def agent_host_service(self):
        """Multi-Agent Host & LLM Council War Room on port 3116 with Claude -> Antigravity -> OpenRouter failover."""
        return {
            "path": "/root/agent-host",
            "target_path": "/var/www/PersonalProject/llm/agent-host",
            "port": 3116,
            "systemd_unit": "agent-host.service",
            "features": [
                "OpenWebUI Neural Command Console",
                "LLM Council 5-Advisor Autonomous Group Chat",
                "Per-Agent OpenRouter API Toggle",
                "Dynamic Cascading Fallback Builder (Dropdown + API Key Input + Add Button)",
                "Graphify Knowledge Graph Context Injection",
            ],
        }

    def active_mcp_sessions(self):
        """Active Claude Code & MCP projects on the VPS under /var/www/PersonalProjects."""
        return [
            "CeDX MCP (/var/www/PersonalProjects/cis-mcp/src/index.ts)",
            "UC_Cyber_Security (/var/www/PersonalProjects/e-learn-mcp/src/index.ts)",
            "E-Learn MCP (/var/www/PersonalProjects/e-learn-mcp/src/index.ts)",
            "cedx-booking (/root/cedx-booking/book.mjs)",
        ]

    def cis_mcp_server(self):
        """CIS (Campus Information System / CEdX) MCP Server at /var/www/PersonalProjects/cis-mcp/src/index.ts."""
        return {
            "server_path": "/var/www/PersonalProjects/cis-mcp/src/index.ts",
            "credentials": "/root/.config/cis-mcp/credentials.json",
            "user": "Bryan Fernando Dinata (Student S1, bryankunhahaha@gmail.com)",
            "timezone": "Asia/Jakarta (WIB)",
            "tools": ["cis_login", "cis_status", "cis_room_schedule", "cis_book_room"],
            "rooms": ["Study Room (A225C)", "Study Room (A225B)", "Study Room (A225D)", "Collaboration Room (A225A)"],
            "automation": "/root/cedx-booking/book.mjs",
        }

    def e_learn_mcp_server(self):
        """E-Learn (University LMS) MCP Server at /var/www/PersonalProjects/e-learn-mcp/src/index.ts."""
        return {
            "server_path": "/var/www/PersonalProjects/e-learn-mcp/src/index.ts",
            "browser_mcp": "@playwright/mcp/cli.js --headless --browser chromium --user-data-dir /root/.cache/claude-browser-profile",
            "sessions": ["E-Learn MCP", "UC_Cyber_Security", "CeDX MCP"],
        }

    def installed_skills(self):
        """Custom Antigravity & Claude skills installed on the VPS."""
        return [
            "frontend-design-skill (/root/_agents/skills/frontend-design-skill/SKILL.md)",
            "llm-council (/root/_agents/skills/llm-council/SKILL.md)",
            "graphify (/root/_agents/skills/graphify/SKILL.md)",
        ]
