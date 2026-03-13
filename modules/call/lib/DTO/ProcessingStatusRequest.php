<?php

namespace Bitrix\Call\DTO;

/**
 * @internal
 */
class ProcessingStatusRequest extends Hydrator
{
	public string $roomId = '';
	public string $status = '';
	public string $message = '';
}
