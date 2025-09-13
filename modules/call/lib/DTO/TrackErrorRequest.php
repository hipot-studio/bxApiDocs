<?php

namespace Bitrix\Call\DTO;

class TrackErrorRequest extends Hydrator
{
	public string $callUuid = '';
	public string $errorCode = '';

	public function __construct(?array $fields = null)
	{
		if ($fields)
		{
			parent::__construct((object) $fields);
		}
	}
}
