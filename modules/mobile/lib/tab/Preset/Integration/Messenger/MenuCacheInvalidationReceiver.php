<?php

namespace Bitrix\Mobile\Tab\Preset\Integration\Messenger;

use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Mobile\Tab\Preset\Service\MobileMenuCacheInvalidator;

final class MenuCacheInvalidationReceiver extends AbstractReceiver
{
	public function __construct(
		private readonly MobileMenuCacheInvalidator $cacheInvalidator = new MobileMenuCacheInvalidator(),
	)
	{
	}

	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof MenuCacheInvalidationMessage)
		{
			throw new UnprocessableMessageException(
				$message,
				'Expected ' . MenuCacheInvalidationMessage::class,
			);
		}

		$this->cacheInvalidator->clearUsers($message->userIds);
	}
}
