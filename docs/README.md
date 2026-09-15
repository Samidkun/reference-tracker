# Documentation

Two audiences, two artifacts. Do not merge them — a document serving both
serves neither.

| Document | Audience | Contents |
|---|---|---|
| [user-guide/](user-guide/README.md) | The person USING the app. Non-technical. | What it does, task-by-task how-to, FAQ |
| [runbook/](runbook/README.md) | Whoever MAINTAINS it. Technical. | Architecture, setup, tests, deploy, troubleshooting |
| [design-contract.md](design-contract.md) | Whoever builds the UI | Product intent, required states, banned patterns, a11y bar |
| [adr/](adr/) | Future maintainers | Why decisions were made |

## Rules

- **Architecture, deploy, and troubleshooting belong in the runbook.** Never in the user guide.
- **Screenshots and plain language belong in the user guide.** Never in the runbook.
- If a behaviour changes, update the runbook in the same commit. A stale runbook is worse than none — it sends the next maintainer down a wrong path with confidence.
