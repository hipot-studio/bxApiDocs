<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Im\Result;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class ChatCreationResult extends Result
{
	public function __construct(
		public readonly int $chatId = 0,
		public readonly ?int $messageId = null,
		public readonly bool $alreadyExists = false,
	)
	{
		parent::__construct();

		if ($this->chatId > 0)
		{
			$this->setData([
				'chatId' => $this->chatId,
				'CHAT_ID' => $this->chatId,
				'messageId' => $this->messageId,
				'MESSAGE_ID' => $this->messageId,
				'alreadyExists' => $this->alreadyExists,
				'ALREADY_EXISTS' => $this->alreadyExists,
			]);
		}
	}

	public static function createWithError(Error $error): self
	{
		$result = new self();
		$result->addError($error);

		return $result;
	}

	public static function createWithErrors(array $errors): self
	{
		$result = new self();
		$result->addErrors($errors);

		return $result;
	}
}
