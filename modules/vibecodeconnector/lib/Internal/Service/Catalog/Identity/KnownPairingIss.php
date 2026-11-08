<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Identity;

use Bitrix\Main\ArgumentException;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;

/**
 * Guards that a pairing issuer stamped on a catalog item is a paired VibeCode server.
 *
 * Shared by the create path and the pairing bind path so both reject an unknown issuer identically.
 */
final class KnownPairingIss
{
	public function __construct(
		private readonly PairingRepository $pairingRepository = new PairingRepository(),
	) {}

	/**
	 * @throws ArgumentException when no pairing row is stored for the issuer.
	 */
	public function assert(string $iss): void
	{
		if ($this->pairingRepository->findByIss($iss) === null)
		{
			throw new ArgumentException('Unknown pairing iss', 'iss');
		}
	}

	/**
	 * Same check, but the row is always read from the table itself, never from the cache, and where
	 * the driver supports it the row stays locked until the caller's transaction ends - so an
	 * unregistration cannot delete the issuer between the check and the write that relies on it.
	 *
	 * Callers that want the lock must be inside a transaction; without row-locking support the
	 * check still bypasses the cache, which is the part that matters even unsynchronised: a cached
	 * entry can outlive the deletion by a process that could not invalidate it here.
	 *
	 * @throws ArgumentException when no pairing row is stored for the issuer.
	 */
	public function assertLocked(string $iss): void
	{
		if (!$this->pairingRepository->lockByIss($iss))
		{
			throw new ArgumentException('Unknown pairing iss', 'iss');
		}
	}
}
