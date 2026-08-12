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
	/**
	 * @param LicenseService|null $licenseService note-внутренний сервис; не инжектится
	 *   конструктором (модуль note может быть не подключён в момент создания фасада) —
	 *   резолвится лениво в doResolveSection после includeModule('note').
	 */
	public function __construct(
		private readonly BindingService $bindingService,
		private readonly ?LicenseService $licenseService = null,
	)
	{
	}

	/**
	 * Resolves the knowledge-base section state for a collab and a user.
	 *
	 * @return array{available: bool, collectionId: int|null, canView: bool, restriction: string|null}
	 */
	public function resolveSection(int $collabId, int $userId): array
	{
		try
		{
			return $this->doResolveSection($collabId, $userId);
		}
		catch (\Throwable)
		{
			return self::unavailable();
		}
	}

	/**
	 * Lazily provisions the knowledge-base collection for a collab and returns
	 * its id, or null if it cannot be provisioned (gates off, no note module,
	 * tariff unavailable, error).
	 *
	 * Triggered on the first access to the section ("first click"), NOT at collab
	 * creation. Idempotent: an already-bound collection is returned as-is without
	 * creating a duplicate (DB-level unique index on COLLAB_ID is the backstop).
	 * Never throws: on any failure it logs and returns null.
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
			Logger::log(
				['collabId' => $collabId, 'message' => $e->getMessage()],
				'COLLAB_NOTE_COLLECTION_CREATE_FAILED',
			);

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

		// Idempotency: a collection is already bound to this collab.
		$existing = $this->bindingService->findCollectionIdByCollab($collabId);
		if ($existing !== null)
		{
			return $existing;
		}

		return $this->createCollectionWithBinding($collabId, $group);
	}

	/**
	 * @throws \Throwable
	 */
	private function createCollectionWithBinding(int $collabId, Workgroup $group): int
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
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

		$collectionId = $this->bindingService->findCollectionIdByCollab($collabId);

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
