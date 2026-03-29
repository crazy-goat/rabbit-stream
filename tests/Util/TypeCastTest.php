<?php

declare(strict_types=1);

namespace CrazyGoat\RabbitStream\Tests\Util;

use CrazyGoat\RabbitStream\Util\TypeCast;
use PHPUnit\Framework\TestCase;

class TypeCastTest extends TestCase
{
    public function testToIntWithInt(): void
    {
        $this->assertSame(42, TypeCast::toInt(42));
    }

    public function testToIntWithFloat(): void
    {
        $this->assertSame(3, TypeCast::toInt(3.14));
        $this->assertSame(3, TypeCast::toInt(3.99));
    }

    public function testToIntWithString(): void
    {
        $this->assertSame(123, TypeCast::toInt('123'));
        $this->assertSame(0, TypeCast::toInt('abc'));
    }

    public function testToIntWithBool(): void
    {
        $this->assertSame(1, TypeCast::toInt(true));
        $this->assertSame(0, TypeCast::toInt(false));
    }

    public function testToIntWithNull(): void
    {
        $this->assertSame(0, TypeCast::toInt(null));
    }

    public function testToIntWithArray(): void
    {
        $this->assertSame(0, TypeCast::toInt([]));
        $this->assertSame(0, TypeCast::toInt([1, 2, 3]));
    }

    public function testToIntWithObject(): void
    {
        $this->assertSame(0, TypeCast::toInt(new \stdClass()));
    }

    public function testToFloatWithFloat(): void
    {
        $this->assertSame(3.14, TypeCast::toFloat(3.14));
    }

    public function testToFloatWithInt(): void
    {
        $this->assertSame(42.0, TypeCast::toFloat(42));
    }

    public function testToFloatWithString(): void
    {
        $this->assertSame(3.14, TypeCast::toFloat('3.14'));
        $this->assertSame(0.0, TypeCast::toFloat('abc'));
    }

    public function testToFloatWithBool(): void
    {
        $this->assertSame(1.0, TypeCast::toFloat(true));
        $this->assertSame(0.0, TypeCast::toFloat(false));
    }

    public function testToFloatWithNull(): void
    {
        $this->assertSame(0.0, TypeCast::toFloat(null));
    }

    public function testToFloatWithArray(): void
    {
        $this->assertSame(0.0, TypeCast::toFloat([]));
    }

    public function testToFloatWithObject(): void
    {
        $this->assertSame(0.0, TypeCast::toFloat(new \stdClass()));
    }

    public function testToStringWithString(): void
    {
        $this->assertSame('hello', TypeCast::toString('hello'));
    }

    public function testToStringWithInt(): void
    {
        $this->assertSame('42', TypeCast::toString(42));
    }

    public function testToStringWithFloat(): void
    {
        $this->assertSame('3.14', TypeCast::toString(3.14));
    }

    public function testToStringWithBool(): void
    {
        $this->assertSame('1', TypeCast::toString(true));
        $this->assertSame('', TypeCast::toString(false));
    }

    public function testToStringWithNull(): void
    {
        $this->assertSame('', TypeCast::toString(null));
    }

    public function testToStringWithArray(): void
    {
        $this->assertSame('', TypeCast::toString([]));
    }

    public function testToStringWithObject(): void
    {
        $this->assertSame('', TypeCast::toString(new \stdClass()));
    }

    public function testToNullableStringWithNull(): void
    {
        $this->assertNull(TypeCast::toNullableString(null));
    }

    public function testToNullableStringWithString(): void
    {
        $this->assertSame('hello', TypeCast::toNullableString('hello'));
    }

    public function testToNullableStringWithInt(): void
    {
        $this->assertSame('42', TypeCast::toNullableString(42));
    }

    public function testToNullableStringWithBool(): void
    {
        $this->assertSame('1', TypeCast::toNullableString(true));
        $this->assertSame('', TypeCast::toNullableString(false));
    }

    public function testToBoolWithBool(): void
    {
        $this->assertTrue(TypeCast::toBool(true));
        $this->assertFalse(TypeCast::toBool(false));
    }

    public function testToBoolWithInt(): void
    {
        $this->assertTrue(TypeCast::toBool(1));
        $this->assertTrue(TypeCast::toBool(42));
        $this->assertFalse(TypeCast::toBool(0));
    }

    public function testToBoolWithFloat(): void
    {
        $this->assertTrue(TypeCast::toBool(1.0));
        $this->assertTrue(TypeCast::toBool(3.14));
        $this->assertFalse(TypeCast::toBool(0.0));
    }

    public function testToBoolWithString(): void
    {
        $this->assertTrue(TypeCast::toBool('1'));
        $this->assertTrue(TypeCast::toBool('hello'));
        $this->assertFalse(TypeCast::toBool(''));
        $this->assertFalse(TypeCast::toBool('0'));
    }

    public function testToBoolWithNull(): void
    {
        $this->assertFalse(TypeCast::toBool(null));
    }

    public function testToBoolWithArray(): void
    {
        $this->assertFalse(TypeCast::toBool([]));
        $this->assertFalse(TypeCast::toBool([1, 2, 3]));
    }

    public function testToBoolWithObject(): void
    {
        $this->assertFalse(TypeCast::toBool(new \stdClass()));
    }

    public function testToIntArrayWithArray(): void
    {
        $this->assertSame([1, 2, 3], TypeCast::toIntArray([1, 2, 3]));
    }

    public function testToIntArrayWithMixedTypes(): void
    {
        $this->assertSame([1, 2, 3, 0], TypeCast::toIntArray([1, '2', 3.14, null]));
    }

    public function testToIntArrayWithNonArray(): void
    {
        $this->assertSame([], TypeCast::toIntArray(null));
        $this->assertSame([], TypeCast::toIntArray('string'));
        $this->assertSame([], TypeCast::toIntArray(42));
    }

    public function testToIntArrayWithEmptyArray(): void
    {
        $this->assertSame([], TypeCast::toIntArray([]));
    }

    public function testToStringArrayWithArray(): void
    {
        $this->assertSame(['foo', 'bar'], TypeCast::toStringArray(['foo', 'bar']));
    }

    public function testToStringArrayWithMixedTypes(): void
    {
        $this->assertSame(['1', '2', '3.14', ''], TypeCast::toStringArray([1, '2', 3.14, null]));
    }

    public function testToStringArrayWithNonArray(): void
    {
        $this->assertSame([], TypeCast::toStringArray(null));
        $this->assertSame([], TypeCast::toStringArray(42));
    }

    public function testToStringArrayWithEmptyArray(): void
    {
        $this->assertSame([], TypeCast::toStringArray([]));
    }

    public function testToArrayWithArray(): void
    {
        $this->assertSame([1, 2, 3], TypeCast::toArray([1, 2, 3]));
    }

    public function testToArrayWithAssociativeArray(): void
    {
        $this->assertSame(['a', 'b', 'c'], TypeCast::toArray(['x' => 'a', 'y' => 'b', 'z' => 'c']));
    }

    public function testToArrayWithNonArray(): void
    {
        $this->assertSame([], TypeCast::toArray(null));
        $this->assertSame([], TypeCast::toArray('string'));
        $this->assertSame([], TypeCast::toArray(42));
    }

    public function testToArrayWithEmptyArray(): void
    {
        $this->assertSame([], TypeCast::toArray([]));
    }
}
