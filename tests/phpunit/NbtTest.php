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

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntArrayTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackExtraData;
use function bin2hex;
use function strlen;
use function substr;

class NbtTest extends TestCase{

	private static function sample() : CompoundTag{
		return CompoundTag::create()
			->setString("name", "minecraft:chest")
			->setInt("x", -7)
			->setLong("time", 1 << 40)
			->setTag("ints", new IntArrayTag([1, 300, 2147483647]))
			->setTag("items", new ListTag([new StringTag("a"), new StringTag("b")]))
			->setTag("empty", new ListTag());
	}

	public function testRootReadsAdvanceTheReader() : void{
		$encoded = (new CacheableNbt(self::sample()))->getEncodedNbt();
		$in = new ByteBufferReader("xx" . $encoded . "yy");
		$in->setOffset(2);

		self::assertTrue(self::sample()->equals(CommonTypes::getNbtCompoundRoot($in)));
		self::assertSame(2 + strlen($encoded), $in->getOffset());
	}

	public function testMalformedRootsThrowDecodeErrors() : void{
		$encoded = (new CacheableNbt(self::sample()))->getEncodedNbt();
		foreach([substr($encoded, 0, -1), "\x08\x00\x01a", "\x63\x00"] as $bad){
			try{
				CommonTypes::getNbtCompoundRoot(new ByteBufferReader($bad));
				self::fail("Accepted " . bin2hex($bad));
			}catch(PacketDecodeException){
				self::addToAssertionCount(1);
			}
		}
	}

	public function testHeadlessEventDataRoundTrips() : void{
		$packet = LevelEventGenericPacket::create(9, self::sample());
		$out = new ByteBufferWriter();
		$packet->encode($out);
		$in = new ByteBufferReader($out->getData() . "tail");

		$decoded = new LevelEventGenericPacket();
		$decoded->decode($in);
		self::assertTrue(self::sample()->equals($decoded->getEventData()));
		self::assertSame(strlen($out->getData()), $in->getOffset());
	}

	public function testItemExtraDataRoundTrips() : void{
		$out = new ByteBufferWriter();
		(new ItemStackExtraData(self::sample(), ["minecraft:stone"], []))->write($out);
		$in = new ByteBufferReader($out->getData());

		$decoded = ItemStackExtraData::read($in);
		$nbt = $decoded->getNbt();
		self::assertNotNull($nbt);
		self::assertTrue(self::sample()->equals($nbt));
		self::assertSame(["minecraft:stone"], $decoded->getCanPlaceOn());
		self::assertSame(strlen($out->getData()), $in->getOffset());

		$this->expectException(PacketDecodeException::class);
		ItemStackExtraData::read(new ByteBufferReader("\xff\xff\x01\x08\x00\x01a"));
	}
}
