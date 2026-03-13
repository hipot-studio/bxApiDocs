<?php

namespace Bitrix\Call\DTO;

/**
 * @internal
 */
class TrackErrorRequest extends Hydrator
{
	public string $callUuid = '';
	public string $errorCode = '';
}
