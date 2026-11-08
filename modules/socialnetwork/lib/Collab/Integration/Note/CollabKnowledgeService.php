<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Integration\Note;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Service\License\LicenseService;
use Bitrix\Note\Public\Command\CreateCollectionCommand;
use Bitrix\Socialnetwork\Collab\Integration\Note\PermissionProjectionService;
use Bitrix\Socialnetwork\Collab\Integration\Note\Service\BindingService;
use Bitrix\Socialnetwork\Collab\Integration\Note\Service\CollectionExistenceGateway;
use Bitrix\Socialnetwork\Item\Workgroup;
use Bitrix\Socialnetwork\Log\Logger;
use Bitrix\Socialnetwork\V2\Feature;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

/**
 * Local (socialnetwork-side) facade that resolves the knowledge-base section
 * for a collab. Single point of tariff gating.
 *
 * All access to the note module is guarded by Loader::includeModule('note').
 * The method never throws: on any error or unavailability it returns a safe
 * `available=false` snapshot.
 */
class CollabKnowledgeService
{
	/** Depth of the exception chain the failure log keeps ({@see self::describeThrowable()}). */
	private const THROWABLE_CHAIN_LIMIT = 5;

	/**
	 * Section snapshots ({@see self::resolveSection()}) per collab and user.
	 *
	 * Memoized for as long as the instance lives: the facade is resolved through the
	 * ServiceLocator, so that is one HTTP request - and every caller of the facade today (the
	 * tools menu and the controller of the click) lives inside one, so the memo dies with it.
	 * A process that handles several units of work (a queue worker, an agent, a console
	 * command) would keep the same instance across all of them, and a snapshot taken in the
	 * first one would answer for the rest; no such caller exists today, which is why nothing
	 * drops the memo ({@see self::reset()}) - the first one to appear has to drop it itself.
	 *
	 * @var array<int, array<int, array<string, mixed>>>
	 */
	private array $sectionMemo = [];

	/**
	 * @param LicenseService|null $licenseService note-внутренний сервис; не инжектится
	 *   конструктором (модуль note может быть не подключён в момент создания фасада) —
	 *   резолвится лениво в doResolveSection после includeModule('note').
	 * @param CollectionExistenceGateway|null $existenceGateway resolved from the container when
	 *   omitted; the default value keeps autowiring away from it, tests pass their own.
	 */
	public function __construct(
		private readonly BindingService $bindingService,
		private readonly ?LicenseService $licenseService = null,
		private readonly ?CollectionExistenceGateway $existenceGateway = null,
	)
	{
	}

	/**
	 * Resolves the knowledge-base section state for a collab and a user.
	 *
	 * The tools menu resolves the same section twice per render, so the answer -
	 * including an unavailable() one - is memoized ({@see self::$sectionMemo}).
	 *
	 * @return array{available: bool, collectionId: int|null, canView: bool, restriction: string|null}
	 */
	public function resolveSection(int $collabId, int $userId): array
	{
		if (isset($this->sectionMemo[$collabId][$userId]))
		{
			return $this->sectionMemo[$collabId][$userId];
		}

		try
		{
			$section = $this->doResolveSection($collabId, $userId);
		}
		catch (\Throwable)
		{
			$section = self::unavailable();
		}

		$this->sectionMemo[$collabId][$userId] = $section;

		return $section;
	}

	/**
	 * Drops the memoized section state for a collab: provisioning a knowledge base
	 * changes it for every user of that collab.
	 */
	public function forgetSection(int $collabId): void
	{
		unset($this->sectionMemo[$collabId]);
	}

	/**
	 * Forgets every memoized section: a caller that outlives a single request starts each
	 * unit of work with the state of the section unresolved.
	 *
	 * Nothing in lib/ calls this today - the facade is not on the path of the queue worker,
	 * which resolves the projection service and the existence gateway only
	 * ({@see Async\CollabNoteAclReceiver::process()}). It is here for the first caller that
	 * does outlive a request: the section is memoized per instance, and such a caller has no
	 * other way to start over.
	 */
	public function reset(): void
	{
		$this->sectionMemo = [];
	}

