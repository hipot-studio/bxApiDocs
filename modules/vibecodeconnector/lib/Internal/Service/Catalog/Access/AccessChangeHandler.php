<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Access;

use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppChatMembershipService;

final class AccessChangeHandler
{
	public function __construct(
		private readonly AppChatMembershipService $membershipService = new AppChatMembershipService(),
	) {}

	public function handle(CatalogItem $item): void
	{
		if ($item->getType() !== CatalogItemType::Application)
		{
			return;
		}

		try
		{
			$this->membershipService->scheduleItemSync((int)$item->getId());
		}
		catch (\Throwable $exception)
		{
			$this->logAppChatFailure($item, $exception, 'alignment was not scheduled');
		}

		try
		{
			$this->membershipService->subscribeOwner($item);
		}
		catch (\Throwable $exception)
		{
			$this->logAppChatFailure($item, $exception, 'the owner was not subscribed');
		}
	}

	private function logAppChatFailure(CatalogItem $item, \Throwable $exception, string $what): void
	{
		$this->writeLog(
			'Catalog item ' . (int)$item->getId() . ': app chat membership ' . $what . ', '
				. $exception->getMessage(),
		);
	}

	private function writeLog(string $message): void
	{
		try
		{
			\AddMessage2Log($message, 'vibecodeconnector');
		}
		catch (\Throwable)
		{
		}
	}
}
