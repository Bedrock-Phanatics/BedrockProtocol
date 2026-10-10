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
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CraftRecipeStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequest;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\LabTableCombineStackRequestAction;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use function array_fill;

final class ItemStackRequestTest extends TestCase{

	public function testActionBoundariesRoundTrip() : void{
		foreach([0, 1, ItemStackRequest::MAX_ACTIONS] as $count){
			$actions = array_fill(0, $count, new LabTableCombineStackRequestAction());
			if($count > 0){ $actions[0] = new CraftRecipeStackRequestAction(7, 2); }
			$request = new ItemStackRequest(-7, $actions, ["filter", "unicode \u{1f600}"], 3);
			$out = new ByteBufferWriter();
			$request->write($out);
			$in = new ByteBufferReader($out->getData());
			self::assertEquals($request, ItemStackRequest::read($in));
			self::assertSame(0, $in->getUnreadLength());
		}
		foreach([61, 100000, 0xffffffff] as $count){
			$out = new ByteBufferWriter();
			CommonTypes::writeItemStackRequestId($out, 7);
			VarInt::writeUnsignedInt($out, $count);
			$in = new ByteBufferReader($out->getData());
			try{
				ItemStackRequest::read($in);
				self::fail("Oversized action list accepted");
			}catch(PacketDecodeException $e){
				self::assertSame([$count, ItemStackRequest::MAX_ACTIONS], [$e->observed, $e->limit]);
				self::assertSame(0, $in->getUnreadLength());
			}
		}
	}

	public function testBothPacketPathsPreserveLimitEvidence() : void{
		$request = new ItemStackRequest(7, array_fill(0, 61, new LabTableCombineStackRequestAction()), [], 0);
		$packets = [
			ItemStackRequestPacket::create([$request]),
			PlayerAuthInputPacket::create(new Vector3(1, 2, 3), 4, 5, 6, 0, 0, new BitSet(PlayerAuthInputFlags::NUMBER_OF_FLAGS), 1, 0, 0, new Vector2(0, 0), 123, new Vector3(0, 0, 0), null, $request, null, null, 0, 0, new Vector3(0, 0, 0), new Vector2(0, 0))
		];
		foreach($packets as $packet){
			$out = new ByteBufferWriter();
			$packet->encode($out);
			try{
				$packet->decode(new ByteBufferReader($out->getData()));
				self::fail("Packet accepted oversized nested request");
			}catch(PacketDecodeException $e){
				self::assertSame([61, 60], [$e->observed, $e->limit]);
				self::assertStringStartsWith($packet->getName() . ": ", $e->getMessage());
				self::assertInstanceOf(PacketDecodeException::class, $e->getPrevious());
				$wrapped = PacketDecodeException::wrap($e, "outer");
				self::assertSame([61, 60], [$wrapped->observed, $wrapped->limit]);
				self::assertSame($e, $wrapped->getPrevious());
			}
		}
	}

	public function testMalformedCountsAndPlainExceptionsKeepTheirMeaning() : void{
		foreach(["", "\x80", "\x80\x80\x80\x80\x80\x80"] as $buffer){
			try{
				ItemStackRequest::read(new ByteBufferReader("\x0e" . $buffer));
				self::fail("Malformed count accepted");
			}catch(DataDecodeException $e){
				self::assertSame([null, null], [PacketDecodeException::wrap($e)->observed, PacketDecodeException::wrap($e)->limit]);
			}
		}
		$previous = new \RuntimeException("previous");
		$plain = new PacketDecodeException("plain", 123, $previous);
		self::assertSame(["plain", 123, $previous, null, null], [$plain->getMessage(), $plain->getCode(), $plain->getPrevious(), $plain->observed, $plain->limit]);
		self::assertSame("prefix: plain", PacketDecodeException::wrap($plain, "prefix")->getMessage());
	}
}
