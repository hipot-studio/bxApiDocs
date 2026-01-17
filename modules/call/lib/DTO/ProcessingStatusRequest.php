<?php

namespace Bitrix\Call\DTO;

class ProcessingStatusRequest extends Hydrator
{
	public string $roomId = '';
	public string $status = '';
	public string $message = '';
}
