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

use Bedwire\Nbt as NativeNbt;
use Bedwire\NbtException;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\VarInt;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\Tag;

class LevelEventGenericPacket extends DataPacket implements ClientboundPacket{
	public const NETWORK_ID = ProtocolInfo::LEVEL_EVENT_GENERIC_PACKET;

	private int $eventId;
	private Tag $eventData;

	/**
	 * @generate-create-func
	 */
	public static function create(int $eventId, Tag $eventData) : self{
		$result = new self;
		$result->eventId = $eventId;
		$result->eventData = $eventData;
		return $result;
	}

	public function getEventId() : int{
		return $this->eventId;
	}

	public function getEventData() : Tag{
		return $this->eventData;
	}

	protected function decodePayload(ByteBufferReader $in) : void{
		$this->eventId = VarInt::readSignedInt($in);
		try{
			[$this->eventData, $offset] = NativeNbt::readHeadlessAt($in->getData(), NativeNbt::NETWORK, NBT::TAG_Compound, $in->getOffset());
		}catch(NbtException $e){
			throw PacketDecodeException::wrap($e);
		}
		$in->setOffset($offset);
	}

	protected function encodePayload(ByteBufferWriter $out) : void{
		VarInt::writeSignedInt($out, $this->eventId);
		$out->writeByteArray(NativeNbt::writeHeadless($this->eventData, NativeNbt::NETWORK));
	}

	public function handle(PacketHandlerInterface $handler) : bool{
		return $handler->handleLevelEventGeneric($this);
	}
}
