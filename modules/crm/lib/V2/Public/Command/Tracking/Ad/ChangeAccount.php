<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Tracking\Ad;

use Bitrix\Crm\V2\Internal\Entity\Tracking\Ad\Binding;
use Bitrix\Crm\V2\Internal\Service\Tracking\Ad\AccountService;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;

final class ChangeAccount
{
	private readonly AccountService $service;
	private readonly Connection $connection;

	public function __construct(?AccountService $service = null, ?Connection $connection = null)
	{
		$this->connection = $connection ?? Application::getConnection();
		$this->service = $service ?? new AccountService($this->connection);
	}

	/** A null client disconnects only the CRM binding; shared OAuth and expenses remain untouched. */
	public function execute(int $userId, int $sourceId, ?string $clientId = null, ?string $accountId = null): Binding
	{
		$expected = $this->service->prepareChange($userId, $sourceId, $clientId, $accountId);
		$this->connection->startTransaction();
		try
		{
			$binding = $this->service->applyChange($expected, $clientId, $accountId);
			$this->connection->commitTransaction();

			return $binding;
		}
		catch (\Throwable $exception)
		{
			try
			{
				$this->connection->rollbackTransaction();
			}
			catch (TransactionException $rollback)
			{
				// The framework throws after successfully rolling back a nested savepoint.
				if ($rollback->getMessage() !== 'Nested rollbacks are unsupported.')
				{
					throw $rollback;
				}
			}
			throw $exception;
		}
	}
}
