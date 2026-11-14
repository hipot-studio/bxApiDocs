<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access\App\Model;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\Internal\Entity\Application\App;
use Bitrix\Rest\Internal\Repository\Application\AppRepository;

final class AppModel implements AccessibleItem
{
	private ?App $app = null;
	private bool $appLoaded = true;
	private int $id = 0;
	private bool $installed = false;
	private ?int $ownerUserId = null;
	private array $accessCodes = [];

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

	/**
	 * @param array{ID: int, INSTALLED: bool, OWNER_USER_ID: ?int, ACCESS_CODES: string[]} $data
	 */
	public static function createFromAccessData(array $data): self
	{
		$model = new self();
		$model->id = $data['ID'];
		$model->installed = $data['INSTALLED'];
		$model->ownerUserId = $data['OWNER_USER_ID'];
		$model->accessCodes = $data['ACCESS_CODES'];
		$model->appLoaded = false;

		return $model;
	}

	public function getId(): int
	{
		return $this->appLoaded ? ($this->app?->getId() ?? 0) : $this->id;
	}

	public function getApp(): ?App
	{
		if (!$this->appLoaded)
		{
			$this->app = (new AppRepository())->getById($this->getId());
			$this->appLoaded = true;
		}

		return $this->app;
	}

	public function isInstalled(): bool
	{
		return $this->appLoaded ? ($this->app?->isInstalled() ?? false) : $this->installed;
	}

	public function getOwnerUserId(): ?int
	{
		if (!$this->appLoaded)
		{
			return $this->ownerUserId;
		}

		return $this->app?->getOwnerUserId();
	}

	public function isPersonal(): bool
	{
		return $this->getOwnerUserId() !== null;
	}

	public function getAccessCodes(): array
	{
		if (!$this->appLoaded)
		{
			return $this->accessCodes;
		}

		$access = $this->app?->getAccess();

		if (empty($access))
		{
			return [];
		}

		return explode(',', $access);
	}
}
