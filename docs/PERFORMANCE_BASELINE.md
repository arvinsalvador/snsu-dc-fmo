# Phase 12 local performance baseline

This is a small functional baseline, **not load testing or a production capacity claim**. Environment: local WSL2 Ubuntu, Docker Desktop, Laravel Sail PHP 8.5 container and MySQL 8.4 container; sample data is test-generated rather than campus-scale. PHP-FPM and MySQL share the development computer with other applications.

| Check | Observed result | Interpretation |
| --- | --- | --- |
| Public `GET /api/v1/health`, five sequential local HTTP requests | HTTP 200 each; total times 205 ms (first), then 11.5, 15.7, 12.3, 10.8 ms | Smoke check only; warm/cold effects and local machine load matter |
| Requester Work Order API collection with 55 own records plus an unrelated record | Automated test verifies first page contains 50, second page 5, unrelated record absent | Pagination and scope verified; no throughput claim |
| Web Work Order/history/notification lists | Code review confirms pagination (20 per page); report CSV streams 200-record chunks | Bounds response and export memory; query plans must be checked with realistic staging data |
| Assigned-only mobile bootstrap | Eager-loads related records but returns a complete snapshot | Potential payload growth remains a scale risk; no incremental cursor yet |

Before pilot deployment, measure p50/p95 response times, memory and SQL query counts for authenticated Work Order list, dashboard, filtered report, assigned-work API and mobile bootstrap using representative **staging** volumes. Record browser/device and dataset sizes. Review MySQL `EXPLAIN` for slow filters and make changes only after evidence. Do not treat the local health timings as an SLA.
