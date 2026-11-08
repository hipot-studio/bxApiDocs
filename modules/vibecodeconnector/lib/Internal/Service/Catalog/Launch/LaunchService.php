<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Launch;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;
use Bitrix\Vibecodeconnector\Internal\Exception\CatalogItemNotFoundException;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\AccessCodes;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\UserNameResolver;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\LastOpenedRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\ViewedRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppChatOnDemandService;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\NewApps\NewAppsCountCache;

final class LaunchService
{
	public function __construct(
		private readonly CatalogItemRepository $repository = new CatalogItemRepository(),
		private readonly AccessCodes $accessCodes = new AccessCodes(),
		private readonly LastOpenedRepository $lastOpenedRepository = new LastOpenedRepository(),
		private readonly ViewedRepository $viewedRepository = new ViewedRepository(),
		private readonly UserNameResolver $userNameResolver = new UserNameResolver(),
		private readonly NewAppsCountCache $newAppsCountCache = new NewAppsCountCache(),
		private readonly AppChatOnDemandService $appChatOnDemandService = new AppChatOnDemandService(),
	) {}

	public function launch(int $catalogItemId, int $userId): LaunchResult
	{
		if ($userId <= 0)
		{
			throw new AccessDeniedException('A guest cannot launch an app');
		}

		$item = $this->repository->getById($catalogItemId);
		if ($item === null || $item->isDeactivated())
		{
			throw new CatalogItemNotFoundException($catalogItemId);
		}

		if ($item->getType() !== CatalogItemType::Application)
		{
			throw new CatalogItemNotFoundException($catalogItemId);
		}

		if (!$this->repository->isAccessibleToUser(
			$catalogItemId,
			$userId,
			$this->accessCodes->getUserCodesForItems($userId, [$catalogItemId]),
		))
		{
			throw new AccessDeniedException('User has no access to this catalog item');
		}

		$this->appChatOnDemandService->ensureUserChatBestEffort($catalogItemId, $userId);

		$result = new LaunchResult(LaunchResult::KIND_APP, $this->buildAppTarget($item, $userId));

		$this->markOpened($userId, $catalogItemId);

		return $result;
	}

	public function launchOpenedApp(CatalogItem $item, int $userId): void
	{
		$this->markOpened($userId, (int)$item->getId());
	}

	private function markOpened(int $userId, int $catalogItemId): void
	{
		$this->lastOpenedRepository->recordOpen($userId, $catalogItemId);
		$this->viewedRepository->markViewed($userId, [$catalogItemId]);
		$this->newAppsCountCache->forget($userId);
	}

	private function buildAppTarget(CatalogItem $item, int $userId): LaunchAppTarget
	{
		$ownerId = $item->getOwnerId();

		return new LaunchAppTarget(
			title: $item->getTitle(),
			viewUrl: $item->getViewUrl(),
			externalId: $item->getExternalId(),
			ownerId: $ownerId,
			ownerName: $this->userNameResolver->resolveMany([$ownerId])[$ownerId] ?? null,
			isMine: $ownerId === $userId,
		);
	}
}
