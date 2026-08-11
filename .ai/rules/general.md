---
paths:
  - '{package.json,package-lock.json,.npmrc,composer.json,composer.lock,.github/**}'
---

# General

## Dependency supply-chain controls: quarantine window and ignore-scripts
`.npmrc` sets `ignore-scripts=true` (dependency install scripts never run — this is the main npm-worm payload vector) and `min-release-age=5` (refuse to resolve versions published <5 days ago).

Traps:
- `min-release-age` also applies to `npm audit signatures`, which re-resolves the tree. Pinning an override to a version newer than the window makes that command fail, not just installs.
- To land a security fix still inside the window: `npm install --min-release-age=0`, then diff package-lock.json and confirm only the intended package moved. Pin the exact version (`"nanoid": "3.3.17"`), not a caret range, or npm pulls the newest patch and defeats the quarantine.
- `npm ci` ignores the window (installs the lockfile verbatim), so CI is unaffected.

CI uses `composer ci:setup` (npm ci), never `composer setup` (npm install), so installs can't drift from the lockfile. `composer security:audit` runs all three gates locally.

## Socket.dev scanning: org, token scopes, and pinned CLI version
Org: RealLabs (id 369849, free plan). Repo has a permanent scan registered (not --tmp) as baseline; re-scan with `socket scan create --report .` after material dependency changes.

Token scopes for any Socket token used here — exactly three, no more:
`full-scans:create`, `full-scans:list`, `security-policy:read`. Do NOT grant `packages:list` (100+ quota units/call, needed only for `package score`/`fix`/`optimize`, none of which this project uses) or `fixes:list`/`report:write` (unrelated commands). A local/interactive token (`socket login`) and a CI token (GitHub secret `SOCKET_SECURITY_API_TOKEN`) should be separate tokens, not shared.

CLI trap: socket@1.1.151 and 1.1.152 throw `Cannot find module 'form-data'` on `scan create` — the module is required by their bundled dist/vendor.js but not declared in their package.json dependencies, so npm never installs it. Pin to 1.1.144 (or re-verify a newer version works before bumping) rather than installing unpinned/latest.

Global npm installs (`npm install -g`) use `~/.npmrc`, not the project's `.npmrc` — the project's `min-release-age=5` does not constrain global tool installs; only the user's own `~/.npmrc` (currently 7 days) does.

The CI step in .github/workflows/supply-chain.yml is present but commented out pending `SOCKET_SECURITY_API_TOKEN` as a repo secret — see the comment block there for setup steps.
