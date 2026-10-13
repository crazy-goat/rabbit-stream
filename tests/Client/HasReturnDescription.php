<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Client;

trait HasReturnDescription
{
    /**
     * Whether the docblock has an `@return` tag carrying a description after
     * its type. A bare `@return <type>` whose type merely repeats the native
     * return type is removed by Rector's DEAD_CODE set (FAQ-008), so tag
     * presence alone is not enough — the gate must require prose too. A
     * description may sit on the tag line or on the continuation line(s).
     */
    protected function hasReturnDescription(string $docblock): bool
    {
        $body = preg_replace('~^/\\*\\*|\\*/$~', '', trim($docblock)) ?? '';
        $lines = explode("\n", $body);

        foreach ($lines as $index => $line) {
            $line = trim(ltrim(trim($line), '*'));
            if (preg_match('/^@return\\s+(?:\\S*<[^>]*>|\\S*\\{[^}]*\\}|\\S+)\\s*(.*)$/', $line, $matches) !== 1) {
                continue;
            }
            if (trim($matches[1]) !== '') {
                return true;
            }
            for ($next = $index + 1, $count = count($lines); $next < $count; $next++) {
                $continuation = trim(ltrim(trim($lines[$next]), '*'));
                if ($continuation === '' || str_starts_with($continuation, '@')) {
                    break;
                }
                return true;
            }
        }

        return false;
    }
}
