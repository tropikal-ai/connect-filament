# Protected releases for the two supported Filament lines

The package remains two independently tested lines: 0.1 uses Filament 3 and 0.2
uses Filament 5. Never upgrade a host's Filament major to obtain an embed fix.
Core must first be visible through normal Packagist with verified source/dist
references; CI no longer uses a special Core VCS override.

## Source approval and tests

1. Complete the owning contributor checks and exact-source review. Open the 0.1
   source PR into `maintenance/filament-3`, and the 0.2 PR into protected `main`.
   Require all actual source PR checks before merging through the PR, even when
   maintenance has no branch protection. Never push directly to either source
   branch as a shortcut. Empty `.github/release-intents.json` means preparation
   only: no version is publishable.
2. After source PRs merge, a **separate protected-main PR** explicitly approves
   their exact merge SHAs, source PR numbers, supported lines and new versions in
   `.github/release-intents.json`. At most one record per line is allowed. This
   protected-main approval is maintenance publication authority; it does not make
   the maintenance branch protected. Do not substitute a branch name, arbitrary
   dispatch SHA, successful main-only tests or an unprotected source PR alone.
3. Main CI validates the committed intent with the immutable Core delivery action
   and runs the canonical PHP 8.2/8.3/8.4 matrix on every approved payload SHA.
   The ordinary package matrix and intent resolver must always succeed. Only an
   explicitly empty record list allows the payload matrix to be skipped. A
   populated list with skipped, cancelled or failed payload quality cannot pass
   required `CI green`. `ReleaseWorkflowTest` executes this exact shell guard.

## Publication and provenance

Wait for exact merged-main CI, then dispatch **Publish package** on protected
main using the approved version and full main/control SHA. The workflow resolves
that committed record and reruns its actual payload matrix read-only. Its final
write job checks out control and source separately, then invokes only immutable
Core action code—not Composer, source scripts or payload tests—with the runner
token. It revalidates intent, source PR, package line, clean exact checkouts,
actual quality SHA and current protected main. No extra credentials or repository
settings are needed or authorized by this procedure.

Keep `.github/workflows/` identical between the source payload and control main;
the publisher compares complete Git trees before creating any tag. GITHUB_TOKEN
cannot publish an off-main workflow modification; do not add a personal token,
change default branches or overwrite a tag to avoid that guard. Resolve failure
through reviewed source/workflow changes. Existing exact releases reconcile
idempotently; conflicting/uncertain state must never be deleted or overwritten.

Record the terminal workflow, control SHA, dereferenced source tag, release ID and
observed platform immutability. Verify the actual archive and normal Packagist
source/dist references, then each normal host lock/install and full owner gates.
A synthetic local package, VCS-only CI install or green publication job does not
replace those proofs. Published `immutable: false` must not be reported as
platform-enforced immutable storage.

The Core action and canonical quality workflow are pinned to the same full
reviewed commit. Changes to that pin or this trust boundary require affected
tests and the existing independent reviewer before protected publication.
