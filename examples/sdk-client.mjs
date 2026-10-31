// Connects to MCP Gateway with the official MCP SDK and exercises each tool.
// Usage: MCP_URL=https://example.com/mcp MCP_TOKEN=mcp_... npm run demo
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { StreamableHTTPClientTransport } from "@modelcontextprotocol/sdk/client/streamableHttp.js";

const url = process.env.MCP_URL ?? "http://127.0.0.1:8888/mcp";
const token = process.env.MCP_TOKEN;
if (!token) {
  console.error("Set MCP_TOKEN to a token from /user/{uid}/mcp-tokens.");
  process.exit(1);
}

const transport = new StreamableHTTPClientTransport(new URL(url), {
  requestInit: { headers: { Authorization: `Bearer ${token}` } },
});
const client = new Client({ name: "mcp-gateway-example", version: "1.0.0" });
await client.connect(transport);

const server = client.getServerVersion();
console.log(`Connected to ${server.title} (${server.name} ${server.version})`);

const { tools } = await client.listTools();
console.log("Tools you may use:", tools.map((t) => t.name).join(", "));

const call = async (name, args = {}) => {
  const result = await client.callTool({ name, arguments: args });
  if (result.isError) throw new Error(`${name}: ${result.content[0].text}`);
  return result.structuredContent;
};

const info = await call("site_info");
console.log(`Acting as ${info.acting_as.name} on "${info.site_name}" (Drupal ${info.drupal_version})`);

const { results } = await call("search_content", { limit: 3 });
for (const item of results) console.log(`- [${item.nid}] ${item.title}: ${item.summary}`);

if (tools.some((t) => t.name === "create_draft")) {
  const draft = await call("create_draft", {
    type: "article",
    title: "Weekly content summary (AI draft)",
    body: `<p>Latest items: ${results.map((r) => r.title).join("; ")}.</p>`,
  });
  console.log(`Draft created for review: ${draft.edit_url}`);
}

await client.close();