	/**
	 * Lazily provisions the knowledge-base collection for a collab and returns
	 * its id, or null if it cannot be provisioned (gates off, no note module,
	 * tariff unavailable, error).
	 *
	 * Triggered on the first access to the section ("first click"), NOT at collab
	 * creation. Idempotent: an already-bound collection is returned as-is without
	 * creating a duplicate (DB-level unique index on COLLAB_ID is the backstop).
	 * A binding that outlived its collection is not a knowledge base: it is dropped
	 * and a new one is provisioned in its place.
	 * Never throws: on any failure it logs and returns null.
	 *
	 * Access contract: the method provisions on behalf of the collab and checks no rights
	 * of its own - it takes no initiator, so it has nobody to check. The caller MUST make
	 * sure the initiator is an actual member of the collab (owner, moderator or ordinary
	 * member) before calling: creating is also recreating, and an outsider would undo a
	 * deletion the collab decided on. The only caller today does exactly that
	 * ({@see \Bitrix\Socialnetwork\Collab\Controller\Note::resolveSectionAction()}).
	 */
	public function ensureCollection(int $collabId): ?int
	{
		try
		{
			return $this->doEnsureCollection($collabId);
		}
		catch (\Throwable $e)
		{
			// Provisioning failure must not surface as a fatal: log and report no section.
			// A later access retries (idempotent repair).
			// The dead id is null on purpose: everything that fails once the binding is known to
			// be dead is written down by recoverAfterFailedRecreate(), which does know the id
			// (the write of the entry itself is the only thing that can still land here). What
			// gets here is a collab that has no knowledge base behind it.
			$this->logProvisioningFailure($collabId, null, $e);

			return null;
		}
	}

	private function doEnsureCollection(int $collabId): ?int
	{
		if ($collabId <= 0)
		{
			return null;
		}

		if (!Feature::isNewProjectsOn())
		{
			return null;
		}

		$group = Workgroup::getById($collabId);
		if ($group === false || !$group->isCollab())
		{
			return null;
		}

		if (!Loader::includeModule('note'))
		{
			return null;
		}

		$licenseService = $this->licenseService ?? Container::getInstance()->get(LicenseService::class);
		if (!$licenseService->isModuleAvailable())
		{
			return null;
		}

		// Idempotency: a collection is already bound to this collab. The binding alone proves
		// nothing though - only an existing collection ends the work here. An unknown answer of
		// the gateway (null) is not a reason to drop the binding either.
		$existing = $this->bindingService->findCollectionIdByCollab($collabId);
		$deadId = null;
		if ($existing !== null)
		{
			if ($this->resolveExistenceGateway()->exists($existing) !== false)
			{
				return $existing;
			}

			$deadId = $existing;
		}

		try
		{
			$collectionId = $this->createCollectionWithBinding($collabId, $group, $deadId);
		}
		catch (\Throwable $e)
		{
			return $this->recoverAfterFailedRecreate($collabId, $deadId, $e);
		}

		$this->forgetSection($collabId);

		return $collectionId;
	}

	/**
	 * Single recovery branch for every failure of the (re)creation: a rejected binding insert,
	 * a failed collection creation, a deadlock on the delete-then-insert pair. The cause is
	 * unknown and does not matter - the decision is made by the state of the binding left after
	 * the rollback, and it is read exactly once. A read that does not go through decides nothing
	 * either: it is a failure of the same recreation, and it is written down as one.
	 *
	 * The dead id is never returned: a click would reproduce the original symptom.
	 */
	private function recoverAfterFailedRecreate(int $collabId, ?int $deadId, \Throwable $error): ?int
	{
		try
		{
			$current = $this->bindingService->findCollectionIdByCollab($collabId);
			if ($current !== null && $current !== $deadId && $this->resolveExistenceGateway()->exists($current) === true)
			{
				// The knowledge base of the click that won the race.
				$this->forgetSection($collabId);

				return $current;
			}
		}
		catch (\Throwable $recoveryError)
		{
			// The recovery itself did not go through: whatever the collab is left with stays
			// unknown. The entry is still written from here rather than left to the catch of
			// ensureCollection(), because the dead id - and with it the path the incident
			// belongs to - is known only here.
			$this->logProvisioningFailure($collabId, $deadId, $error, $recoveryError);

			return null;
		}

		$this->logProvisioningFailure($collabId, $deadId, $error);

		return null;
	}

