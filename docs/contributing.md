# Contributing

## Local checks

Before opening a PR, run the same gates CI runs:

```bash
composer quality   # cs-check + phpstan + phpunit
```

Individual commands:

```bash
composer cs-check
composer phpstan   # needs a warmed container: php bin/console cache:warmup
composer test
composer infection # mutation testing (needs Postgres + JWT keys like PHPUnit)
```

Infection is not part of `composer quality` (too slow for the everyday gate). Locally it mutates all of `src/` (except `Kernel.php`). Config lives in `infection.json.dist`; override locally with an untracked `infection.json` / `infection.json5` if needed.

## CI

On every push and pull request, GitHub Actions runs four jobs:

| Check | What it runs |
|-------|----------------|
| CS Fixer | `composer cs-check` |
| PHPStan | cache warmup + `composer phpstan` |
| PHPUnit | tests with coverage |
| Infection | mutation testing on changed lines only (`--git-diff-lines`) |

Infection fails the build when covered MSI on mutated lines is below **70%**. Diffs that touch no mutable `src/` lines pass (`ignoreMsiWithNoMutations`).

Coverage is uploaded to Codecov. PRs get a coverage comment. The Codecov check fails when:

- overall project coverage drops more than **2%** vs the default-branch baseline (`target: auto`), or
- **patch** coverage on new/changed lines is below **80%**.

### Secrets

Set `CODECOV_TOKEN` in the repo secrets (Settings → Secrets and variables → Actions). Create a token at [codecov.io](https://codecov.io) for this repository. Required for private repos; recommended for public ones so uploads are reliable. When the secret is unset, the upload step is skipped and PHPUnit still passes. Once it is set, a failed upload fails the job.

## Dependency updates (Renovate)

This repo uses [Renovate](https://docs.renovatebot.com/) (not Dependabot). Install the [Mend Renovate GitHub App](https://github.com/apps/renovate) on the repository once. Config lives in `renovate.json` (Composer + GitHub Actions, weekly schedule, Symfony and Actions grouping).

## Releases

Tag a semver release and push the tag:

```bash
git tag vX.Y.Z
git push origin vX.Y.Z
```

The Release workflow then:

1. Builds and pushes the production image to GHCR (`ghcr.io/<owner>/znamky`)
2. Creates a GitHub Release with auto-generated notes

## Branch protection (manual)

In Settings → Branches → rules for the default branch, require:

- Status checks: **CS Fixer**, **PHPStan**, **PHPUnit**, **Infection**, and the **Codecov** check
- Do not allow force pushes
- Prefer requiring a pull request before merging
