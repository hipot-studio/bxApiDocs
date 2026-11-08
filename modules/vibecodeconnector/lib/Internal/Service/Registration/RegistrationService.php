<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Registration;

use Bitrix\Main\Application;
use Bitrix\Vibecodeconnector\Internal\Entity\Pairing\Pairing;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message\UserListPullMessage;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\UserListPullQueueGuard;
use Bitrix\Vibecodeconnector\Internal\Service\Balance\BalanceSyncCoordinator;
use Bitrix\Vibecodeconnector\Internal\Service\Auth\CloudSharedVerifier;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Deactivation\PairingCatalogDeactivator;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointResolver;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointUrlGuard;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\PublicKeySource;

final class RegistrationService
{
	private readonly \Closure $registrationRequest;
	private readonly \Closure $unregisterRequest;
	private readonly ?UserListPullQueueGuard $userListPullQueueGuard;
	private readonly ?BalanceSyncCoordinator $balanceSyncCoordinator;

	public function __construct(
		private readonly PairingRepository $repository = new PairingRepository(),
		private readonly PairingSettings $settings = new PairingSettings(),
		private readonly CloudSharedVerifier $cloudSharedVerifier = new CloudSharedVerifier(),
		private readonly PairingCatalogDeactivator $catalogDeactivator = new PairingCatalogDeactivator(),
		private readonly EndpointUrlGuard $urlGuard = new EndpointUrlGuard(),
		?\Closure $registrationRequest = null,
		?UserListPullQueueGuard $userListPullQueueGuard = null,
		?\Closure $unregisterRequest = null,
		?BalanceSyncCoordinator $balanceSyncCoordinator = null,
	) {
		$this->registrationRequest = $registrationRequest
			?? static fn (string $url): RegistrationResponse => (new RegistrationClient(new EndpointResolver($url)))->register();
		$this->unregisterRequest = $unregisterRequest
			?? static function (string $url, string $iss): void {
				(new RegistrationClient(new EndpointResolver($url)))->unregister($iss);
			};
		$this->userListPullQueueGuard = $userListPullQueueGuard;
		$this->balanceSyncCoordinator = $balanceSyncCoordinator;
	}

	public function register(string $endpointUrl): Pairing
	{
		$url = $endpointUrl;
		$this->urlGuard->assertValid($url);

		$response = ($this->registrationRequest)($url);
		if (!$response instanceof RegistrationResponse)
		{
			throw new \UnexpectedValueException('Invalid registration response');
		}

		$now = time();
		$pairing = new Pairing(
			iss: $response->iss,
			publicKey: $response->publicKey,
			portalId: $response->portalId,
			endpointUrl: $url,
			fetchedAt: $now,
			expiresAt: $this->settings->computeExpiresAt($now, $response->ttlSeconds),
			publicKeyTtl: $response->ttlSeconds,
			keySource: PublicKeySource::default(),
		);
		$this->repository->upsert($pairing);
		$message = new UserListPullMessage(
			pairingIss: $pairing->iss,
			endpointUrl: $pairing->endpointUrl,
		);
		$userListPullQueueGuard = $this->userListPullQueueGuard ?? new UserListPullQueueGuard();
		try
		{
			$userListPullQueueGuard->tryEnqueue($message);
		}
		catch (\Throwable)
		{
		}

		try
		{
			($this->balanceSyncCoordinator ?? new BalanceSyncCoordinator())->request(
				new \Bitrix\Vibecodeconnector\Internal\Entity\User\UserEventTarget(
					$pairing->iss,
					$pairing->endpointUrl,
				),
				0,
			);
		}
		catch (\Throwable)
		{
		}

		return $pairing;
	}

	public function unregister(string $iss): void
	{
		$pairing = $this->repository->findByIss($iss);
		if ($pairing !== null)
		{
			($this->unregisterRequest)($pairing->endpointUrl, $iss);
		}

		$this->teardownPairing($iss);
	}

	/**
	 * Deactivation and deletion are one step that starts by locking the pairing row.
	 *
	 * A `catalog.item.pairing.bind` running at the same time locks the same row before stamping the
	 * issuer, so the two orders are the only ones possible: the bind commits first and the
	 * deactivation below then sees the card it stamped, or this teardown wins and the bind finds no
	 * issuer and refuses. Unlocked, a card bound between `deactivateByIss()` and `deleteByIss()`
	 * stayed active carrying an issuer that no longer existed - and a repeated bind then answered
	 * "Unknown pairing iss" instead of reporting it was already bound.
	 *
	 * The remote unregister call stays outside: holding a row lock across an HTTP round trip would
	 * trade one race for a much longer one. The cache is invalidated after the commit, not inside
	 * the transaction - see `PairingRepository::deleteByIssKeepingCache()`.
	 */
	private function teardownPairing(string $iss): void
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$this->repository->lockByIss($iss);
			$this->catalogDeactivator->deactivateByIss($iss);
			$this->repository->deleteByIssKeepingCache($iss);
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
				// A nested rollback reports itself; it must not mask the original failure.
			}

			throw $exception;
		}

		// Only now: invalidating inside the transaction would let a reader that misses the cache
		// re-cache the row that is still committed, and nothing would invalidate it afterwards -
		// the revoked issuer would keep verifying tokens until the cached entry expired.
		$this->repository->invalidateCache();
	}

	public function isRegistered(): bool
	{
		return $this->repository->hasAny();
	}

	public function isCloudSharedConfigured(): bool
	{
		return $this->cloudSharedVerifier->isConfigured();
	}

	/**
	 * The slot serves this portal even before the key is stored: the key is obtained
	 * on the first incoming request.
	 */
	public function isCloudSharedAvailable(): bool
	{
		return $this->cloudSharedVerifier->isSlotAvailable();
	}

	public function listPairings(): array
	{
		return $this->repository->listAll();
	}
}
