<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Integration\Im\Action\CounterRecipients\Mapper\MapperInterface;

abstract class RecipientsResolver
{
	public AbstractNotify $notification;
	public Entity\Task $task;
	public ?Entity\Task $taskWithMembers = null;
	public Entity\UserCollection $recipients;

	public function __construct(
		protected readonly Container $container,
	)
	{
	}

	abstract protected function isAllowedMapper(MapperInterface $mapper): bool;

	public function resolve(AbstractNotify $notification, Entity\Task $task): Entity\UserCollection
	{
		$this->notification = $notification;
		$this->task = $task;
		$this->recipients = new Entity\UserCollection();

		return $this->map()->reduce()->recipients;
	}

	public function map(): static
	{
		foreach ($this->getMappersFromNotification() as $mapper)
		{
			if ($this->isAllowedMapper($mapper))
			{
				$mapper($this);
			}
		}

		return $this;
	}

	public function reduce(): static
	{
		foreach ($this->getReducersFromNotification() as $reducer)
		{
			$reducer($this);
		}

		return $this;
	}

	/** @return CounterRecipients\Mapper\MapperInterface[] */
	protected function getMappersFromNotification(): \Generator
	{
		foreach (Recipients::getFromNotification($this->notification) as $attribute)
		{
			foreach ($attribute->mappers as $mapper)
			{
				yield $this->container->get($mapper);
			}
		}
	}

	/** @return CounterRecipients\Reducer\ReducerInterface[] */
	protected function getReducersFromNotification(): \Generator
	{
		foreach (Recipients::getFromNotification($this->notification) as $attribute)
		{
			foreach ($attribute->reducers as $reducer)
			{
				yield $this->container->get($reducer);
			}
		}
	}
}
