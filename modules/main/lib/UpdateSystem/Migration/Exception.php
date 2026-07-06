<?php

declare(strict_types=1);

namespace Bitrix\Main\UpdateSystem\Migration;

class Exception extends \Bitrix\Main\SystemException
{
	public function __construct(
		private readonly string $moduleId,
		int $code,
		string $message,
		private readonly array $messageParameters = [],
	)
	{
		parent::__construct($message, $code, null);
	}

	public function getModuleId(): string
	{
		return $this->moduleId;
	}

	public function getMessageParameters(): array
	{
		$result = [];
		foreach ($this->messageParameters as $id => $value)
		{
			$result['#' . $id . '#'] = $value;
		}

		return $result;
	}

	public function setMessage(string $message): void
	{
		$this->message = $message;
	}
}
