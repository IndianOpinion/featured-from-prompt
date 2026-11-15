# Security policy

## Reporting a vulnerability

Please e-mail hello@indianopinion.org with the details and, if you can, a
way to reproduce it. Do not open a public issue for a security problem.

We acknowledge reports within 3 working days and aim to ship a fix or a
mitigation within 30 days, sooner for anything that leaks secrets, spends
money past the caps, or lets someone set a featured image without the
capability. We credit reporters in the release notes unless you ask us not
to.

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | Yes |

## Scope notes

- Provider secrets must never appear in logs, records, REST answers or
  exception messages. A leak is a vulnerability.
- Pending images must not be reachable without the review capability.
- Spending past the per-day cap, or any provider call while the kill switch
  is off, is a vulnerability.
