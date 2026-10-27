<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Util;

use PHPUnit\Framework\TestCase;

class MergeApprovedRoutingTest extends TestCase
{
    public function testFailAndDefaultOfMergeApprovedAskAgain(): void
    {
        $state = $this->states()['merge_approved'] ?? null;
        $this->assertIsArray($state);
        $this->assertIsArray($state['on'] ?? null);

        $this->assertSame('ask_protected', $state['on']['fail'] ?? null);
        $this->assertSame('ask_protected', $state['on']['default'] ?? null);
    }

    public function testOnlyApprovedAskProtectedRoutesToMergeApproved(): void
    {
        $edges = [];
        foreach ($this->states() as $name => $state) {
            $this->assertIsArray($state);
            if (!isset($state['on']) || !is_array($state['on'])) {
                continue;
            }
            foreach ($state['on'] as $event => $target) {
                if ($target === 'merge_approved') {
                    $edges[] = [$name, $event];
                }
            }
        }

        $this->assertSame([['ask_protected', 'approve']], $edges);
    }

    /** @return array<string, mixed> */
    private function states(): array
    {
        $path = dirname(__DIR__, 2) . '/.tyci/workflows/issue-to-merge/workflow.json';
        $json = file_get_contents($path);
        $this->assertIsString($json);

        $workflow = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($workflow);
        $this->assertIsArray($workflow['states'] ?? null);

        return $workflow['states'];
    }
}