	/**
	 * The event code tells the two paths apart, and the dead id decides which one it is: a
	 * knowledge base that was never provisioned keeps the event code it had before recreation
	 * existed, a failed recreation gets its own. Both top-level handlers write through here, so
	 * the code of the event and the id in the entry cannot disagree about the path.
	 *
	 * @param \Throwable|null $recoveryError failure of the recovery after a failed recreation,
	 *   when even the state left by the rollback could not be read. The failure that started it
	 *   all stays under `error`: this one only explains why its outcome is unknown.
	 */
	private function logProvisioningFailure(
		int $collabId,
		?int $deadCollectionId,
		\Throwable $error,
		?\Throwable $recoveryError = null,
	): void
	{
		$data = [
			'collabId' => $collabId,
			'deadCollectionId' => $deadCollectionId,
			'error' => self::describeThrowable($error),
		];

		if ($recoveryError !== null)
		{
			$data['recoveryError'] = self::describeThrowable($recoveryError);
		}

		$this->writeFailureLog(
			$data,
			$deadCollectionId === null
				? 'COLLAB_NOTE_COLLECTION_CREATE_FAILED'
				: 'COLLAB_NOTE_COLLECTION_RECREATE_FAILED',
		);
	}

	/**
	 * The failure as the log has to see it: the message alone is not enough here. The creation
	 * runs inside a transaction of ours while the note module opens its own, and the core
	 * replaces the message of a nested rollback with 'Nested rollbacks are unsupported.'
	 * (main/lib/db/mysqlcommonconnection.php) - the original cause survives only in the
	 * exception chain, so the whole chain is written down.
	 *
	 * Class, code and message only: no trace, no query text, nothing read out of the collab or
	 * of its knowledge base - the entry is written on a path nobody watches, and it must stay
	 * readable. The message is taken as it comes, and the errors of the note module are about
	 * the collection that could not be created, so the name of the collection - the name of the
	 * collab itself - can reach the entry through them. That text is the only account of why
	 * the creation was refused; dropped, it would leave an entry that says no more than
	 * 'it failed'.
	 *
	 * @return list<array{class: string, code: int|string, message: string}>
	 */
	private static function describeThrowable(\Throwable $error): array
	{
		$chain = [];

		$current = $error;
		// Guard against a cyclic chain: getPrevious() is filled by whoever throws.
		for ($depth = 0; $current !== null && $depth < self::THROWABLE_CHAIN_LIMIT; $depth++)
		{
			$chain[] = [
				'class' => $current::class,
				'code' => $current->getCode(),
				'message' => $current->getMessage(),
			];

			$current = $current->getPrevious();
		}

		return $chain;
	}

	/**
	 * Protected on purpose: the log entry is the only trace of a knowledge base that could not
	 * be provisioned, and replacing this step is the only way for a test to observe it.
	 */
	protected function writeFailureLog(array $data, string $marker): void
	{
		Logger::log($data, $marker);
	}

	/**
	 * @param int|null $deadId binding to a collection that no longer exists; it is dropped
	 *   inside the same transaction, so a failed creation leaves the collab with its old
	 *   record rather than with none.
	 *
	 * @throws \Throwable
	 */
	private function createCollectionWithBinding(int $collabId, Workgroup $group, ?int $deadId): int
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			if ($deadId !== null)
			{
				$this->bindingService->deleteByCollabAndCollection($collabId, $deadId);
			}

