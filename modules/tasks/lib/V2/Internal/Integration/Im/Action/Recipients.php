<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

use Bitrix\Tasks\V2\Internal\Service\Task\Role;

#[\Attribute(\Attribute::TARGET_CLASS)]
class Recipients
{
	private static array $attributes = [];

	/**
	 * @param class-string[] $reducers
	 */
	public function __construct(
		private bool $creator = false,
		private bool $responsible = false,
		private bool $accomplices = false,
		private bool $auditors = false,
		/** @var class-string<CounterRecipients\Mapper\MapperInterface>[] */
		public ?array $mappers = null,
		/** @var class-string<CounterRecipients\Reducer\ReducerInterface>[] */
		public ?array $reducers = null,
	)
	{
		if (null === $mappers)
		{
			$this->mappers = [
				CounterRecipients\Mapper\DefaultMapper::class,
			];
		}

		if (null === $reducers)
		{
			$this->reducers = [
				CounterRecipients\Reducer\ExcludeNonUniqueUsers::class,
				CounterRecipients\Reducer\ExcludeTriggeredUser::class,
			];
		}
	}

	public function getRecipients(): array
	{
		$recipients = [];

		if ($this->creator)
		{
			$recipients[] = Role::Creator;
		}

		if ($this->responsible)
		{
			$recipients[] = Role::Responsible;
		}

		if ($this->accomplices)
		{
			$recipients[] = Role::Accomplice;
		}

		if ($this->auditors)
		{
			$recipients[] = Role::Auditor;
		}

		return $recipients;
	}

	/** @return self[] */
	public static function getFromNotification(AbstractNotify $notification): array
	{
		if (!array_key_exists($notification::class, self::$attributes))
		{
			$reflection = new \ReflectionClass($notification);
			self::$attributes[$notification::class] = array_map(
				fn (\ReflectionAttribute $attribute): self => $attribute->newInstance(),
				$reflection->getAttributes(self::class),
			);
		}

		return self::$attributes[$notification::class];
	}
}
