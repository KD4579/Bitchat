# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Bitchat is a social networking platform built on the WoWonder framework. It provides messaging, posts/feeds, forums, groups, pages, events, marketplace, and video/audio calling.

## Tech Stack

- **Backend**: PHP 7.1+ with MySQLi
- **Database**: MySQL/MariaDB (~100+ tables, all prefixed `Wo_`)
- **Caching**: Redis (sessions in DB 1, data cache in DB 2, file-based fallback)
- **Real-time**: Node.js Socket.io server (Express + Sequelize + Redis adapter)
- **Templates**: PHTML files rendered via `Wo_LoadPage()`
- **Web Server**: Apache (.htaccess) or Nginx (nginx.conf)

## Development Commands

### Node.js real-time server (in `nodejs/` directory)
```bash
npm start          # Production: node main.js
npm run dev        # Development: nodemon main.js
```

### Deployment
```bash
bash deploy.sh     # Live server deployment with backup + git pull + permission fix
```

### Local PHP development
```bash
php -S localhost:8000   # Built-in PHP server (site_url in config.php must match)
```

No test suite, linter, or build pipeline exists. There is no `composer install` step — PHP libraries are vendored in `assets/libraries/`.

## Architecture

### Request Flow

1. All requests route through `index.php` → `assets/init.php` (loads config, functions, session)
2. URL rewriting (`.htaccess`/`nginx.conf`) maps clean URLs to `?link1=page&link2=subpage` params
3. `index.php` routes to the appropriate file in `sources/{link1}.php`
4. Templates render from `themes/{active_theme}/layout/{page}/content.phtml`
5. AJAX requests go to `requests.php` which dispatches to handlers in `xhr/` (272 handlers)

### Key Directories

| Directory | Purpose |
|-----------|---------|
| `sources/` | Page controller logic (106 PHP files + subdirs for Forums, Events, etc.) |
| `xhr/` | AJAX request handlers (272 files) |
| `assets/includes/` | Core function libraries |
| `assets/libraries/` | Vendored third-party PHP libraries (31 packages) |
| `themes/` | 3 themes (wowonder, sunshine, wondertag), each with layout/, pages/, assets/ |
| `nodejs/` | Socket.io real-time server with 117 Sequelize models |
| `admin-panel/` | Admin dashboard (124 page templates) |
| `api/` | Mobile and v2 API handlers |

### Core PHP Files

| File | Purpose |
|------|---------|
| `assets/includes/functions_general.php` | General utilities (~2,200 lines) |
| `assets/includes/functions_one.php` | User/auth functions (~10,900 lines) |
| `assets/includes/functions_two.php` | Content functions (~7,200 lines) |
| `assets/includes/functions_three.php` | Advanced features (~8,800 lines) |
| `assets/includes/tabels.php` | Database table name constants (T_*) |
| `assets/includes/security_helpers.php` | Rate limiting, CSRF, sanitization |
| `assets/includes/redis_cache.php` | Redis caching layer with TTL management |
| `assets/includes/data.php` | Configuration constants |

### Entry Points

| File | Purpose |
|------|---------|
| `index.php` | Main app (page routing, session, redirects) |
| `api.php` | REST API v1.3.1 |
| `api-v2.php` | REST API v2 |
| `app_api.php` | Mobile app API |
| `admincp.php` | Admin panel router |
| `requests.php` | AJAX dispatcher → `xhr/*.php` |
| `ajax_loading.php` | Legacy AJAX handlers |
| `login-with.php` | Social OAuth login |
| `cron-job.php` | Background scheduled tasks |

## Conventions

- **Function naming**: All functions use `Wo_` prefix (e.g., `Wo_GetConfig()`, `Wo_UserData()`, `Wo_Login()`)
- **Table constants**: Defined in `tabels.php` as `T_USERS`, `T_POSTS`, etc., resolving to `Wo_` prefixed table names
- **Database access**: Uses MySQLi wrapper class in `assets/libraries/DB/`
- **Input sanitization**: `Wo_Secure()` for all user input; `SecurityHelpers` class for CSRF, rate limiting, file validation
- **Template loading**: `Wo_LoadPage()` renders PHTML templates from the active theme

## Configuration (gitignored — sensitive)

- `config.php` — Database credentials, site URL, auto-redirect setting
- `nodejs/config.json` — Node.js server database config
- `.user.ini` — PHP session config (Redis handler, cookie settings)

## Redis Cache TTLs

- News Feed: 30s | Notifications: 10s | User Data: 30s | Suggestions: 5min | Trending: 1min

## Deployment Notes

- `deploy.sh` creates timestamped backups, preserves config files, runs `git pull`, fixes permissions (dirs: 755, files: 644), and updates Node.js dependencies
- Writable directories needed: `upload/`, `cache/`
- FFmpeg binaries are included in `ffmpeg/` for video processing
- PHP configured for 256MB uploads, 5-minute execution timeout (`php.ini`)
