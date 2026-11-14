<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access;

use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;
use Bitrix\Rest\Internal\Entity\Access\EntityType;
use Bitrix\Rest\Internal\Entity\Access\PermissionType;
use Bitrix\Rest\Internal\Entity\Application\App;
use Bitrix\Rest\Internal\Entity\Application\AppOrigin;
use Bitrix\Rest\Internal\Service\AccessCodesService;

class EmbeddingAccessChecker
{
	private readonly AppAccessChecker $appAccessChecker;

	public function __construct(
		private readonly int $userId,
		private readonly AccessCodesService $accessCodesService = new AccessCodesService(),
		?AppAccessChecker $appAccessChecker = null,
	)
	{
		$this->appAccessChecker = $appAccessChecker ?? new AppAccessChecker($this->userId);
	}

	public function canInstallEmbedding(App $app): bool
	{
		return $this->canManageEmbedding($app) && $this->appAccessChecker->canAccessApp($app);
	}

	public function canUninstallEmbedding(App $app): bool
	{
		return $this->canInstallEmbedding($app);
	}

	public function canViewEmbeddingList(App $app): bool
	{
		return $this->appAccessChecker->canAccessApp($app);
	}

	public function canViewAllEmbeddings(): bool
	{
		return $this->userId > 0 && RestUserModel::createFromId($this->userId)->isAdmin();
	}

	private function canManageEmbedding(App $app): bool
	{
		if ($this->isAdmin())
		{
			return true;
		}

		if ($app->isPersonal())
		{
			if ($this->userId <= 0 || !$app->isOwnedBy($this->userId))
			{
				return false;
			}

			return $this->canAccessPolicy(EntityType::Embedding, PermissionType::ManageOwnEmbeddings);
		}

		if ($app->getOrigin() !== AppOrigin::Local)
		{
			return false;
		}

		return $this->canAccessPolicy(EntityType::LocalApp, PermissionType::Create);
	}

	private function canAccessPolicy(EntityType $entityType, PermissionType $permission): bool
	{
		$accessCodes = $this->accessCodesService->getAccessCodes($entityType, $permission);

		return $accessCodes !== [] && RestUserModel::createFromId($this->userId)->canAccess($accessCodes);
	}

	private function isAdmin(): bool
	{
		return $this->userId > 0 && \CRestUtil::isAdmin($this->userId);
	}
}
