## Summary

Describe the user-visible change and why it is needed.

## Compatibility

- [ ] PHP API and PHP 8.1 compatibility impact is documented.
- [ ] No wire-contract change, or the coordinated contract change is explained.
- [ ] The SDK still sends only to worker `/events` and keeps credentials out of source.

## Verification

- [ ] `composer validate --strict`
- [ ] `composer test`
- [ ] `composer run test:coverage`

## Automated review

- [ ] Native `CodeRabbit` passed for the latest push; formal review state was checked.
- [ ] Blocking findings are resolved and governance changes have code-owner approval.
