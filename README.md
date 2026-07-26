# RouteStack.ai — Starter Templates

Boilerplate projects for connecting to the RouteStack MCP server. Each starter is standalone — clone it, add your API key, and run.

## Quick Start

```bash
cd typescript/cli-agent
cp .env.example .env
# Add your ROUTESTACK_API_KEY
pnpm install
pnpm start
```

## Available Starters

### TypeScript

| Starter | Description |
|:--------|:------------|
| [chat-agent](typescript/chat-agent/) | Browser-based chat UI for travel queries |
| [cli-agent](typescript/cli-agent/) | Terminal-based travel search tool |
| [slack-bot](typescript/slack-bot/) | Slack slash commands for travel |
| [discord-bot](typescript/discord-bot/) | Discord bot for travel search |
| [react-widget](typescript/react-widget/) | Embeddable `<TravelSearch />` component |
| [multi-agent](typescript/multi-agent/) | Multi-agent trip planner (Claude Agent SDK) |
| [webhook-listener](typescript/webhook-listener/) | SSE event subscriber + forwarder |
| [nextjs-ai-mcp](typescript/nextjs-ai-mcp/) | Next.js + Vercel AI SDK integration |
| [voice-agent](typescript/voice-agent/) | Voice AI travel agent (OpenAI Realtime / Vapi) |
| [ollama-local](typescript/ollama-local/) | Local LLM (Ollama) + remote RouteStack MCP |
| [n8n-mcp-node](typescript/n8n-mcp-node/) | Custom n8n node for travel automation |
| [chrome-extension](typescript/chrome-extension/) | Context-aware Chrome side panel agent |
| [price-drop-cron](typescript/price-drop-cron/) | Cloudflare Worker cron for price alerts |
| [corp-approval-flow](typescript/corp-approval-flow/) | Corporate travel approval via Deep Links |
| [rag-travel-profile](typescript/rag-travel-profile/) | Personalized search with vector DB |
| [php-bridge](typescript/php-bridge/) | Private Node.js bridge for PHP websites (hotel, flight, car hire) |

### Python / Go / Rust

Coming soon. See the [language matrix](../docs/starter-code-guide.md) for planned coverage.

## Prerequisites

- A RouteStack API key ([get one at routestack.ai](https://routestack.ai))
- Node.js >= 20 (for TypeScript starters)
- An LLM API key (OpenAI or Anthropic) for agent-based starters

## Configuration

Every starter uses the same core environment variables:

```bash
ROUTESTACK_API_KEY=your_api_key_here
ROUTESTACK_MCP_URL=https://mcp.routestack.ai/sse
```

Some starters require additional keys (LLM provider, Slack, Discord, etc.). See each starter's `.env.example` for details.
