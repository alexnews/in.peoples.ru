# in.peoples.ru Community Platform — Build Plan

## What We're Building

A community portal at **in.peoples.ru** where users register, submit content (bios, news, photos, facts, poetry, etc.), and moderators approve it. Approved content goes directly into the existing peoples.ru database tables.

## Key Decisions

- **No new database** — uses existing `peoplesru` MySQL DB
- **Only 4 new tables** — `users`, `user_sessions`, `user_submissions`, `moderation_log`
- **Content goes into existing tables** — `histories`, `photo`, `news`, `Facts`, `songs`, `poetry`, etc.
- **`peoples_section` is the router** — section_id maps to target table_name
- **Separate project** at `/usr/local/www/in.peoples.ru/www/` with own git repo
- **PHP 8.1** on Ubuntu 22.04
- **GitHub Actions** for CI/CD (lint + deploy)
- **Open registration** — anyone can sign up, all content goes through moderation

## Documentation Index

| # | File | What It Covers |
|---|------|---------------|
| 00 | [overview.md](00-overview.md) | Vision, content flow, section mapping, constraints |
| 01 | [architecture.md](01-architecture.md) | Directory layout, VirtualHost, request flow, encoding |
| 02 | [database-schema.md](02-database-schema.md) | 4 new tables, approval logic per section |
| 03 | [agents.md](03-agents.md) | 5 agents: setup, API, user portal, moderation, testing |
| 04 | [auth-system.md](04-auth-system.md) | Registration, login, sessions, RBAC, CSRF |
| 05 | [api-design.md](05-api-design.md) | Full REST API spec: endpoints, request/response, errors |
| 06 | [user-ui.md](06-user-ui.md) | User portal wireframes: dashboard, forms, photo upload |
| 07 | [moderation-panel.md](07-moderation-panel.md) | Mod UI: queue, review, user management, shortcuts |
| 08 | [photo-upload.md](08-photo-upload.md) | Upload pipeline, image processing, security |
| 09 | [implementation-order.md](09-implementation-order.md) | Phase-by-phase plan, agent prompts, checkpoints |
| 10 | [testing-strategy.md](10-testing-strategy.md) | Test structure, categories, test cases |

## Build Phases (5 Agents)

```
Phase 1: Agent 1 (project setup + DB + includes) → Agent 2 (API)
Phase 2: Agent 3 (user portal) + Agent 4 (moderation panel) ← parallel
Phase 3: Agent 5 (testing & QA)
```

## Content Flow (The Core Idea)

```
User submits → user_submissions (staging) → Moderator approves → INSERT INTO {target table}
                                                                    ↑
                                                    peoples_section.table_name
```
