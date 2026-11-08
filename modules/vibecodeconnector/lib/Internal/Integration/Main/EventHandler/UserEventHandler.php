<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Main\EventHandler;

use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\Main\Application;
use Bitrix\Main\Diag\ExceptionHandlerLog;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemAccessType;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;
use Bitrix\Vibecodeconnector\Internal\Entity\User\UserEventChange;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemFilter;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogUserRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\AppChat\AppChatMembershipService;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserAttributesResolver;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserEventPublisher;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserEventsAvailability;

class UserEventHandler
{
	private static array $appReconciliationUsers = [];
	private static ?bool $hasRelevantApplications = null;

	public static function onBeforeUserUpdate(array &$fields): void
	{
		$userId = (int)($fields['ID'] ?? 0);
		if ($userId <= 0)
		{
			return;
		}

		try
		{
			if (
				array_key_exists('ACTIVE', $fields)
				&& !array_key_exists('PREV_ACTIVE', $fields)
			)
			{
				$fields['PREV_ACTIVE'] = static::getCurrentActive($userId);
			}
		}
		catch (\Throwable $exception)
		{
			static::logException($exception);
		}
	}

	public static function onAfterUserUpdate(array $fields): void
	{
		try
		{
			if (($fields['RESULT'] ?? null) !== true)
			{
				return;
			}

			$userId = (int)($fields['ID'] ?? 0);
			$organizationChanged = array_key_exists('UF_DEPARTMENT', $fields);
			$activeChanged = false;
			if (
				$userId > 0
				&& array_key_exists('ACTIVE', $fields)
				&& array_key_exists('PREV_ACTIVE', $fields)
			)
			{
				$change = match ([$fields['PREV_ACTIVE'], $fields['ACTIVE']]) {
					['Y', 'N'] => UserEventChange::Fired,
					['N', 'Y'] => UserEventChange::Restored,
					default => null,
				};
				if ($change !== null)
				{
					$activeChanged = true;
					if (static::isUserEventsEnabled())
					{
						static::publishSafely($userId, $change);
					}
				}
			}
			if ($userId > 0 && ($organizationChanged || $activeChanged))
			{
				self::scheduleAudienceReevaluation($userId, coalesce: !$activeChanged);
			}

		}
		catch (\Throwable $exception)
		{
			static::logException($exception);
		}
	}

	public static function onAfterSetUserGroup(mixed $userId, array $groups): void
	{
		try
		{
			$userId = (int)$userId;
			$publisher = static::getPublisher();
			if ($userId <= 0 || !$publisher->hasUser($userId))
			{
				return;
			}

			$attributes = static::getResolver()->resolveForUser($userId);
			$publisher->publishGroupsChanged($userId, $attributes['isAdmin'], $attributes['isIntegrator']);
		}
		catch (\Throwable $exception)
		{
			static::logException($exception);
		}
	}

	public static function onAfterUserDelete(mixed $userId): void
	{
		$userId = (int)$userId;

		try
		{
			(new CatalogUserRepository())->deleteAllForUser($userId);
		}
		catch (\Throwable $exception)
		{
			static::logException($exception);
		}

		try
		{
			self::scheduleAudienceReevaluation($userId, coalesce: false);
			static::getPublisher()->publish($userId, UserEventChange::Deleted);
		}
		catch (\Throwable $exception)
		{
			static::logException($exception);
		}
	}

	public static function onMemberAdded(Event $event): void
	{
		self::onOrganizationMemberChanged($event);
	}

	public static function onMemberUpdated(Event $event): void
	{
		self::onOrganizationMemberChanged($event);
	}

	public static function onMemberDeleted(Event $event): void
	{
		self::onOrganizationMemberChanged($event);
	}

	private static function onOrganizationMemberChanged(Event $event): void
	{
		try
		{
			$member = $event->getParameter('member');
			if (
				!$member instanceof NodeMember
				|| $member->entityType !== MemberEntityType::USER
				|| $member->entityId <= 0
			)
			{
				return;
			}

			self::scheduleAudienceReevaluation((int)$member->entityId);
		}
		catch (\Throwable $exception)
		{
			static::logException($exception);
		}
	}

	private static function scheduleAudienceReevaluation(int $userId, bool $coalesce = true): void
	{
		if ($userId <= 0)
		{
			return;
		}

		try
		{
			if (
				(!$coalesce || !isset(self::$appReconciliationUsers[$userId]))
				&& self::relevantApplicationsExist()
			)
			{
				static::scheduleAppChatReconciliation($userId);
				if ($coalesce)
				{
					self::$appReconciliationUsers[$userId] = true;
				}
			}
		}
		catch (\Throwable $exception)
		{
			static::logException($exception);
		}
	}

	private static function relevantApplicationsExist(): bool
	{
		return self::$hasRelevantApplications ??= static::hasRelevantApplications();
	}

	protected static function getPublisher(): UserEventPublisher
	{
		return ServiceLocator::getInstance()->get(UserEventPublisher::class);
	}

	protected static function getResolver(): UserAttributesResolver
	{
		return ServiceLocator::getInstance()->get(UserAttributesResolver::class);
	}

	protected static function isUserEventsEnabled(): bool
	{
		return (new UserEventsAvailability())->isEnabled();
	}

	protected static function publishSafely(int $userId, UserEventChange $change): void
	{
		try
		{
			static::getPublisher()->publish($userId, $change);
		}
		catch (\Throwable $exception)
		{
			static::logException($exception);
		}
	}

	protected static function logException(\Throwable $exception): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog(
			$exception,
			ExceptionHandlerLog::CAUGHT_EXCEPTION,
		);
	}

	protected static function scheduleAppChatReconciliation(int $userId): void
	{
		try
		{
			ServiceLocator::getInstance()
				->get(AppChatMembershipService::class)
				->requestUserSync($userId)
			;
		}
		catch (\Throwable $exception)
		{
			static::logException($exception);
		}
	}

	protected static function hasRelevantApplications(): bool
	{
		return (new CatalogItemRepository())->exists(
			(new CatalogItemFilter())
				->type(CatalogItemType::Application)
				->accessTypeIn([CatalogItemAccessType::Public, CatalogItemAccessType::ACL]),
		);
	}

	protected static function getCurrentGroupIds(int $userId): array
	{
		return \CUser::GetUserGroup($userId);
	}

	protected static function getCurrentActive(int $userId): ?string
	{
		$user = \Bitrix\Main\UserTable::query()
			->setSelect(['ACTIVE'])
			->where('ID', $userId)
			->setLimit(1)
			->fetch()
		;

		return is_array($user) ? ($user['ACTIVE'] ?? null) : null;
	}
}
