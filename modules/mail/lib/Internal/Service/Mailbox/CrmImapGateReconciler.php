<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Mailbox;

use Bitrix\Mail\Helper\Enum\CrmFlag;
use Bitrix\Mail\Helper\Enum\CrmImapFilterContext;
use Bitrix\Mail\Helper\Enum\MailboxStatus;
use Bitrix\Mail\Helper\Mailbox\CrmImapFilter;
use Bitrix\Mail\Internal\Repository\CrmImapGateMarkerRepository;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

final class CrmImapGateReconciler
{
	public const ERROR_INVALID_MAILBOX_ID = 'MAIL_CRM_IMAP_GATE_INVALID_MAILBOX_ID';
	public const ERROR_CRM_UNAVAILABLE = 'MAIL_CRM_IMAP_GATE_CRM_UNAVAILABLE';
	public const ERROR_FUTURE_MARKER_CONFLICT = 'MAIL_CRM_IMAP_GATE_FUTURE_MARKER_CONFLICT';
	public const ERROR_MARKER_WRITE_FAILED = 'MAIL_CRM_IMAP_GATE_MARKER_WRITE_FAILED';
	public const ERROR_MARKER_INVALIDATION_FAILED = 'MAIL_CRM_IMAP_GATE_MARKER_INVALIDATION_FAILED';
	public const ERROR_RECONCILE_FAILED = 'MAIL_CRM_IMAP_GATE_RECONCILE_FAILED';

	private const CURRENT_VERSION = 1;
	private const SUPPORTED_SERVER_TYPES = ['imap', 'controller', 'domain', 'crdomain'];

	private readonly \Closure $crmAvailable;
	private readonly \Closure $filterSynchronizer;

	public function __construct(
		private readonly CrmImapGateMarkerRepository $markerRepository = new CrmImapGateMarkerRepository(),
		?\Closure $crmAvailable = null,
		?\Closure $filterSynchronizer = null,
	)
	{
		$this->crmAvailable = $crmAvailable ?? static fn (): bool => Loader::includeModule('crm');
		$this->filterSynchronizer = $filterSynchronizer ?? static fn (int $mailboxId, bool $desired): Result =>
			CrmImapFilter::sync(
				$mailboxId,
				$desired,
				CrmImapFilterContext::SettingsSave,
				lockFilters: true,
			);
	}

	public function ensureReady(int $mailboxId): Result
	{
		if ($mailboxId <= 0)
		{
			return $this->error('Invalid mailbox ID', self::ERROR_INVALID_MAILBOX_ID);
		}

		$connection = Application::getConnection();
		$started = false;
		try
		{
			$connection->startTransaction();
			$started = true;
			$mailbox = $this->markerRepository->lockMailbox($mailboxId);
			if (!$this->isSupportedMailbox($mailbox))
			{
				$connection->commitTransaction();

				return (new Result())->setData(['skipped' => true]);
			}

			$desired = in_array(CrmFlag::Connect->value, (array)($mailbox['OPTIONS']['flags'] ?? []), true);
			$marker = $this->parseMarker($this->markerRepository->read($mailboxId));
			if ($this->isSatisfied($marker, $desired))
			{
				$connection->commitTransaction();

				return (new Result())->setData(['skipped' => true, 'desired' => $desired]);
			}

			CrmImapFilter::fetchFilters($mailboxId, true);
			$marker = $this->parseMarker($this->markerRepository->read($mailboxId, true));
			if ($this->isSatisfied($marker, $desired))
			{
				$connection->commitTransaction();

				return (new Result())->setData(['skipped' => true, 'desired' => $desired]);
			}
			if (($marker['version'] ?? 0) > self::CURRENT_VERSION && $desired)
			{
				return $this->rollback(
					$connection,
					$this->error('A newer CRM IMAP gate marker has a conflicting state', self::ERROR_FUTURE_MARKER_CONFLICT),
				);
			}
			if ($desired && !($this->crmAvailable)())
			{
				return $this->rollback(
					$connection,
					$this->error('The CRM module is not available', self::ERROR_CRM_UNAVAILABLE),
				);
			}

			$syncResult = ($this->filterSynchronizer)($mailboxId, $desired);
			if (!$syncResult->isSuccess())
			{
				$syncResult->addError(new Error('The CRM IMAP gate was not reconciled', self::ERROR_RECONCILE_FAILED));

				return $this->rollback($connection, $syncResult);
			}
			if (($marker['version'] ?? 0) <= self::CURRENT_VERSION)
			{
				$writeResult = $this->writeSatisfiedMarkerWithinTransaction($mailboxId, $desired);
				if (!$writeResult->isSuccess())
				{
					return $this->rollback($connection, $writeResult);
				}
			}

			$connection->commitTransaction();
			$started = false;

			return (new Result())->setData(['reconciled' => true, 'desired' => $desired]);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
			if ($started)
			{
				try
				{
					$connection->rollbackTransaction();
				}
				catch (\Throwable $rollbackException)
				{
					Application::getInstance()->getExceptionHandler()->writeToLog($rollbackException);
				}
			}

			return $this->error('The CRM IMAP gate was not reconciled', self::ERROR_RECONCILE_FAILED);
		}
	}

