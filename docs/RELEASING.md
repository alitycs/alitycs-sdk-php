# Releasing

1. Update `Client::SDK_VERSION` and move user-visible changelog entries into a dated release
   section in a pull request. Composer derives the package version from the Git tag.
2. Run Composer validation, tests, coverage, and governance checks.
3. Merge after CI and CodeRabbit review.
4. Create and push an annotated tag matching the package version.
5. The release workflow verifies reviewed `main`, rebuilds source archives, rechecks immutable tag
   identity, attests the artifacts, and creates the GitHub Release.

The active `Immutable release tags` ruleset matches `refs/tags/v*`, blocks tag updates and
deletions, and has no bypass actors.

Register `alitycs/alitycs-sdk-php` with Packagist before the first tag if automatic registry
publication is desired. Packagist must consume only immutable GitHub tags.
