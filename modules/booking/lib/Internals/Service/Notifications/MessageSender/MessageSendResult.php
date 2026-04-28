<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\MessageSender;

use Bitrix\Main\Result;

class MessageSendResult extends Result
{
	private string|null $id;

	public function getId(): string|null
	{
		return $this->id;
	}

	public function setId(string|null $id): MessageSendResult
	{
		$this->id = $id;

		return $this;
	}
}
