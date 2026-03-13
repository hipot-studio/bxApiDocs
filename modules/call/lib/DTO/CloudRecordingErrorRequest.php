<?php

namespace Bitrix\Call\DTO;

/**
 * @internal
 */
class CloudRecordingErrorRequest extends Hydrator
{
	public string $roomId = '';
	public string $errorCode = '';
	public string $errorMessage = '';
}