	public function writeSatisfiedMarkerWithinTransaction(
		int $mailboxId,
		bool $desired,
		int $version = self::CURRENT_VERSION,
	): Result
	{
		try
		{
			$marker = $this->parseMarker($this->markerRepository->read($mailboxId, true));
			if (($marker['version'] ?? 0) > self::CURRENT_VERSION)
			{
				return ($marker['desired'] ?? null) === $desired
					? new Result()
					: $this->error(
						'A newer CRM IMAP gate marker has a conflicting state',
						self::ERROR_FUTURE_MARKER_CONFLICT,
					);
			}

			$value = max(self::CURRENT_VERSION, $version) . ':' . ($desired ? 'Y' : 'N');
			if (!$this->markerRepository->write($mailboxId, $value))
			{
				return $this->error('The CRM IMAP gate marker was not saved', self::ERROR_MARKER_WRITE_FAILED);
			}
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return $this->error('The CRM IMAP gate marker was not saved', self::ERROR_MARKER_WRITE_FAILED);
		}

		return new Result();
	}

	public function invalidateWithinTransaction(int $mailboxId): Result
	{
		try
		{
			return $this->markerRepository->invalidate($mailboxId);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return $this->error(
				'The CRM IMAP gate marker was not invalidated',
				self::ERROR_MARKER_INVALIDATION_FAILED,
			);
		}
	}

	public function invalidateManyWithinTransaction(array $mailboxIds): Result
	{
		$result = new Result();
		$mailboxIds = array_values(array_unique(array_map('intval', $mailboxIds)));
		sort($mailboxIds, SORT_NUMERIC);
		foreach ($mailboxIds as $mailboxId)
		{
			if ($mailboxId <= 0)
			{
				continue;
			}
			$invalidated = $this->invalidateWithinTransaction($mailboxId);
			if (!$invalidated->isSuccess())
			{
				$result->addErrors($invalidated->getErrors());
			}
		}

		return $result;
	}

	private function isSupportedMailbox(?array $mailbox): bool
	{
		return $mailbox !== null
			&& $mailbox['ACTIVE'] === MailboxStatus::Active->value
			&& in_array($mailbox['SERVER_TYPE'], self::SUPPORTED_SERVER_TYPES, true)
		;
	}

	private function parseMarker(?string $value): ?array
	{
		if ($value === null || preg_match('/^(0|[1-9][0-9]*):(Y|N)$/D', $value, $matches) !== 1)
		{
			return null;
		}

		return ['version' => (int)$matches[1], 'desired' => $matches[2] === 'Y'];
	}

	private function isSatisfied(?array $marker, bool $desired): bool
	{
		return $marker !== null
			&& $marker['version'] >= self::CURRENT_VERSION
			&& $marker['desired'] === $desired
		;
	}

	private function rollback(Connection $connection, Result $result): Result
	{
		$connection->rollbackTransaction();

		return $result;
	}

	private function error(string $message, string $code): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
