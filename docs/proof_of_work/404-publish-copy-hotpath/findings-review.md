
## Round 1 dispositions (main session, after fixes)

1. (low) no direct toWire() unit tests — **fixed**: added tests/VO/PublishedMessageV2Test.php (wire bytes, empty values, negative id, too-long filter, invalid UTF-8).
2. (low) validation constants duplicated in three classes — **deliberately not fixed**: refactor (WireEncodeTrait) touches three files beyond this issue's minimal scope; tracked as follow-up in findings-coder.md.
3. (low) validation order (length before UTF-8) differs from WriteBuffer::addString() for doubly-invalid input — **not a real finding**: both orders reject the same inputs; only the exception identity for doubly-invalid data differs, which no caller or test relies on.
4. (nit) pack('J')+pack('n') vs pack('Jn') — **fixed**: now a single pack('Jn', ...), matching V1 style.
5. (nit) upper-bound publishingId check dead on 64-bit — **deliberately not fixed**: kept for 32-bit/parity with V1, as the review itself notes.
