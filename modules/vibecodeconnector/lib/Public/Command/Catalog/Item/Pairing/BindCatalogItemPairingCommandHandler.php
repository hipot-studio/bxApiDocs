<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Command\Catalog\Item\Pairing;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\NotSupportedException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Exception\CatalogItemNotFoundException;
use Bitrix\Vibecodeconnector\Internal\Exception\NotOwnerException;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Identity\AppIdentityHash;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Identity\KnownPairingIss;

/**
 * Repairs the pairing identity of a catalog item in place.
 *
 * The item keeps its id, app chat, pins and bookmarks, so a card created without identity no longer
 * has to be deleted and recreated to become a release target again.
 *
 * Write-once is preserved: only `NULL -> value` is allowed. Rebinding an already bound item is
 * refused even when the new value comes from a trusted credential, because the pairing issuer is
 * the item's identity, not a mutable field.
 *
 * A deactivated item is reported as missing: `CatalogItemRepository::getReleaseTarget()` skips
 * deactivated rows, so binding one would write a column nothing can read back, while the caller
 * would be told the repair succeeded.
 *
 * The item is read under the same row lock `UpdateCatalogItemCommandHandler` takes, because the
 * identity hash is derived from `EXTERNAL_ID` as well: a concurrent update renaming the external id
 * would otherwise leave a hash of the previous name behind.
 */
final class BindCatalogItemPairingCommandHandler
{
	public const BOUND = 'bound';
	public const ALREADY = 'already';

	private const CONFLICT_MESSAGE = 'Catalog item is already bound to a different pairing iss';
	private const UNAVAILABLE_MESSAGE = 'The calling credential carries no pairing identity';

	public function __construct(
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly KnownPairingIss $knownPairingIss = new KnownPairingIss(),
	)
	{
	}

	/**
	 * @return array{iss: string, bound: self::BOUND|self::ALREADY}
	 *
	 * @throws CatalogItemNotFoundException
	 * @throws NotOwnerException
	 * @throws ArgumentException
	 * @throws PersistenceException
	 */
	public function __invoke(BindCatalogItemPairingCommand $command): array
	{
		if (!BindCatalogItemPairingCommand::isSupportedHere())
		{
			// Unreachable through REST - the controller refuses earlier - but a direct caller must
			// not get a "repaired" answer that nothing can hold against a parallel unregistration.
			throw new NotSupportedException(
				'Binding a pairing identity needs row locking, which this database does not provide',
			);
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$result = $this->bindLockedItem($command);
			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			try
			{
				$connection->rollbackTransaction();
			}
			catch (\Throwable)
			{
				// A nested rollback reports itself, and a savepoint may already be gone. Neither is
				// allowed to mask the exception that actually aborted the bind.
			}

			throw $exception;
		}

		return $result;
	}

	/**
	 * @return array{iss: string, bound: self::BOUND|self::ALREADY}
	 *
	 * @throws CatalogItemNotFoundException
	 * @throws NotOwnerException
	 * @throws ArgumentException
	 * @throws PersistenceException
	 */
	private function bindLockedItem(BindCatalogItemPairingCommand $command): array
	{
		$iss = trim((string)$command->iss);
		if ($iss === '')
		{
			throw new ArgumentException(self::UNAVAILABLE_MESSAGE, 'iss');
		}

		// Lock order is pairing row first, catalog row second - the same order
		// `RegistrationService::unregister()` takes them in. Reversed here, a bind of an already
		// bound card would hold the catalog row while waiting for the pairing row that a concurrent
		// unregistration holds while waiting for that same card: a deadlock instead of a wait.
		$this->knownPairingIss->assertLocked($iss);

		$this->itemRepository->lockById($command->catalogItemId);

		$item = $this->itemRepository->getById($command->catalogItemId);
		if ($item === null || $item->getDeactivatedAt() !== null)
		{
			throw new CatalogItemNotFoundException($command->catalogItemId);
		}

		if ($item->getOwnerId() !== $command->userId)
		{
			throw new NotOwnerException();
		}

		$boundIss = $this->boundIss($item);
		if ($boundIss === $iss)
		{
			return ['iss' => $iss, 'bound' => self::ALREADY];
		}
		if ($boundIss !== null)
		{
			throw new ArgumentException(self::CONFLICT_MESSAGE, 'iss');
		}

		$this->assertIdentityIsFree($iss, $item);

		try
		{
			$bound = $this->itemRepository->bindPairingIss(
				$command->catalogItemId,
				$iss,
				$item->getExternalId(),
			);
		}
		catch (DuplicateEntryException)
		{
			// The unique index on APP_IDENTITY_HASH lost the race: another card holds (iss, externalId).
			throw new ArgumentException(self::CONFLICT_MESSAGE, 'iss');
		}
		catch (SqlQueryException $exception)
		{
			// Only MySQL and PostgreSQL classify a duplicate key as DuplicateEntryException; the
			// other drivers raise a plain query error, so the state decides instead. Anything that
			// is not a taken identity keeps propagating - a pairing conflict must not become the
			// answer to an unrelated SQL failure.
			try
			{
				$this->assertIdentityIsFree($iss, $item);
			}
			catch (ArgumentException $conflict)
			{
				throw $conflict;
			}
			catch (\Throwable)
			{
				// The read failed too (an aborted transaction, say): nothing to classify by.
			}

			throw $exception;
		}

		if ($bound)
		{
			return ['iss' => $iss, 'bound' => self::BOUND];
		}

		return $this->answerLostRace($command->catalogItemId, $iss);
	}

	/**
	 * The statement wrote nothing, so the row stopped matching what was read: it got bound,
	 * deactivated or renamed meanwhile.
	 *
	 * @return array{iss: string, bound: self::ALREADY}
	 */
	private function answerLostRace(int $catalogItemId, string $iss): array
	{
		$item = $this->itemRepository->getById($catalogItemId);
		if ($item === null || $item->getDeactivatedAt() !== null)
		{
			throw new CatalogItemNotFoundException($catalogItemId);
		}

		$boundIss = $this->boundIss($item);
		if ($boundIss === $iss)
		{
			return ['iss' => $iss, 'bound' => self::ALREADY];
		}
		if ($boundIss !== null)
		{
			throw new ArgumentException(self::CONFLICT_MESSAGE, 'iss');
		}

		// Still unbound: the external id the hash was calculated from is gone. Reporting a conflict
		// here would blame the caller for a concurrent edit, so let the call be retried instead.
		throw new PersistenceException('The catalog item was changed concurrently');
	}

	/**
	 * Reports the identity a card already carries; a legacy empty string counts as no identity,
	 * exactly as the write-once condition of `bindPairingIss()` treats it.
	 */
	private function boundIss(CatalogItem $item): ?string
	{
		$iss = trim((string)$item->getPairingIss());

		return $iss === '' ? null : $iss;
	}

	/**
	 * @throws ArgumentException when the catalog already holds the card this identity belongs to.
	 */
	private function assertIdentityIsFree(string $iss, CatalogItem $item): void
	{
		$identityHash = AppIdentityHash::calculate($iss, $item->getExternalId());
		if ($identityHash === null)
		{
			return;
		}

		if ($this->itemRepository->existsByAppIdentityHash($identityHash, (int)$item->getId()))
		{
			throw new ArgumentException(self::CONFLICT_MESSAGE, 'iss');
		}
	}
}
