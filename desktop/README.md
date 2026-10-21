# Stoop Admin (macOS)

A native SwiftUI app for moderating and maintaining Stoop. It talks only to the staff API at `/api/admin` (see `routes/api-admin.php`). It never loads the website.

## Connect

1. On Stoop, a staff member opens **Settings → Terminal & API**.
2. Tick **For the Stoop Admin desktop app** and create a token.
3. Paste the token and the site address into the app. The token is stored in your login keychain.

An admin token can moderate and run maintenance but can't post, and it stops working when its owner loses staff access.

## Build

```sh
brew install xcodegen   # once
cd desktop
xcodegen generate
xcodebuild -project StoopAdmin.xcodeproj -scheme StoopAdmin -derivedDataPath build build
open "build/Build/Products/Debug/Stoop Admin.app"
```

The build is ad-hoc signed, so it runs on this Mac only. To share it with other Macs, set `DEVELOPMENT_TEAM` and a Developer ID identity in `project.yml`, then notarize it.

## What it does

- **Moderation:** the queue (↑↓ to move; A approves, R removes, O opens the post on the site), appeals, reported messages, and active timeouts and bans.
- **People:** search, the ban review list, penalties, verification and staff notes.
- **Maintenance:** integration health checks, safe chores (article import, scheduler, search index, cache, backup), failed jobs and the log tail. Nothing can wipe or reseed the database.
- **Alerts:** a Dock badge, a menu bar count and a notification when new work arrives (checked every 30 seconds).