			$createResult = (new CreateCollectionCommand(
				name: $this->resolveCollectionName($group, $collabId),
				createdBy: $group->getOwnerId(),
				permissions: Container::getInstance()->get(PermissionProjectionService::class)->buildPermissions($collabId),
			))->run();

			if (!$createResult->isSuccess())
			{
				throw new \RuntimeException(
					'Failed to create note collection: ' . implode('; ', $createResult->getErrorMessages())
				);
			}

			$collectionId = (int)($createResult->getData()['collection'] ?? null)?->getId();
			if ($collectionId <= 0)
			{
				throw new \RuntimeException('Note collection created without a valid id');
			}

			$bindingResult = $this->bindingService->createBinding($collabId, $collectionId, $group->getOwnerId());
			if (!$bindingResult->isSuccess())
			{
				throw new \RuntimeException(
					'Failed to bind note collection: ' . implode('; ', $bindingResult->getErrorMessages())
				);
			}

			$connection->commitTransaction();

			return $collectionId;
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();

			throw $e;
		}
	}

	private function resolveCollectionName(Workgroup $group, int $collabId): string
	{
		$name = trim((string)$group->getName());

		return $name !== '' ? $name : 'Collab #' . $collabId;
	}

	/**
	 * @return array{available: bool, collectionId: int|null, canView: bool, restriction: string|null}
	 */
	private function doResolveSection(int $collabId, int $userId): array
	{
		if ($collabId <= 0 || $userId <= 0)
		{
			return self::unavailable();
		}

		if (!Feature::isNewProjectsOn())
		{
			return self::unavailable();
		}

		if (!$this->isCollab($collabId))
		{
			return self::unavailable();
		}

		if (!Loader::includeModule('note'))
		{
			return self::unavailable();
		}

		$licenseService = $this->licenseService ?? Container::getInstance()->get(LicenseService::class);
		if (!$licenseService->isModuleAvailable())
		{
			return [
				'available' => false,
				'collectionId' => null,
				'canView' => false,
				'restriction' => $licenseService->getAccessSliderCode(),
			];
		}

		$collectionId = $this->resolveExistingCollectionId($collabId);

		$canView = false;
		if ($collectionId !== null)
		{
			$accessCodes = CollectionAccessService::buildUserAccessCodes($userId);
			$canView = CollectionAccessService::hasCollectionLevel(
				$collectionId,
				$userId,
				$accessCodes,
				CollectionAccessService::LEVEL_VIEW,
			);
		}

		return [
			'available' => true,
			'collectionId' => $collectionId,
			'canView' => $canView,
			'restriction' => null,
		];
	}

	/**
	 * Id of the bound knowledge base, or null when the binding outlived the collection it
	 * points to: such a collab is reported as one without a knowledge base, and the menu
	 * item keeps waiting for a click.
	 *
	 * Read-only by contract: the section is resolved on every render of the tools menu, so
	 * neither the dead binding nor a new collection may be touched here.
	 *
	 * An unknown answer of the gateway (null) leaves the binding as the source of truth.
	 */
	private function resolveExistingCollectionId(int $collabId): ?int
	{
		$collectionId = $this->bindingService->findCollectionIdByCollab($collabId);
		if ($collectionId === null)
		{
			return null;
		}

		return $this->resolveExistenceGateway()->exists($collectionId) === false ? null : $collectionId;
	}

	private function resolveExistenceGateway(): CollectionExistenceGateway
	{
		return $this->existenceGateway ?? Container::getInstance()->get(CollectionExistenceGateway::class);
	}

	private function isCollab(int $collabId): bool
	{
		$group = Workgroup::getById($collabId);

		return $group !== false && $group->isCollab();
	}

	/**
	 * @return array{available: bool, collectionId: int|null, canView: bool, restriction: string|null}
	 */
	private static function unavailable(): array
	{
		return [
			'available' => false,
			'collectionId' => null,
			'canView' => false,
			'restriction' => null,
		];
	}
}
