<?php

declare(strict_types=1);

namespace Bitrix\Main\Messenger\Internals\Exception\Receiver;

use Bitrix\Main\Messenger\Entity\MessageBox;
use Bitrix\Main\Messenger\Internals\Exception\MessageBoxAwareExceptionTrait;
use Bitrix\Main\Messenger\Internals\Exception\MessageBoxAwareExceptionInterface;
use Bitrix\Main\Messenger\Internals\Exception\RuntimeException;

class ProcessingException extends RuntimeException implements MessageBoxAwareExceptionInterface
{
	use MessageBoxAwareExceptionTrait;

	public function __construct(MessageBox $messageBox, \Throwable $previous)
	{
		$message = sprintf(
			'Message processing exception: "%s". Queue: "%s". Message: "%s". ItemId: "%s"',
			$previous->getMessage(),
			$messageBox->getQueueId(),
			$messageBox->getId(),
			$messageBox->getItemId()
		);

		parent::__construct($message, $previous->getCode(), $previous);

		$this->messageBox = $messageBox;
	}
}
