# Releasing

1. Update `Client::SDK_VERSION` and move user-visible changelog entries into a dated release
   section in a pull request. Composer derives the package version from the Git tag.
2. Run Composer validation, tests, coverage, and governance checks.
3. Merge after CI and CodeRabbit review.
4. Create and push an annotated tag matching the package version.
5. The release workflow verifies reviewed `main`, rebuilds and attests source archives, rechecks
   immutable tag identity, and creates the GitHub Release.

Register `alitycs/alitycs-sdk-php` with Packagist before the first tag if automatic registry
publication is desired. Packagist must consume only immutable GitHub tags.
