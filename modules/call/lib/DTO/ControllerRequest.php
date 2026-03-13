<?php

namespace Bitrix\Call\DTO;

/**
 * @internal
 */
class ControllerRequest extends Hydrator
{
	public string $callUuid = '';
	public int $userId = 0;
}
