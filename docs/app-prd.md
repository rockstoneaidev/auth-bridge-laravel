# Product Requirements Document (PRD): Auth Bridge Laravel

## 1. Project Overview

**Project Name:** Auth Bridge Laravel  
**Type:** Laravel Package / Integration Layer  
**Primary Stakeholders:**  
- Platform engineers (maintainers of auth-api and downstream apps)
- App developers (using laravel-app-template)
- AI developer agents (see [AI Collaboration Playbook](../ai/AGENTS.md))

**Summary:**  
Auth Bridge Laravel is a reusable Laravel package that handles authentication and user context for Laravel + Inertia + Svelte apps. It supports two strategies:

1.  **Firebase Authentication** (Default/Recommended): Modern, stateless JWT authentication where each app (per environment) has its own Firebase project.
2.  **Auth API** (Legacy): Centralized OAuth2/Passport authentication connecting to the **auth-api** service.

The package standardizes the interface for "logging in", syncing a minimal local user record, and managing roles/permissions, regardless of the underlying provider.

The laravel-app-template is at ../laravel-app-template/ and the auth-api is at ../auth-api/. From the laravel-app-template and its install.sh script together with this auth-bridge-laravel package, we created the RefinePress app at ../refinepress/.

---

## 2. Goals & Success Metrics

**Business Goals:**
- Enable rapid launch of new Laravel + Inertia + Svelte apps with zero custom authentication code.
- Support modern, standard authentication (Firebase) while maintaining support for legacy centralized auth.
- Reduce onboarding time for new projects to under 10 minutes.

**User Goals:**
- Developers can create a new app and choose their auth provider (Firebase or Auth API) via configuration.
- End users experience seamless authentication with modern security standards (MFA, etc.).

**Success Metrics / KPIs:**
- Time to first authenticated request in a new app < 10 minutes.
- Successful adoption of Firebase provider for new apps.
- Zero regression for existing apps using Auth API.

---

## 3. Scope & Non-Scope

**In Scope:**
- Laravel package (`auth-bridge-laravel`) that:
    - **Firebase Provider:** Verifies Firebase ID tokens (JWT), handles JWKS caching, and maps claims to user context.
    - **Auth API Provider:** Connects to central auth-api (OAuth2) and handles token validation/refresh.
    - Publishes migrations to sync local users table.
    - Scaffolds Inertia + Svelte UI for login (customizable).
    - Internalizes User creation/sync logic.
- Integration with [laravel-app-template](https://github.com/rockstoneaidev/laravel-app-template) for rapid app creation.
- Documentation for AI agents and human developers (see [AI Collaboration Playbook](../ai/AGENTS.md)).

**Out of Scope:**
- The central auth-api itself (see [auth-api repo](https://github.com/rockstoneaidev/auth-api)).
- Hosting Firebase (managed by Google).
- Custom business logic for downstream apps.

---

## 4. Core Architecture Layer

| Framework      | Language | Strategy                                | Database      | Queue / Jobs | Frontend         | Observability      | Deployment           |
|----------------|----------|-----------------------------------------|---------------|--------------|------------------|--------------------|----------------------|
| Laravel 12.x   | PHP 8.4+ | Firebase (JWT) or Auth API (Passport)   | MySQL (local) | Optional     | Inertia + Svelte | Laravel logs, Sentry| Composer/NPM, Docker |

**Key Components:**
- **Firebase Authentication**: Default provider, handles identity and MFA.
- **auth-api**: Legacy central authority.
- **auth-bridge-laravel**: Abstraction layer implementing `AuthProviderInterface`.
- **laravel-app-template**: Starter kit using this bridge.

---

## 5. Functional Requirements

- **Authentication Providers:**
    - **Firebase (Default):**
        - Configured via `AUTH_BRIDGE_PROVIDER=firebase`.
        - Verifies RS256 JWTs from Firebase.
        - Validates `iss`, `aud` (Project ID), `exp`, `sub`.
        - Caches Google's JWKS public keys.
    - **Auth API (Legacy):**
        - Configured via `AUTH_BRIDGE_PROVIDER=auth_api`.
        - Uses centralized OAuth2 flow.
        - Delegates token validation to Auth API User endpoint.

- **User Synchronization:**
    - JIT (Just-In-Time) provisioning of users into local MySQL `users` table upon successful auth.
    - Syncs `external_user_id` (Firebase UID or Auth API UUID).
    - Maps profile data (email, name, avatar).

- **Install/Onboard Flow:**
    - `install.sh` in template supports provider selection.
    - `auth-bridge:onboard` command handles setup.

- **AI Agent Awareness:**
    - All conventions, scripts, and integration points are documented in [ai/AGENTS.md](../ai/AGENTS.md).
    - AI agents should reference this PRD and related docs for context.

---

## 6. Integration & Usage

**For a new app:**
1. Create from [laravel-app-template](https://github.com/rockstoneaidev/laravel-app-template).
2. Run `install.sh` and select **Firebase**.
3. Configure `FIREBASE_PROJECT_ID` in `.env`.
4. App is ready.

**For legacy apps (Auth API):**
See [auth-api/README.md](https://github.com/rockstoneaidev/auth-api/blob/main/README.md) and [docs/setup/testing-apps.md](https://github.com/rockstoneaidev/auth-api/blob/main/docs/setup/testing-apps.md) for details on registering new apps and managing OAuth clients.

---

## 7. References

- [AI Collaboration Playbook](../ai/AGENTS.md)
- [Auth Bridge Integration Guide](./setup/auth-bridge.md)
- [laravel-app-template](https://github.com/rockstoneaidev/laravel-app-template)
- [auth-api](https://github.com/rockstoneaidev/auth-api)
- [Testing Apps Setup](./setup/testing-apps.md)

---

## 8. Change Log

- **2025-12-12:** Added Firebase Authentication as a supported provider.
- **2025-11-09:** Major update to reflect real-world architecture and onboarding flow.
