<?php

namespace Bitrix\Tasks\Access\Model;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Tasks\Internals\Task\Result\Result;
use Bitrix\Tasks\Internals\Task\Result\ResultTable;
use Bitrix\Tasks\V2\Internal\Access\Registry\ResultRegistry;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Integration\Im\Entity\Chat;
use ReflectionClass;

final class ResultModel implements AccessibleItem
{
	private int $id;
	private ?int $taskId = null;
	private ?int $createdBy = null;
	private ?int $status = null;
	private ?int $messageId = null;
	private ?Chat $chat = null;

	private static array $cache = [];

	public static function createFromId(int $itemId): self
	{
		if (array_key_exists($itemId, self::$cache))
		{
			return self::$cache[$itemId];
		}

		$model = new self();
		$model->id = $itemId;

		self::$cache[$itemId] = $model;

		return $model;
	}

	public static function createFromArray(array|Arrayable $data): self
	{
		if ($data instanceof Arrayable)
		{
			$data = $data->toArray();
		}

		$model = new self();

		$reflection = new ReflectionClass($model);

		foreach ($data as $key => $value)
		{
			if ($reflection->hasProperty($key))
			{
				$model->{$key} = $value;
			}
		}

		return $model;
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getTaskId(): ?int
	{
		$this->taskId ??= $this->getEntity()?->getTaskId();

		return $this->taskId;
	}

	public function getCreatedBy(): ?int
	{
		$this->createdBy ??= $this->getEntity()?->getCreatedBy();

		return $this->createdBy;
	}

	public function isOpened(): bool
	{
		$this->status ??= $this->getEntity()?->getStatus();

		return $this->status === ResultTable::STATUS_OPENED;
	}

	public function getChat(): ?Chat
	{
		$this->messageId ??= $this->getEntity()?->getMessage()?->getMessageId();

		if ($this->messageId > 0)
		{
			$repository = Container::getInstance()->getMessageRepository();

			$this->chat ??= $repository->getById($this->messageId)?->chat;
		}

		return $this->chat;
	}

	private function getEntity(): ?Result
	{
		return ResultRegistry::getInstance()->get($this->id);
	}
}
