<?php

namespace Bitrix\Call\DTO;

/**
 * @internal
 */
class CallTokenRequest extends Hydrator
{
	public int $chatId = 0;
	public array|null $additionalData = null;
}