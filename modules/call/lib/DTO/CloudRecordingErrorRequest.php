<?php

namespace Bitrix\Call\DTO;

class CloudRecordingErrorRequest extends Hydrator
{
	public string $roomId = '';
	public string $errorCode = '';
	public string $errorMessage = '';
}