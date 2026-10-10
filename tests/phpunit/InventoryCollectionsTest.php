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
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\inventory\HandSlot;
use pocketmine\network\mcpe\protocol\types\inventory\InventoryTransactionChangedSlotsHack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\inventory\NormalTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\PredictedResult;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CraftRecipeAutoStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\DeprecatedCraftingResultsEntry;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\DeprecatedCraftingResultsStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequest;
use pocketmine\network\mcpe\protocol\types\inventory\TriggerType;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\network\mcpe\protocol\types\ItemInteractionData;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeIngredient;
use function array_fill;
use function array_values;
use function range;
use function str_repeat;

final class InventoryCollectionsTest extends TestCase{
	public function testOversizedAndMalformedPrefixesFailBeforeReadingElements() : void{
		foreach([
			[InventoryTransactionChangedSlotsHack::MAX_CHANGED_CONTAINERS, "\x0d\x01", ItemInteractionData::read(...)],
			[InventoryTransactionChangedSlotsHack::MAX_CHANGED_CONTAINERS, "\x1e\x00\x01", static fn(ByteBufferReader $in) => (new InventoryTransactionPacket())->decode($in)],
			[ItemStackRequest::MAX_FILTER_STRINGS, "\x00\x00", ItemStackRequest::read(...)],
			[CraftRecipeAutoStackRequestAction::MAX_INGREDIENTS, "\x07\x02", CraftRecipeAutoStackRequestAction::read(...)],
			[DeprecatedCraftingResultsStackRequestAction::MAX_RESULTS, "", DeprecatedCraftingResultsStackRequestAction::read(...)]
		] as [$limit, $prefix, $read]){
			foreach([$limit + 1, 100000, 0xffffffff] as $count){
				$out = new ByteBufferWriter($prefix);
				VarInt::writeUnsignedInt($out, $count);
				foreach(["", "\xff"] as $tail){
					$in = new ByteBufferReader($out->getData() . $tail);
					try{ $read($in); self::fail("Oversized list accepted"); }catch(PacketDecodeException $e){
						self::assertSame([$count, $limit], [$e->observed, $e->limit]);
						self::assertSame($tail, $in->getUnreadLength() === 0 ? "" : $in->readByteArray(1));
					}
				}
			}
			foreach(["", "\x80", str_repeat("\x80", 6), "\x01"] as $suffix){
				try{ $read(new ByteBufferReader($prefix . $suffix)); self::fail("Malformed list accepted"); }catch(DataDecodeException|PacketDecodeException $e){
					self::assertSame([null, null], [PacketDecodeException::wrap($e)->observed, PacketDecodeException::wrap($e)->limit]);
				}
			}
		}
	}

	public function testBoundedListReaderPreservesDefaultAndZeroLimits() : void{
		foreach([0, 1, 300] as $count){
			$out = new ByteBufferWriter();
			CommonTypes::writeList($out, array_fill(0, $count, ""), CommonTypes::putString(...));
			self::assertCount($count, CommonTypes::readList(new ByteBufferReader($out->getData()), CommonTypes::getString(...)));
			$in = new ByteBufferReader($out->getData());
			try{
				self::assertSame([], CommonTypes::readList($in, CommonTypes::getString(...), 0));
				self::assertSame(0, $count);
			}catch(PacketDecodeException $e){
				self::assertSame([$count, 0], [$e->observed, $e->limit]);
				self::assertSame($count, $in->getUnreadLength());
			}
		}
	}

	public function testNestedCollectionBoundariesPreserveBothPacketPaths() : void{
		foreach([null, [], [new InventoryTransactionChangedSlotsHack(2, [255, 0, 1])], array_fill(0, 10, new InventoryTransactionChangedSlotsHack(2, range(0, 255)))] as $slots){
			$transaction = UseItemTransactionData::new([], 0, TriggerType::PLAYER_INPUT, new BlockPosition(1, 2, 3), 2, 7, HandSlot::MAINHAND, ItemStackWrapper::legacy(ItemStack::null()), new Vector3(1, 2, 3), new Vector3(0, 0, 0), 4, PredictedResult::SUCCESS, 0);
			foreach([
				InventoryTransactionPacket::create(-7, $slots, NormalTransactionData::new([])),
				$this->movement(new ItemInteractionData(-7, $slots, $transaction), null)
			] as $packet){ $this->roundTrip($packet); }
		}
		foreach([0, 1, 128] as $count){
			$craft = new CraftRecipeAutoStackRequestAction(7, 2, array_fill(0, $count, new RecipeIngredient(null, 1)));
			$request = new ItemStackRequest(-7, [$craft], [], 0);
			foreach([ItemStackRequestPacket::create([$request]), $this->movement(null, $request)] as $packet){ $this->roundTrip($packet); }
		}
		foreach([0, 1, 256] as $count){
			$results = new DeprecatedCraftingResultsStackRequestAction(array_fill(0, $count, new DeprecatedCraftingResultsEntry(null, 1, 4, "")), 2);
			$filters = array_fill(0, $count, "filter \u{1f600}");
			if($count > 0){ $filters[$count - 1] = ""; }
			$request = new ItemStackRequest(-7, [$results], array_values($filters), 3);
			foreach([ItemStackRequestPacket::create([$request]), $this->movement(null, $request)] as $packet){ $this->roundTrip($packet); }
		}
	}

	private function movement(?ItemInteractionData $interaction, ?ItemStackRequest $request) : PlayerAuthInputPacket{
		return PlayerAuthInputPacket::create(new Vector3(1, 2, 3), 4, 5, 6, 0, 0, new BitSet(PlayerAuthInputFlags::NUMBER_OF_FLAGS), 1, 0, 0, new Vector2(0, 0), 123, new Vector3(0, 0, 0), $interaction, $request, null, null, 0, 0, new Vector3(0, 0, 0), new Vector2(0, 0));
	}

	private function roundTrip(DataPacket $packet) : void{
		$packet->senderSubId = 2;
		$packet->recipientSubId = 3;
		$out = new ByteBufferWriter();
		$packet->encode($out);
		$original = clone $packet;
		$in = new ByteBufferReader($out->getData());
		$packet->decode($in);
		self::assertEquals($original, $packet);
		self::assertSame(0, $in->getUnreadLength());
		$encoded = new ByteBufferWriter();
		$packet->encode($encoded);
		self::assertSame($out->getData(), $encoded->getData());
	}
}
