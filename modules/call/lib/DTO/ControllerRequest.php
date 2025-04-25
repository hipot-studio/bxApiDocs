<?php

namespace Bitrix\Call\DTO;

class ControllerRequest extends Hydrator
{
	public string $callUuid = '';
	public int $userId = 0;

	public function __construct(?array $fields = null)
	{
		if ($fields)
		{
			parent::__construct((object) $fields);
		}
	}
}
