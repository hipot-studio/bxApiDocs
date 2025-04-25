<?php

namespace Bitrix\Call\DTO;

class CallTokenRequest extends Hydrator
{
	public int $chatId = 0;
	public array|null $additionalData = null;

	public function __construct(?array $fields = null)
	{
		if ($fields)
		{
			parent::__construct((object) $fields);
		}
	}
}