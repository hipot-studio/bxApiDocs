<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Forward;

use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Error;

final class ForwardResult extends Result
{
	/** @var array<int, true> */
	private array $successfulMessageObjectIds = [];
	/** @var array<int, string> */
	private array $legacyFailureMap = [];

	public function markMessageAsSuccessful(Message $message): self
	{
		$this->successfulMessageObjectIds[spl_object_id($message)] = true;

		return $this;
	}

	public function hasSuccessfulMessages(): bool
	{
		return !empty($this->successfulMessageObjectIds);
	}

	public function isMessageSuccessful(Message $message): bool
	{
		return isset($this->successfulMessageObjectIds[spl_object_id($message)]);
	}

	public function markMessageAsFailed(Message $message, Error $error): self
	{
		$messageId = $message->getId();
		$code = (string)$error->getCode();
		if ($message->getForwardUuid() === null && $messageId > 0 && $code !== '')
		{
			$this->legacyFailureMap[$messageId] = $code;
		}

		return $this;
	}

	public function getLegacyFailureMap(): array
	{
		return $this->legacyFailureMap;
	}
}
