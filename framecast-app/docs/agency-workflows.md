# Agency client workflows

This release extends `/clients` into a client work hub. It preserves existing video production, approval, asset storage and billing services. UGC and My Footage remain behind the existing internal-user release gate.

## Included

- Client list: search, archived toggle, open requests, pending reviews and spending alerts.
- Client overview: recent projects, outstanding work, due dates and activity.
- Client brief: audience, goals, products, approved claims, restrictions, creative preferences, pronunciation and workspace-scoped references/uploads. Existing brand kit settings continue to own logos, fonts and visual styling.
- Requests: client submission, attachments, due dates, status, assigned editing member and linked project. Start creates a blank draft once, carries the brief into the project, and does not charge generation credits.
- Reviews: existing exported-version approval links gain timestamped discussion. Comments and decisions identify the exported version. Final decisions cannot be overwritten by reusing the link.
- Delivery: agency selects completed, approved exports plus supporting assets, adds a message and expiry, and can revoke the public link. Media links expire after ten minutes and can be refreshed from the delivery page.
- Access: one account can join multiple workspaces with different roles. Removing access revokes only that membership. Invite delivery failures and expired invitations are visible, with resend support.
- Spending: monthly consumption and project breakdown, 80% cap warning, atomic deductions and ledger writes. Pooled clients spend monthly allowance directly; only top-up credits can be allocated into a funded client balance. Reclaims record the actual amount moved.
- Lifecycle: pause, archive and restore; archived clients do not consume the client quota. Offboarding archives, revokes memberships and delivery links, cancels pending approvals and can return unspent allocated top-ups. The UI explicitly confirms credit return.
- Client context feeds script generation, one-shot planning and internal UGC/footage planning. Supplied verbatim scripts are preserved.

## Permissions

| Role | Client work | Production | Client settings | Agency administration |
| --- | --- | --- | --- | --- |
| Viewer (`client`) | Read, submit requests/uploads, review | No paid generation | No | No |
| Editor (`client_editor`) | Read and review | Existing content operations | No workspace settings | No |
| Client admin (`client_admin`) | Read and review | Existing content operations | Client brief and existing workspace settings; no lifecycle changes | No |
| Agency owner | All client hubs and request management | Yes | Yes | Memberships, funding, caps, lifecycle and deliveries |

Invite agency staff into the specific client workspace as editors/admins; request assignment is constrained to members with editing access. This is workspace access plus request responsibility, not a project-level ACL system. Public review and delivery links grant access by possession of the token; reviewer names are self-reported, not verified identities.

## Database and rollout

Two additive migrations add active workspace selection to auth sessions and introduce memberships, briefs, requests, activity, timed comments and deliveries. Existing client seats are backfilled into memberships; account home workspace IDs are retained. Access is checked on every authenticated request, including session revocation and membership removal. Refresh preserves the selected workspace and recalculates its role.

1. Integrate this branch after reviewing concurrent changes to `routes/api.php`, `UgcController.php` and the web router. Their integration changes are deliberately small. Do not replace those files wholesale from this branch.
2. Back up the database and rehearse migrations in staging. The full migration history requires PostgreSQL.
3. Apply migrations before routing requests to the new API/web release, then restart queue workers so script jobs use the same schema and code.
4. Smoke-test with an agency owner, a new viewer and an existing user whose home workspace is different: invite, switch, refresh, role change and revoke.
5. Submit a request, create its draft twice (same project), export, review with a timed comment, approve, create a delivery and revoke it. Check public access after pausing/offboarding too.
6. Verify pooled/funded credit balances and caps using test accounts; do not run credit-writing smoke scripts against customer accounts.

The production bare repository's current post-receive hook pulls `origin master` regardless of the branch pushed. Pushing this feature to that remote is not an isolated deployment. Integrate and deploy a reviewed revision through a controlled release; do not use the existing hook to push a feature branch.

Rollback should restore application code while retaining the new tables. Dropping migrations after use deletes workflow data and memberships for users invited to additional workspaces.

## Validation and boundaries

- PHPUnit: 359 tests, 966 assertions, including cross-workspace access, role isolation, refresh, revoked membership tokens, invitation failure, archive quota, credit ledger rollback, export approval and delivery revocation.
- Production web build passed. Existing bundle-size and mixed-import warnings remain.
- Full migration chain passed on disposable PostgreSQL 16. Full-schema smoke checks covered request-to-draft idempotence, no draft charge, hub queries, concurrent charges at a cap, and credit return on offboarding.
- Local browser inspection used synthetic data and checked the actual overview, brief, requests and access UI. Live email delivery, paid generation and real external storage downloads were not exercised.

Hub lists currently show the latest 100 projects/requests/assets/approvals/exports, 50 deliveries and 30 activity entries. These need pagination before high-volume agency use. Assignment is per request; status and due dates are managed manually. Alerts are in-app, not email notifications. Delivery contains selected existing files; it does not automatically generate captions, thumbnails, alternate formats or ZIP archives. Custom branding/domains, invoices, CRM, analytics dashboards and project-level team permissions are separate future work.
