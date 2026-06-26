# Project Instructions

We are building a custom osTicket plugin for Autotask integration.

Important rules:
- Do not modify osTicket core files unless explicitly requested.
- All custom integration code must live under include/plugins/autotask/.
- Follow osTicket plugin conventions.
- Use osTicket's Signal system for hooks.
- Use plugin-specific database tables for mappings, sync queue, and logs.
- Do not hardcode Autotask credentials.
- Do not commit secrets, API keys, passwords, or live customer data.
- Keep changes small and module-based.

Primary goal:
Build the Autotask integration plugin module by module.

Initial module sequence:
1. Module 2 - Create Plugin Folder Structure
2. Module 3 - Plugin Metadata and Registration
3. Module 4 - Create Database Tables
4. Module 5 - Plugin Configuration Page
5. Module 6 - Autotask REST Client
6. Module 7 - Test Connection Function
