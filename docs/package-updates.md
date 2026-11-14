# Package updates

Compatible Chat notification-provider fixes are deployed centrally. Once a host adopts `customer_notifications.v1`, it calls the current server on each delivery, so a server fix does not require every school to reinstall the connector. The versioned request, capability probe and fallback rules are the compatibility boundary.

Connector PHP changes still require an immutable package update in each host's Composer lock. There is no safe way to change pinned PHP source remotely without that deployment. Existing installations do not opt in automatically, and server improvements cannot add a missing adapter to an old host.

## Owning publication

The package's protected-main `Publish package` workflow takes a stable tag and exact expected SHA. It runs the shared Connect PHP quality matrix and publication policy tests. The write-capable job requires both gates. It rejects branch dispatches, stale main, dirty source, version conflicts, mismatched release readback and uncertain writes. Retry reconciles the same tag/release rather than moving or deleting a tag. No workstation publication path is provided.

The workflow uses only the runner's scoped `github.token`. Its presence in source does not mean publication has run. Repository rules must protect main and published tags. The report distinguishes exact-source publication from GitHub's optional platform release immutability setting.

## Consumer rollout

Publish the tested package first. A consumer update PR changes the package constraint only if required and commits the resolved Composer lock. Its owning CI must test the notification journey, migrations and previous server/connector combinations before protected merge/deployment. Do not replace the lock with a branch, mutable download or a startup-time `composer update`.

This public package and Connect SDK already contain weekly GitHub Dependabot configuration for Composer and immutable Actions references. That creates update PRs; it does not grant automatic merge or deploy rights. GitHub Dependabot configuration does not operate on a separate Gitea repository.

At inspection, Art of Sport School's authoritative Gitea `prod` tree had the protected delivery workflow but no dependency update runner/configuration. Automatic connector distribution there requires an owning Gitea scheduled update workflow with verified package read access, a repository-scoped PR credential/runtime token, duplicate-PR handling and the existing required verification/deployment path. Its runner and credentials must be provisioned and tested by that repository's owner. No credential or inert updater configuration is added by this package.

## Compatibility evidence

`tests/Fixtures/customer-notification-compatibility.json` and `CustomerNotificationCompatibilityTest` cover legacy 404/405, disabled support, capability outage/configuration denial, unexpected envelopes, future versions and additive v1 fields. The standard package CI runs those tests. `CustomerNotificationsTest` covers admission/claim uncertainty and duplicate prevention. `NotificationDashboardTest` verifies tenant-scoped status, private-field exclusion and an enabled-but-unmigrated installation rendering without a table error.
