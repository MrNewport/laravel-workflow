<?php

declare(strict_types=1);

namespace MrNewport\LaravelWorkflow\Tests\Unit;

use InvalidArgumentException;
use MrNewport\LaravelWorkflow\Support\CanonicalJson;
use PHPUnit\Framework\TestCase;

final class CanonicalJsonTest extends TestCase
{
    public function test_associative_order_is_canonical_and_list_order_and_numeric_types_are_preserved(): void
    {
        $a = ['z' => [3, 1], 'a' => ['amount' => 1.0, 'count' => 1]];
        $b = ['a' => ['count' => 1, 'amount' => 1.0], 'z' => [3, 1]];
        $this->assertSame(CanonicalJson::encode($a), CanonicalJson::encode($b));
        $this->assertNotSame(CanonicalJson::encode([1, 3]), CanonicalJson::encode([3, 1]));
        $this->assertNotSame(CanonicalJson::encode(1), CanonicalJson::encode(1.0));
    }

    public function test_recursive_arrays_are_rejected_without_exhausting_the_process(): void
    {
        $data = [];
        $data['cycle'] = &$data;
        $this->expectException(InvalidArgumentException::class);
        CanonicalJson::encode($data);
    }

    public function test_excessive_nesting_is_rejected_before_normalization_can_exhaust_the_process(): void
    {
        $data = 'value';
        for ($i = 0; $i < 600; $i++) {
            $data = [$data];
        }
        $this->expectException(InvalidArgumentException::class);
        CanonicalJson::encode($data);
    }

    public function test_shared_non_recursive_array_references_are_supported(): void
    {
        $shared = ['key' => 'value'];
        $data = ['a' => &$shared, 'b' => &$shared];
        $this->assertSame('{"a":{"key":"value"},"b":{"key":"value"}}', CanonicalJson::encode($data));
        $this->assertSame(['key' => 'value'], $shared);
    }

    public function test_encoding_does_not_reorder_or_mutate_referenced_input(): void
    {
        $shared = ['z' => 1, 'a' => 2];
        $data = ['reference' => &$shared];
        $before = serialize($data);
        $this->assertSame('{"reference":{"a":2,"z":1}}', CanonicalJson::encode($data));
        $this->assertSame($before, serialize($data));
        $detached = CanonicalJson::detach($data);
        $this->assertSame(['z', 'a'], array_keys($detached['reference']));
        $shared['z'] = 999;
        $this->assertSame(1, $detached['reference']['z']);
    }
}
