<?php

/*
 * This file is part of BedrockProtocol.
 * Copyright (C) 2014-2022 PocketMine Team <https://github.com/pmmp/BedrockProtocol>
 *
 * BedrockProtocol is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\VarInt;
use pocketmine\network\mcpe\protocol\serializer\BitSet;
use function bin2hex;
use function chr;
use function in_array;
use function range;

class BitSetTest extends TestCase{

	public function testBitSet() : void{
		$writeTest = new BitSet(65);

		$writeTest->set(0, true);
		$writeTest->set(64, true);

		$out = new ByteBufferWriter();
		$writeTest->write($out);

		$in = new ByteBufferReader($out->getData());
		$readTest = BitSet::read($in, 65);

		self::assertEqualBitSets($writeTest, $readTest);
	}

	public function testBitSetConstructor() : void{
		$test = new BitSet(65, [-9223372036854775807 - 1, 1]);
		$test2 = new BitSet(65, [-9223372036854775807 - 1]);

		$test2->set(64, true);

		$out1 = new ByteBufferWriter();
		$test->write($out1);

		$out2 = new ByteBufferWriter();
		$test2->write($out2);

		self::assertEquals($out1->getData(), $out2->getData());
	}

	public function testBitSetParts() : void{
		$writeTest = new BitSet(128);
		$writeTest->set(127, true);

		$out = new ByteBufferWriter();
		$writeTest->write($out);

		$in = new ByteBufferReader($out->getData());
		$readTest = BitSet::read($in, 128);

		self::assertEqualBitSets($writeTest, $readTest);
	}

	public function testVarUnsignedLongCompatibility() : void{
		$out = new ByteBufferWriter();
		VarInt::writeUnsignedLong($out, 1 << 63);

		$in = new ByteBufferReader($out->getData());
		$readTest = BitSet::read($in, 64);

		$expectedResult = new BitSet(64);
		$expectedResult->set(63, true);

		self::assertEqualBitSets($expectedResult, $readTest);
	}

	/**
	 * @return \Generator<string, array{int, list<int>}>
	 */
	public static function wideSetsProvider() : \Generator{
		yield "top bit of the first part" => [128, [63]];
		yield "both sides of the part boundary" => [128, [62, 63, 64]];
		yield "last bit" => [128, [127]];
		yield "every bit" => [70, range(0, 69)];
	}

	/**
	 * @param list<int> $bits
	 */
	#[DataProvider("wideSetsProvider")]
	public function testWideSetsWriteEachGroupOnce(int $length, array $bits) : void{
		$set = new BitSet($length);
		foreach($bits as $bit){
			$set->set($bit, true);
		}
		$expected = "";
		for($group = 0; $group * 7 < $length; ++$group){
			$value = 0;
			for($i = 0; $i < 7; ++$i){
				if(in_array($group * 7 + $i, $bits, true)){
					$value |= 1 << $i;
				}
			}
			$expected .= chr($value | (($group + 1) * 7 < $length ? 0x80 : 0));
		}

		$out = new ByteBufferWriter();
		$set->write($out);
		self::assertSame(bin2hex($expected), bin2hex($out->getData()));
		self::assertTrue($set->equals(BitSet::read(new ByteBufferReader($out->getData()), $length)));
	}

	private static function assertEqualBitSets(BitSet $a, BitSet $b) : void{
		self::assertEquals($length = $a->getLength(), $b->getLength(), "BitSet lengths are not equal");

		for($i = 0; $i < $length; ++$i){
			self::assertEquals($a->get($i), $b->get($i), "BitSet values at index $i are not equal");
		}

		self::assertEquals($a->getPartsCount(), $b->getPartsCount(), "BitSet parts count is not equal");

		self::assertTrue($a->equals($b));
		$b->set($b->getLength() - 1, !$b->get($b->getLength() - 1));
		self::assertFalse($a->equals($b), "BitSet equality check failed after modifying one BitSet");
	}
}
