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
use pocketmine\network\mcpe\protocol\types\camera\CameraAimAssistTargetMode;
use pocketmine\network\mcpe\protocol\types\camera\CameraPresetAimAssist;
use pocketmine\network\mcpe\protocol\types\EducationSettingsExternalLinkSettings;

class WireLayoutTest extends TestCase{

	public function testCameraPresetAimAssistTargetModeIsInt32() : void{
		$bytes = "\x00\x01\x01\x00\x00\x00\x00\x00";
		$in = new ByteBufferReader($bytes);
		$aimAssist = CameraPresetAimAssist::read($in);
		self::assertSame(0, $in->getUnreadLength());
		self::assertSame(CameraAimAssistTargetMode::DISTANCE, $aimAssist->getTargetMode());

		$out = new ByteBufferWriter();
		$aimAssist->write($out);
		self::assertSame($bytes, $out->getData());
	}

	public function testExternalLinkSettingsKeepFieldOrder() : void{
		$bytes = "\x03url\x04name";
		$settings = EducationSettingsExternalLinkSettings::read(new ByteBufferReader($bytes));
		self::assertSame("url", $settings->getUrl());
		self::assertSame("name", $settings->getDisplayName());

		$out = new ByteBufferWriter();
		$settings->write($out);
		self::assertSame($bytes, $out->getData());
	}
}
