<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Tracking\Elapsed;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Tasks\Internals\Task\ElapsedTimeObject;
use Bitrix\Tasks\V2\Internal\Access\Registry\ElapsedTimeRegistry;

final class ElapsedTimeModel implements AccessibleItem
{
	private int $id = 0;
	private ?int $userId = null;

	public static function createFromArray(array|Arrayable $data): self
	{
		if ($data instanceof Arrayable)
		{
			$data = $data->toArray();
		}

		$model = new self();
		if (isset($data['id']))
		{
			$model->id = (int)$data['id'];
		}

		if (isset($data['userId']))
		{
			$model->userId = (int)$data['userId'];
		}

		return $model;
	}

	public static function createFromId(int $itemId): AccessibleItem
	{
		$model = new self();
		$model->id = $itemId;

		return $model;
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getUserId(): int
	{
		$this->userId ??= (int)$this->getObject()?->getUserId();

		return $this->userId;
	}

	private function getObject(): ?ElapsedTimeObject
	{
		return ElapsedTimeRegistry::getInstance()->get($this->id);
	}
}
