<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access\App\Model;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\Internal\Entity\Application\App;
use Bitrix\Rest\Internal\Entity\Application\AppAttributeCode;
use Bitrix\Rest\Internal\Repository\Application\AppRepository;

final class AppModel implements AccessibleItem
{
	private ?App $app = null;

	public static function createFromId(?int $itemId): self
	{
		$model = new self();

		if ($itemId !== null && $itemId > 0)
		{
			/** @var AppRepository $repository */
			$repository = ServiceLocator::getInstance()->get('rest.repository.app');
			$model->app = $repository->getById($itemId);
		}

		return $model;
	}

	public static function createFromApp(App $app): self
	{
		$model = new self();
		$model->app = $app;

		return $model;
	}

	public function getId(): int
	{
		return $this->app?->getId() ?? 0;
	}

	public function getApp(): ?App
	{
		return $this->app;
	}

	public function getOwnerUserId(): ?int
	{
		$value = $this->app?->getAttributeValue(AppAttributeCode::OwnerUserId->value);

		return $value !== null ? (int)$value : null;
	}

	public function isPersonal(): bool
	{
		return $this->getOwnerUserId() !== null;
	}

	public function getAccessCodes(): array
	{
		$access = $this->app?->getAccess();

		if (empty($access))
		{
			return [];
		}

		return explode(',', $access);
	}
}
