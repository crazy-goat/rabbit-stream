# Upgrade Notes

This guide explains how to upgrade the library. It lists the incompatible changes of each release. The full release notes are in [CHANGELOG.md](../../../CHANGELOG.md).

## Check the Requirements

Check the requirements of the new version before you upgrade. The library needs PHP 8.1 or later and a 64-bit PHP build. See [Requirements](../getting-started/requirements.md) and [Installation](../getting-started/installation.md).

## Upgrade Steps

1. Open [CHANGELOG.md](../../../CHANGELOG.md).
2. Read every release section between your current version and the target version.
3. Find the entries under `### Changed (BREAKING)`. Each entry can break your code.
4. Change your code for each breaking entry. Use the table below as a list.
5. Run your application tests. See [Application Tests](application-tests.md).

## Incompatible Changes by Release

| Release | Change | Action |
|---|---|---|
| 1.14.0 | A name or offset reference over 255 bytes throws `InvalidArgumentException` before the client sends a frame. | Shorten the name to 255 bytes or less, or catch `InvalidArgumentException`. |
| 1.13.1 | `TlsConfig::$passphrase` is private. | Replace `$tls->passphrase` with `$tls->getPassphrase()`. The constructor argument `passphrase:` does not change. |
| 1.0.0 | `ReadBuffer::gatString()` is renamed to `getString()`. | Replace the old method name. |
| 1.0.0 | `CrazyGoat\StreamyCarrot\Trait\CorrelationInterface` moves to `CrazyGoat\StreamyCarrot\Contract\CorrelationInterface`. | Change the `use` statement that imports the interface. |
| 1.0.0 | `CrazyGoat\StreamyCarrot\Trait\KeyVersionInterface` moves to `CrazyGoat\StreamyCarrot\Contract\KeyVersionInterface`. | Change the `use` statement that imports the interface. |
| 1.0.0 | The error messages of `WriteBuffer` are in English. They were in Polish. | Do not match the old message text in your code. |
| 1.0.0 | Methods are declared `public static` instead of `static public`. | No action for code that only calls the methods. |

The 1.0.0 rows use the namespace of the package before its rename. The old namespace is `CrazyGoat\StreamyCarrot`. The current namespace is `CrazyGoat\RabbitStream`.

The table lists the entries that the changelog marks as breaking, and each public-API change that can break a call. Add a row here when a new release has a breaking change.

## Release Lines

The 1.x line takes fixes and changes that keep the public API. The next major version takes breaking changes. Read the changelog heading of your target version before you upgrade.
