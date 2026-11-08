<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Disk;

use Bitrix\Disk\Internal\Service\Audit\AuditTransaction;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;

final class MailTemplateEditorTransaction
{
	public function run(callable $callback): int
	{
		$save = fn(): int => $this->requireSavedId($callback());
		Loader::includeModule('disk');
		if (class_exists(AuditTransaction::class))
		{
			// UF orphan cleanup must join the same transaction as the template and its access rules.
			return $this->requireSavedId(AuditTransaction::run($save));
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$id = $save();
			$connection->commitTransaction();

			return $id;
		}
		catch (\Throwable $exception)
		{
			try
			{
				$connection->rollbackTransaction();
			}
			catch (\Throwable $rollbackException)
			{
				Application::getInstance()->getExceptionHandler()->writeToLog($rollbackException);
			}

			throw $exception;
		}
	}

	private function requireSavedId(mixed $id): int
	{
		if (!is_int($id) || $id <= 0)
		{
			throw new SystemException('Mail template could not be saved.');
		}

		return $id;
	}
}
