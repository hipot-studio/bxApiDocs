<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Async\Receiver;

use Bitrix\Main\Loader;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Internals\Task\ProjectLastActivityTable;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Add\Async\Message;
use CSocNetGroup;
use Throwable;

class AddLastActivity extends AbstractReceiver
{
	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof Message\AddLastActivity)
		{
			return;
		}

		$id = (int)($message->fields['ID'] ?? 0);
		$repository = Container::getInstance()->getTaskRepository();
		if (!$repository->isExists($id))
		{
			return;
		}

		try
		{
			$activityDate = DateTime::createFromUserTime($message->fields['ACTIVITY_DATE']);
		}
		catch (Throwable $t)
		{
			$logger = Container::getInstance()->getLogger();

			$logger->logWarning($message->fields, 'ADD_LAST_ACT');
			$logger->logError($t);

			return;
		}

		ProjectLastActivityTable::update(
			$message->fields['GROUP_ID'],
			['ACTIVITY_DATE' => $activityDate],
		);

		if (Loader::includeModule('socialnetwork'))
		{
			CSocNetGroup::SetLastActivity($message->fields['GROUP_ID']);
		}
	}
}
