# CI/CD merge policy (ShowOps / fpp-plugin-monitor-agent)

This repo uses **GitHub Actions** for PR gates. **Branch protection** and **required checks** are configured in **Settings → Branches** (or rulesets). On GitHub Free **private** repositories, branch protection requires **GitHub Pro** (or Team/Enterprise).

## Required status checks (blocking)

Use these **exact** check names when requiring checks for `main` (verify on a recent PR if names drift):

| Check name | Workflow | Notes |
| --- | --- | --- |
| `ShellCheck` | [CI](.github/workflows/ci.yml) | Scripts under `scripts/` and `system/` |
| `Plugin API contract` | [CI](.github/workflows/ci.yml) | Frozen paths / plugin UI surface |
| `Dry-Run Install` | [CI](.github/workflows/ci.yml) | Install script smoke (dry-run, arm64 runner) |
| `Refuses x86` | [CI](.github/workflows/ci.yml) | Installer rejects x86_64 |
| `Dry-Run Uninstall` | [CI](.github/workflows/ci.yml) | Uninstall script smoke (dry-run) |
| `Validate JSON` | [CI](.github/workflows/ci.yml) | `pluginInfo.json` |

Enable **Require branches to be up to date before merging** (strict).

## Merge when green (automated path)

1. **Settings → General → Pull Requests** → enable **Allow auto-merge**.
2. Protect `main` with the required checks above.
3. Create the **`automerge`** label (if missing) and add it to a PR when it should merge automatically after checks pass.
4. [Enable auto-merge](.github/workflows/enable-automerge.yml) uses squash merge via GitHub’s API. The PR merges only when all required checks succeed.

**QA alignment:** Co-own which checks are blocking vs informational; update this table when workflows change.

## Merge queue (optional)

For private repos, **Merge queue** usually needs **GitHub Team** or higher. If enabled, add `merge_group` to workflows per [GitHub docs](https://docs.github.com/en/pull-requests/collaborating-with-pull-requests/incorporating-changes-from-a-pull-request/merging-a-pull-request-with-a-merge-queue).
