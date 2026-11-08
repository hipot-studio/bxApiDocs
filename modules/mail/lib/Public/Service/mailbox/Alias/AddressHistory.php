<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\Mailbox\Alias;

use Bitrix\Mail\Internals\Repository\MailboxAddressAliasRepository;
use Bitrix\Mail\Internals\Service\Mailbox\AddressLock;
use Bitrix\Mail\Internals\Service\Mailbox\EmailNormalizer;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class AddressHistory
{
	public const ERROR_INVALID_ADDRESS = 'MAIL_MAILBOX_ALIAS_INVALID_ADDRESS';
	public const ERROR_MAILBOX_NOT_FOUND = 'MAIL_MAILBOX_ALIAS_MAILBOX_NOT_FOUND';
	public const ERROR_CURRENT_ADDRESS = 'MAIL_MAILBOX_ALIAS_CURRENT_ADDRESS';
	public const ERROR_ADDRESS_OCCUPIED = 'MAIL_MAILBOX_ALIAS_ADDRESS_OCCUPIED';
	public const ERROR_LOCK_UNAVAILABLE = 'MAIL_MAILBOX_ALIAS_LOCK_UNAVAILABLE';
	public const ERROR_ALIAS_NOT_FOUND = 'MAIL_MAILBOX_ALIAS_NOT_FOUND';
	public const ERROR_ALIAS_NOT_ORPHAN = 'MAIL_MAILBOX_ALIAS_NOT_ORPHAN';

	private readonly MailboxAddressAliasRepository $aliasRepository;
	private readonly EmailNormalizer $emailNormalizer;
	private readonly AddressLock $addressLock;

	public function __construct(
		?MailboxAddressAliasRepository $aliasRepository = null,
		?EmailNormalizer $emailNormalizer = null,
		?AddressLock $addressLock = null,
	)
	{
		$this->aliasRepository = $aliasRepository ?? new MailboxAddressAliasRepository();
		$this->emailNormalizer = $emailNormalizer ?? new EmailNormalizer();
		$this->addressLock = $addressLock ?? new AddressLock();
	}

	public function register(int $mailboxId, string $historicalEmail): Result
	{
		$result = new Result();
		$normalizedEmail = $this->emailNormalizer->normalize($historicalEmail);
		if ($mailboxId <= 0)
		{
			return $result->addError(new Error('Mailbox not found.', self::ERROR_MAILBOX_NOT_FOUND));
		}

		if ($normalizedEmail === null)
		{
			return $result->addError(new Error('Invalid mailbox address.', self::ERROR_INVALID_ADDRESS));
		}

		if (!$this->addressLock->acquire($normalizedEmail))
		{
			return $result->addError(new Error(
				'Unable to acquire mailbox address lock.',
				self::ERROR_LOCK_UNAVAILABLE,
			));
		}

		$connection = Application::getConnection();
		$transactionStarted = false;

		try
		{
			$connection->startTransaction();
			$transactionStarted = true;
			$result = $this->registerWithinTransaction($mailboxId, $normalizedEmail);
			if (!$result->isSuccess())
			{
				$this->rollbackTransaction($connection);
				$transactionStarted = false;

				return $result;
			}

			$connection->commitTransaction();
			$transactionStarted = false;

			return $result;
		}
		catch (\Throwable $exception)
		{
			if ($transactionStarted)
			{
				$this->rollbackTransaction($connection);
			}

			throw $exception;
		}
		finally
		{
			$this->addressLock->release($normalizedEmail);
		}
	}

	public function registerWithinTransaction(int $mailboxId, string $historicalEmail): Result
	{
		$result = new Result();
		$normalizedEmail = $this->emailNormalizer->normalize($historicalEmail);
		if ($mailboxId <= 0)
		{
			return $result->addError(new Error('Mailbox not found.', self::ERROR_MAILBOX_NOT_FOUND));
		}

		if ($normalizedEmail === null)
		{
			return $result->addError(new Error('Invalid mailbox address.', self::ERROR_INVALID_ADDRESS));
		}

		$mailbox = MailboxTable::getByPrimary($mailboxId, [
			'select' => ['ID', 'EMAIL', 'NAME', 'LOGIN'],
		])->fetch();
		if ($mailbox === false)
		{
			return $result->addError(new Error('Mailbox not found.', self::ERROR_MAILBOX_NOT_FOUND));
		}

		if ($this->aliasRepository->exists($mailboxId, $normalizedEmail))
		{
			return $result;
		}

		if ($this->emailNormalizer->normalizeMailbox($mailbox) === $normalizedEmail)
		{
			return $result->addError(new Error(
				'Current mailbox address cannot be historical.',
				self::ERROR_CURRENT_ADDRESS,
			));
		}

		$addResult = $this->aliasRepository->add($mailboxId, $normalizedEmail);
		if (!$addResult->isSuccess())
		{
			$result->addErrors($addResult->getErrors());
		}

		return $result;
	}

	public function isOccupied(string $email, ?int $exceptMailboxId = null): bool
	{
		return $this->inspectOccupation($email, $exceptMailboxId)->isOccupied();
	}

	public function inspectOccupation(string $email, ?int $exceptMailboxId = null): AddressOccupation
	{
		$normalizedEmail = $this->emailNormalizer->normalize($email);
		if ($normalizedEmail === null)
		{
			return new AddressOccupation(AddressOccupationStatus::Free, null);
		}

		$currentFilter = ['=EMAIL_NORMALIZED' => $normalizedEmail];
		if ($exceptMailboxId !== null)
		{
			$currentFilter['!=ID'] = $exceptMailboxId;
		}
		$currentMailboxIds = array_map(
			static fn(array $mailbox): int => (int)$mailbox['ID'],
			MailboxTable::getList([
				'select' => ['ID'],
				'filter' => $currentFilter,
				'order' => ['ID' => 'ASC'],
			])->fetchAll(),
		);
		if ($currentMailboxIds !== [])
		{
			return new AddressOccupation(
				AddressOccupationStatus::OccupiedByMailbox,
				$normalizedEmail,
				$currentMailboxIds,
			);
		}

		$aliases = array_values(array_filter(
			$this->aliasRepository->findByEmail($normalizedEmail),
			static fn(array $alias): bool => $exceptMailboxId === null
				|| (int)$alias['MAILBOX_ID'] !== $exceptMailboxId,
		));
		if ($aliases === [])
		{
			return new AddressOccupation(AddressOccupationStatus::Free, $normalizedEmail);
		}

		$mailboxIds = array_values(array_unique(array_map(
			static fn(array $alias): int => (int)$alias['MAILBOX_ID'],
			$aliases,
		)));
		$existingMailboxIds = [];
		foreach (array_chunk($mailboxIds, 100) as $mailboxIdChunk)
		{
			$rows = MailboxTable::getList([
				'select' => ['ID'],
				'filter' => ['@ID' => $mailboxIdChunk],
			])->fetchAll();
			foreach ($rows as $row)
			{
				$existingMailboxIds[(int)$row['ID']] = true;
			}
		}

		$liveAliases = array_values(array_filter(
			$aliases,
			static fn(array $alias): bool => isset($existingMailboxIds[(int)$alias['MAILBOX_ID']]),
		));
		$matchedAliases = $liveAliases !== [] ? $liveAliases : $aliases;

		return new AddressOccupation(
			$liveAliases !== []
				? AddressOccupationStatus::OccupiedByAlias
				: AddressOccupationStatus::OccupiedByOrphanAlias,
			$normalizedEmail,
			array_values(array_unique(array_map(
				static fn(array $alias): int => (int)$alias['MAILBOX_ID'],
				$matchedAliases,
			))),
			array_map(static fn(array $alias): int => (int)$alias['ID'], $matchedAliases),
		);
	}

	/**
	 * @return OrphanAlias[]
	 */
	public function findOrphanAliases(int $limit, ?int $afterId = null): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		return array_map(
			static fn(array $alias): OrphanAlias => new OrphanAlias(
				(int)$alias['ID'],
				(int)$alias['MAILBOX_ID'],
				(string)$alias['EMAIL'],
			),
			$this->aliasRepository->findOrphans(min($limit, 1000), $afterId),
		);
	}

	public function deleteOrphanAlias(int $aliasId): Result
	{
		$result = new Result();
		if ($aliasId <= 0)
		{
			$result->addError(new Error('Mailbox alias not found.', self::ERROR_ALIAS_NOT_FOUND));

			return $result;
		}

		try
		{
			$deleteResult = $this->aliasRepository->deleteOrphan($aliasId);
			if (!$deleteResult->isSuccess())
			{
				$result->addErrors($deleteResult->getErrors());
			}
		}
		catch (\Throwable $exception)
		{
			$result->addError(new Error($exception->getMessage()));
		}

		return $result;
	}

	public function deleteByMailboxId(int $mailboxId): Result
	{
		$result = new Result();
		if ($mailboxId <= 0)
		{
			$result->addError(new Error('Mailbox not found.', self::ERROR_MAILBOX_NOT_FOUND));

			return $result;
		}

		try
		{
			$this->aliasRepository->deleteByMailboxId($mailboxId);
		}
		catch (\Throwable $exception)
		{
			$result->addError(new Error($exception->getMessage()));
		}

		return $result;
	}

	private function rollbackTransaction(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException $exception)
		{
			if ($exception->getMessage() !== 'Nested rollbacks are unsupported.')
			{
				throw $exception;
			}
		}
	}
}
