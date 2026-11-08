<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\MailTemplate;

use Bitrix\Crm\V2\Internal\Repository\MailTemplate\LegacyMailTemplateRepository;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class MailTemplateCreationService
{
	public const ERROR_INVALID_USER_ID = 'CRM_MAIL_TEMPLATE_INVALID_USER_ID';
	public const ERROR_CREATION_FAILED = 'CRM_MAIL_TEMPLATE_CREATION_FAILED';

	public function __construct(
		private readonly LegacyMailTemplateRepository $repository = new LegacyMailTemplateRepository(),
	)
	{
	}

	/**
	 * @param list<int> $diskObjectIds
	 * @return Result Data shape on success: array{templateId: int}
	 */
	public function createPersonalUniversal(
		string $subject,
		string $bodyHtml,
		string $emailFrom,
		array $diskObjectIds,
		int $userId,
	): Result
	{
		$result = new Result();
		if ($userId <= 0)
		{
			return $result->addError(new Error(
				'User id is invalid.',
				self::ERROR_INVALID_USER_ID,
			));
		}

		$connection = null;
		$transactionStarted = false;
		try
		{
			$connection = Application::getConnection();
			$connection->startTransaction();
			$transactionStarted = true;
			$templateId = $this->repository->addPersonalUniversal(
				subject: $subject,
				bodyHtml: $bodyHtml,
				emailFrom: $emailFrom,
				diskObjectIds: $diskObjectIds,
				userId: $userId,
			);
			if ($templateId <= 0)
			{
				$failure = $this->addLegacyErrors($result);
				$this->rollbackTransaction($connection, $transactionStarted);

				return $failure;
			}

			$connection->commitTransaction();
			$transactionStarted = false;
		}
		catch (\Throwable)
		{
			if ($connection instanceof Connection)
			{
				$this->rollbackTransaction($connection, $transactionStarted);
			}

			return $result->addError(new Error(
				'Mail template creation failed.',
				self::ERROR_CREATION_FAILED,
			));
		}

		return $result->setData([
			'templateId' => $templateId,
		]);
	}

	private function addLegacyErrors(Result $result): Result
	{
		$hasError = false;
		$errorMessages = $this->repository->getLastErrorMessages();
		foreach ($errorMessages as $errorMessage)
		{
			$result->addError(new Error(
				$errorMessage,
				self::ERROR_CREATION_FAILED,
			));
			$hasError = true;
		}

		if (!$hasError)
		{
			$result->addError(new Error(
				'Mail template creation failed.',
				self::ERROR_CREATION_FAILED,
			));
		}

		return $result;
	}

	private function rollbackTransaction(Connection $connection, bool &$transactionStarted): void
	{
		if (!$transactionStarted)
		{
			return;
		}

		$transactionStarted = false;
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException)
		{
			// MySQL restores the savepoint before reporting that nested rollbacks are unsupported.
		}
	}
}
