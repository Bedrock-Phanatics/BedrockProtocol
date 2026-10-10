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
use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\VarInt;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\serializer\BitSet;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\inventory\HandSlot;
use pocketmine\network\mcpe\protocol\types\inventory\InventoryTransactionChangedSlotsHack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\inventory\NormalTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\PredictedResult;
use pocketmine\network\mcpe\protocol\types\inventory\TriggerType;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\network\mcpe\protocol\types\ItemInteractionData;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use function array_fill;
use function range;
use function str_repeat;

final class InventoryChangedSlotsTest extends TestCase{
	public function testSlotBoundariesAndEarlyRejection() : void{
		foreach([[], [0], [255, 0, 1], range(0, 255), array_fill(0, 256, 0)] as $indexes){
			$slots = new InventoryTransactionChangedSlotsHack(2, $indexes);
			$out = new ByteBufferWriter();
			$slots->write($out);
			$in = new ByteBufferReader($out->getData());
			self::assertEquals($slots, InventoryTransactionChangedSlotsHack::read($in));
			self::assertSame(0, $in->getUnreadLength());
		}
		foreach([257, 100000, 0xffffffff] as $count){
			$out = new ByteBufferWriter();
			Byte::writeUnsigned($out, 2);
			VarInt::writeUnsignedInt($out, $count);
			Byte::writeUnsigned($out, 255);
			$in = new ByteBufferReader($out->getData());
			try{
				InventoryTransactionChangedSlotsHack::read($in);
				self::fail("Oversized slot list accepted");
			}catch(PacketDecodeException $e){
				self::assertSame([$count, InventoryTransactionChangedSlotsHack::MAX_CHANGED_SLOTS], [$e->observed, $e->limit]);
				self::assertSame(1, $in->getUnreadLength());
			}
		}
		foreach(["", "\x02", "\x02\x80", "\x02" . str_repeat("\x80", 6), "\x02\x01", "\x02\x80\x02" . str_repeat("\x00", 255)] as $buffer){
			try{
				InventoryTransactionChangedSlotsHack::read(new ByteBufferReader($buffer));
				self::fail("Truncated slot list accepted");
			}catch(DataDecodeException $e){
				$wrapped = PacketDecodeException::wrap($e);
				self::assertSame([null, null], [$wrapped->observed, $wrapped->limit]);
			}
		}
	}

	public function testBothPacketPathsPreserveFieldsAndLimitEvidence() : void{
		$transaction = UseItemTransactionData::new([], 0, TriggerType::PLAYER_INPUT, new BlockPosition(0, 0, 0), 0, 0, HandSlot::MAINHAND, ItemStackWrapper::legacy(ItemStack::null()), new Vector3(1, 2, 3), new Vector3(0, 0, 0), 0, PredictedResult::SUCCESS, 0);
		foreach([0, 1, 256, 257] as $count){
			$slots = new InventoryTransactionChangedSlotsHack(2, array_fill(0, $count, 255));
			foreach([
				InventoryTransactionPacket::create(-7, [$slots], NormalTransactionData::new([])),
				PlayerAuthInputPacket::create(
					new Vector3(1, 2, 3), 4, 5, 6, 0, 0, new BitSet(PlayerAuthInputFlags::NUMBER_OF_FLAGS),
					1, 0, 0, new Vector2(0, 0), 123, new Vector3(0, 0, 0),
					new ItemInteractionData(-7, [$slots], $transaction), null, null, null,
					0, 0, new Vector3(0, 0, 0), new Vector2(0, 0)
				)
			] as $packet){
				$packet->senderSubId = 2;
				$packet->recipientSubId = 3;
				$out = new ByteBufferWriter();
				$packet->encode($out);
				$original = clone $packet;
				$in = new ByteBufferReader($out->getData());
				try{
					$packet->decode($in);
					self::assertLessThanOrEqual(256, $count);
					self::assertEquals($original, $packet);
					self::assertSame(0, $in->getUnreadLength());
					$encoded = new ByteBufferWriter();
					$packet->encode($encoded);
					self::assertSame($out->getData(), $encoded->getData());
				}catch(PacketDecodeException $e){
					self::assertSame(257, $count);
					self::assertSame([257, 256], [$e->observed, $e->limit]);
					self::assertInstanceOf(PacketDecodeException::class, $e->getPrevious());
				}
			}
		}
	}
}
