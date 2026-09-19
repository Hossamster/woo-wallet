# Releasing an update

Sites running Axfit Wallet see "Update available" in wp-admin the same way
they would for a WordPress.org-hosted plugin — but the update source is
this GitHub repo, not wordpress.org, and **nothing is pushed to installed
sites just because you pushed to `main`**. A site only sees a new version
after you deliberately publish a GitHub Release (or, at minimum, push a
version tag). Regular commits and pushes have no effect on any installed
site, no matter how many of them happen.

This is implemented by the bundled [Plugin Update
Checker](https://github.com/YahnisElsts/plugin-update-checker) library
(`includes/libraries/plugin-update-checker/`), wired up in
`includes/woo-wallet-update-checker.php`.

## Steps to ship a new version

1. **Bump the version number** in two places (existing discipline — nothing new here):
   - `woo-wallet.php`: the `Version:` header comment AND the `WOO_WALLET_PLUGIN_VERSION` constant.
   - `readme.txt`: the `Stable tag:` line.
2. **Add a changelog entry** in `readme.txt` under `== Changelog ==` (and an `== Upgrade Notice ==` entry too, if the change is significant enough to warrant one).
3. **Commit and push** those changes to `main` as usual. Still no update shown to anyone yet — this is the same as any other push.
4. **Create a GitHub Release**:
   - Go to the repo → **Releases** → **Draft a new release**.
   - Create a new tag matching the version, e.g. `v1.7.9` (the `v` prefix is optional, either works).
   - Target: `main`, at the commit you just pushed in step 3.
   - Title/description: whatever you like — the description is shown to site admins when they click "View version details" in wp-admin.
   - **Do not** check "Set as a pre-release" — Plugin Update Checker ignores pre-releases by design.
   - Click **Publish release**.
5. **Wait ~1 minute.** Publishing the release automatically triggers the `Build release zip` GitHub Action (`.github/workflows/build-release.yml`), which checks out that exact tag, strips dev-only files (tests, CI config, composer files, docs), zips the rest, and attaches it to the Release as `woo-wallet.zip`. This is the file Plugin Update Checker actually downloads — never GitHub's raw auto-generated source archive (which would otherwise ship `tests/`, `.github/`, `composer.json`, etc. to end users).

That's it. Sites will pick up the update automatically within ~12 hours (Plugin Update Checker's default check interval), or immediately if a site admin clicks "Check for updates" on the Plugins page.

## If the zip didn't get attached

Check the **Actions** tab for a failed `Build release zip` run against your tag. You can re-run it manually without creating a new release: **Actions → Build release zip → Run workflow**, entering the existing tag name.

## Tags without a full Release

Plugin Update Checker also recognizes a plain Git tag (e.g. `git tag v1.7.9 && git push origin v1.7.9`) without going through the GitHub Releases UI. This still won't fire on a normal push — creating a tag is its own deliberate action — but the `Build release zip` workflow above only listens for the `release: published` event, so a bare tag push (with no Release) won't get a clean zip attached automatically; site updates would then fall back to GitHub's raw source archive. **Prefer creating a proper Release** (step 4 above) so the clean zip always gets built.
