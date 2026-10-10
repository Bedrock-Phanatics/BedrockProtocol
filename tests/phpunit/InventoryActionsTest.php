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
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\VarInt;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\serializer\BitSet;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\inventory\HandSlot;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\inventory\MismatchTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\NetworkInventoryAction;
use pocketmine\network\mcpe\protocol\types\inventory\NormalTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\PredictedResult;
use pocketmine\network\mcpe\protocol\types\inventory\ReleaseItemTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\TransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\TriggerType;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\network\mcpe\protocol\types\ItemInteractionData;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use function array_fill;
use function str_repeat;
use function strlen;

final class InventoryActionsTest extends TestCase{
	private function action() : NetworkInventoryAction{
		$action = new NetworkInventoryAction();
		$action->sourceType = NetworkInventoryAction::SOURCE_CONTAINER;
		$action->windowId = 2;
		$action->inventorySlot = 7;
		$action->oldItem = $action->newItem = ItemStackWrapper::legacy(ItemStack::null());
		return $action;
	}

	public function testAllTransactionTypesRejectBeforeReadingActions() : void{
		foreach([NormalTransactionData::class, MismatchTransactionData::class, UseItemTransactionData::class, UseItemOnEntityTransactionData::class, ReleaseItemTransactionData::class] as $type){
			foreach([101, 100000, 0xffffffff] as $count){
				$out = new ByteBufferWriter();
				VarInt::writeUnsignedInt($out, $count);
				$prefix = $out->getData();
				foreach([$prefix, $prefix . "\xff"] as $buffer){
					$in = new ByteBufferReader($buffer);
					$transaction = new $type();
					try{
						$transaction->decode($in);
						self::fail("Oversized transaction accepted");
					}catch(PacketDecodeException $e){
						self::assertSame([$count, TransactionData::MAX_ACTIONS], [$e->observed, $e->limit]);
						self::assertSame([], $transaction->getActions());
						self::assertSame(strlen($buffer) - strlen($prefix), $in->getUnreadLength());
					}
				}
			}
		}
		foreach(["", "\x80", str_repeat("\x80", 6), "\x01", "\x02\x00\x00\x00\x00\x00\x00"] as $buffer){
			$original = [$this->action()];
			$transaction = NormalTransactionData::new($original);
			try{
				$transaction->decode(new ByteBufferReader($buffer));
				self::fail("Malformed transaction accepted");
			}catch(DataDecodeException $e){
				self::assertSame($original, $transaction->getActions());
				$wrapped = PacketDecodeException::wrap($e);
				self::assertSame([null, null], [$wrapped->observed, $wrapped->limit]);
			}
		}
	}

	public function testBothPacketPathsPreserveFieldsAndLimitEvidence() : void{
		foreach([0, 1, 50, 51, 100, 101] as $count){
			$actions = array_fill(0, $count, $this->action());
			if($count > 0){ $actions[0] = clone $actions[0]; $actions[0]->inventorySlot = 255; }
			$transaction = UseItemTransactionData::new($actions, 0, TriggerType::PLAYER_INPUT, new BlockPosition(0, 0, 0), 0, 0, HandSlot::MAINHAND, ItemStackWrapper::legacy(ItemStack::null()), new Vector3(1, 2, 3), new Vector3(0, 0, 0), 0, PredictedResult::SUCCESS, 0);
			foreach([
				InventoryTransactionPacket::create(-7, null, NormalTransactionData::new($actions)),
				PlayerAuthInputPacket::create(
					new Vector3(1, 2, 3), 4, 5, 6, 0, 0, new BitSet(PlayerAuthInputFlags::NUMBER_OF_FLAGS),
					1, 0, 0, new Vector2(0, 0), 123, new Vector3(0, 0, 0),
					new ItemInteractionData(-7, null, $transaction), null, null, null,
					0, 0, new Vector3(0, 0, 0), new Vector2(0, 0)
				)
			] as $packet){
				$packet->senderSubId = 2;
				$packet->recipientSubId = 3;
				$original = clone $packet;
				$out = new ByteBufferWriter();
				$packet->encode($out);
				$in = new ByteBufferReader($out->getData());
				try{
					$packet->decode($in);
					self::assertLessThanOrEqual(TransactionData::MAX_ACTIONS, $count);
					self::assertEquals($original, $packet);
					self::assertSame(0, $in->getUnreadLength());
					$encoded = new ByteBufferWriter();
					$packet->encode($encoded);
					self::assertSame($out->getData(), $encoded->getData());
				}catch(PacketDecodeException $e){
					self::assertSame(101, $count);
					self::assertSame([101, TransactionData::MAX_ACTIONS], [$e->observed, $e->limit]);
					self::assertInstanceOf(PacketDecodeException::class, $e->getPrevious());
				}
			}
		}
	}
}
