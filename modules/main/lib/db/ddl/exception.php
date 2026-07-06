<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl;

class Exception extends \Bitrix\Main\SystemException
{
	public function __construct(
		int $code,
		string $message,
		private readonly array $messageParameters = [],
	)
	{
		parent::__construct($message, $code, null);
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
}
