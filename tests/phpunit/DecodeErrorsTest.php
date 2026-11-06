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
use pmmp\encoding\LE;
use pmmp\encoding\VarInt;
use function hex2bin;

/**
 * Malformed input must only ever surface as PacketDecodeException, since that is all a server catches.
 */
final class DecodeErrorsTest extends TestCase{

	private static function decode(Packet $packet, string $bytes) : void{
		$packet->decode(new ByteBufferReader($bytes));
	}

	public function testUnknownInputFlagIsMalformed() : void{
		$out = new ByteBufferWriter();
		VarInt::writeUnsignedInt($out, ProtocolInfo::PLAYER_AUTH_INPUT_PACKET);
		for($i = 0; $i < 8; ++$i){
			LE::writeFloat($out, 0.0);
		}
		VarInt::writeUnsignedInt($out, 1);
		VarInt::writeSignedInt($out, 1000);

		$this->expectException(PacketDecodeException::class);
		self::decode(new PlayerAuthInputPacket(), $out->getData());
	}

	public function testTruncatedNbtIsMalformed() : void{
		$bytes = hex2bin("7c" . "00" . "0a00");
		self::assertNotFalse($bytes);

		$this->expectException(PacketDecodeException::class);
		self::decode(new LevelEventGenericPacket(), $bytes);
	}

	public function testUnknownTextTypeIsMalformed() : void{
		$bytes = hex2bin("09" . "00" . "00" . "0e" . "00" . "00" . "00");
		self::assertNotFalse($bytes);

		$this->expectException(PacketDecodeException::class);
		self::decode(new TextPacket(), $bytes);
	}
}
