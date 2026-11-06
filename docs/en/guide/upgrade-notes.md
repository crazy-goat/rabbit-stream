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
| 1.14.0 | A name or offset reference over 255 bytes throws `InvalidArgumentException` before the client sends a frame. (#932) | Shorten the name to 255 bytes or less, or catch `InvalidArgumentException`. |
| 1.14.0 | `CreditRequestV1` throws an exception for a credit outside 0 to 32767. A larger credit was sent as a negative value. (#923) | Send a credit from 0 to 32767. |
| 1.13.1 | `TlsConfig::$passphrase` is private. (#948) | Replace `$tls->passphrase` with `$tls->getPassphrase()`. The constructor argument `passphrase:` does not change. |
| 1.9.0 | `ProducerInterface`, `ConsumerInterface` and `SuperStreamProducerInterface` declare new recovery and lifecycle methods. (#592) | If your own class implements one of these interfaces, add the new methods to it. |
| 1.6.0 | `ConnectionInterface` declares `isConnected(): bool`. (#547) | If your own class implements `ConnectionInterface`, add `isConnected()` to it. |
| 1.6.0 | `OffsetSpec::TYPE_INTERVAL` and `OffsetSpec::interval()` are removed. Offset type 6 is not in the protocol. (#468) | Use another factory, such as `OffsetSpec::offset()` or `OffsetSpec::timestamp()`. |
| 1.5.0 | `ConnectionInterface::createConsumer()` and `createSuperStreamConsumer()` gain the optional parameter `maxBufferSize`. (#435) | If your own class implements `ConnectionInterface`, update both method signatures. |
| 1.4.0 | `queryOffset()` returns `null` when no offset is stored. The broker answer `NO_OFFSET` was an error before. (#467) | Treat a `null` result as "no stored offset". |
| 1.3.0 | `KeyEnum::fromStreamCode()` no longer maps an unknown response code of `0x8000` or more to a request key. (#347) | Do not rely on the old mapping for unknown codes. |
| 1.3.0 | `Connection::create()` throws the library's `InvalidArgumentException` for an invalid argument, not the global `\InvalidArgumentException`. (#465) | No action for code that catches the global `\InvalidArgumentException`. The library class extends it. To catch only the library class, catch `CrazyGoat\RabbitStream\Exception\InvalidArgumentException`. |
| 1.2.0 | `OpenRequest` is renamed to `OpenRequestV1`. (#195) | Change the class name in your code. |
| 1.1.0 | `CommandTrait::assertResponseCodeOk()` replaces `isResponseCodeOk()`. (#109) | No action for code outside the library. The trait method is private. |
| 1.0.0 | `ReadBuffer::gatString()` is renamed to `getString()`. | Replace the old method name. |
| 1.0.0 | `StreamClient`, `StreamClientConfig` and `ProducerConfig` are removed. | Use `Connection::create()` and `Connection::createProducer()`. |
| 1.0.0 | `CrazyGoat\StreamyCarrot\Trait\CorrelationInterface` moves to `CrazyGoat\StreamyCarrot\Contract\CorrelationInterface`. | Change the `use` statement that imports the interface. |
| 1.0.0 | `CrazyGoat\StreamyCarrot\Trait\KeyVersionInterface` moves to `CrazyGoat\StreamyCarrot\Contract\KeyVersionInterface`. | Change the `use` statement that imports the interface. |
| 1.0.0 | The error messages of `WriteBuffer` are in English. They were in Polish. | Do not match the old message text in your code. |
| 1.0.0 | Methods are declared `public static` instead of `static public`. | No action for code that only calls the methods. |

The 1.0.0 rows use the namespace of the package before its rename. The old namespace is `CrazyGoat\StreamyCarrot`. The current namespace is `CrazyGoat\RabbitStream`.

The table lists the entries that the changelog marks as breaking. It also lists other changes that remove or rename a public name, change a signature, add a method to an interface, or change a return value or an exception type. It is not a full list of every change. Read the changelog sections between your version and the target version.

## Release Lines

The 1.x line takes fixes that keep the public API. The next major version takes breaking changes. Some past 1.x releases contain a change that breaks a call, for example 1.13.1 and 1.14.0. The table lists them. Read the changelog heading of your target version before you upgrade.
