<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Event;

use Bitrix\Main\DI\ServiceLocator;

final class ExcludedInstanceUserContext
{
	private const NATIVE_CONTEXT_INTERFACE = 'Bitrix\\Calendar\\Internal\\Integration\\AiAssistant\\Service\\CalendarUserContextInterface';

	public function run(int $userId, callable $callback): mixed
	{
		if (interface_exists(self::NATIVE_CONTEXT_INTERFACE))
		{
			return ServiceLocator::getInstance()->get(self::NATIVE_CONTEXT_INTERFACE)->run($userId, $callback);
		}

		$state = $this->apply($userId);
		try
		{
			return $callback();
		}
		finally
		{
			$this->restore($state);
		}
	}

	private function apply(int $userId): array
	{
		$state = [
			'exists' => array_key_exists('USER', $GLOBALS),
			'user' => $GLOBALS['USER'] ?? null,
			'socNetAdmin' => $this->getCalendarSocNetAdminCache(),
		];

		$GLOBALS['USER'] = new class($userId) {
			public function __construct(private readonly int $userId)
			{
			}

			public function IsAuthorized(): bool
			{
				return $this->userId > 0;
			}

			public function GetId(): int
			{
				return $this->userId;
			}

			public function GetParam(string $name): mixed
			{
				return null;
			}

			public function IsAdmin(): bool
			{
				return false;
			}

			public function CanDoOperation(string $operation): bool
			{
				return false;
			}

			public function GetUserGroupArray(): array
			{
				return \CUser::GetUserGroup($this->userId);
			}

			public static function GetHitAuthHash(): string
			{
				return '';
			}

			public static function AddHitAuthHash(string $hash): void
			{
			}

			public function Logout(): void
			{
			}
		};
		\CCalendar::GetCurUserId(true);
		$this->setCalendarSocNetAdminCache(null);

		return $state;
	}

	private function restore(array $state): void
	{
		if (!empty($state['exists']))
		{
			$GLOBALS['USER'] = $state['user'];
		}
		else
		{
			unset($GLOBALS['USER']);
		}

		\CCalendar::GetCurUserId(true);
		$this->setCalendarSocNetAdminCache($state['socNetAdmin'] ?? null);
	}

	private function getCalendarSocNetAdminCache(): mixed
	{
		$property = new \ReflectionProperty(\CCalendar::class, 'bCurUserSocNetAdmin');
		$property->setAccessible(true);

		return $property->getValue();
	}

	private function setCalendarSocNetAdminCache(mixed $value): void
	{
		$property = new \ReflectionProperty(\CCalendar::class, 'bCurUserSocNetAdmin');
		$property->setAccessible(true);
		$property->setValue(null, $value);
	}
}
