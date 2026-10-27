import { Client } from "/var/www/PersonalProjects/cis-mcp/node_modules/@modelcontextprotocol/sdk/dist/esm/client/index.js";
import { StdioClientTransport } from "/var/www/PersonalProjects/cis-mcp/node_modules/@modelcontextprotocol/sdk/dist/esm/client/stdio.js";

const client = new Client({ name: "tool-inspector", version: "1.0.0" }, { capabilities: {} });
const transport = new StdioClientTransport({
  command: process.execPath,
  args: ["/var/www/PersonalProjects/cis-mcp/src/index.ts"],
  stderr: "ignore"
});

try {
  await client.connect(transport);
  const tools = await client.listTools();
  console.log(JSON.stringify(tools.tools.map(t => ({ name: t.name, description: t.description })), null, 2));
  process.exit(0);
} catch (e) {
  console.error("Error:", e);
  process.exit(1);
}
