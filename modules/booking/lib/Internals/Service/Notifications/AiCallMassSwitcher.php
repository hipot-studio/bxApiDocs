<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications;

use Bitrix\Booking\Internals\Integration\Bizproc\AiAgentLauncher;
use Bitrix\Booking\Internals\Integration\Bizproc\AiCallMessageSender;
use Bitrix\Booking\Internals\Model\ResourceNotificationSettingsTable;
use Bitrix\Booking\Internals\Model\ResourceTypeNotificationSettingsTable;
use Bitrix\Booking\Internals\Repository\TransactionHandlerInterface;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Result;

class AiCallMassSwitcher
{
	private const MASS_SWITCHED_OPTION_NAME = 'ai_call_mass_switched';

	public function __construct(
		private readonly AiCallMessageSender $aiCallMessageSender,
		private readonly TransactionHandlerInterface $transactionHandler,
		private readonly AiAgentLauncher $aiAgentLauncher,
	)
	{
	}

	public function isSwitched(): bool
	{
		return Option::get('booking', self::MASS_SWITCHED_OPTION_NAME, 'N') === 'Y';
	}

	public function switchAllToAiCall(): Result
	{
		$result = new Result();

		if ($this->isSwitched())
		{
			return $result;
		}

		if (!$this->aiCallMessageSender->canUse())
		{
			return $result;
		}

		$launchResult = $this->aiAgentLauncher->ensureRunning();
		if (!$launchResult->isSuccess())
		{
			return $result->addErrors($launchResult->getErrors());
		}

		$this->transactionHandler->handle(
			function (): void
			{
				$connection = Application::getConnection();
				$helper = $connection->getSqlHelper();

				$target = $helper->forSql($this->aiCallMessageSender->getCode());
				$resourceTable = $helper->quote(ResourceNotificationSettingsTable::getTableName());
				$resourceTypeTable = $helper->quote(ResourceTypeNotificationSettingsTable::getTableName());

				$connection->queryExecute("UPDATE {$resourceTable} SET SENDER_CODE = '{$target}'");
				$connection->queryExecute("UPDATE {$resourceTypeTable} SET SENDER_CODE = '{$target}'");

				Option::set('booking', self::MASS_SWITCHED_OPTION_NAME, 'Y');
			},
			\Exception::class,
		);

		return $result;
	}
}
