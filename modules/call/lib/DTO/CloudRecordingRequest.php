<?php

namespace Bitrix\Call\DTO;

class CloudRecordingRequest extends Hydrator
{
	public int $chatId = 0;
	public string $roomId = '';
	public ?array $recording = [];
	public ?array $preview = [];
}
