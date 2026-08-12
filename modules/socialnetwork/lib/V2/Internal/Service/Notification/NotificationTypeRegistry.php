<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Notification;

use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\Collab\Integration\IM\ActionType;
use Bitrix\Socialnetwork\V2\Internal\Entity\Notification\NotificationGroup;
use Bitrix\Socialnetwork\V2\Internal\Entity\Notification\NotificationType;

class NotificationTypeRegistry
{
	/**
	 * Maps member-side ActionType values to the corresponding NotificationType id.
	 * Types outside this map (CreateProject*, Convert*, CopyLink, RegenerateLink) are not configurable.
	 */
	private const ACTION_TYPE_MAP = [
		ActionType::AddUser->value => NotificationType::MemberAddEmployee,
		ActionType::AddGuest->value => NotificationType::MemberAddGuest,
		ActionType::InviteGuest->value => NotificationType::MemberInvite,
		ActionType::InviteUser->value => NotificationType::MemberInvite,
		ActionType::AcceptUser->value => NotificationType::MemberJoin,
		ActionType::JoinUser->value => NotificationType::MemberJoin,
		ActionType::LeaveUser->value => NotificationType::MemberLeave,
		ActionType::ExcludeUser->value => NotificationType::MemberExclude,
		ActionType::AddBot->value => NotificationType::MemberAddBot,
	];

	/**
	 * Returns all configurable notification types with their metadata.
	 *
	 * @return list<array{type: NotificationType, wireKey: string, group: NotificationGroup, defaultCounter: bool, labelKey: string}>
	 */
	public static function getAll(): array
	{
		$result = [];
		foreach (NotificationType::cases() as $type)
		{
			$result[] = [
				'type' => $type,
				'wireKey' => $type->getWireKey(),
				'group' => $type->getGroup(),
				'defaultCounter' => $type->getDefaultCounter(),
				'labelKey' => $type->getLabelKey(),
			];
		}

		return $result;
	}

	/**
	 * Looks up a NotificationType by its stable id.
	 */
	public static function findById(string $id): ?NotificationType
	{
		return NotificationType::tryFrom($id);
	}

	/**
	 * Looks up a NotificationType by an ActionType from the member pipeline.
	 * Returns null for ActionTypes that are not in the configurable registry.
	 */
	public static function findByActionType(ActionType $actionType): ?NotificationType
	{
		return self::ACTION_TYPE_MAP[$actionType->value] ?? null;
	}

	/**
	 * Returns the default counter flag for a notification type id.
	 * Returns null if the type is not in the registry.
	 */
	public static function resolveDefaultCounter(string $id): ?bool
	{
		$type = NotificationType::tryFrom($id);
		if ($type === null)
		{
			return null;
		}

		return $type->getDefaultCounter();
	}

	/**
	 * Returns all types belonging to the given group.
	 *
	 * @return list<NotificationType>
	 */
	public static function getByGroup(NotificationGroup $group): array
	{
		return array_values(
			array_filter(
				NotificationType::cases(),
				static fn(NotificationType $type): bool => $type->getGroup() === $group,
			),
		);
	}

	/**
	 * Returns the localized label for a notification type.
	 */
	public static function getTypeLabel(NotificationType $type): string
	{
		return Loc::getMessage($type->getLabelKey()) ?? $type->getLabelKey();
	}

	/**
	 * Returns the localized label for a notification group.
	 */
	public static function getGroupLabel(NotificationGroup $group): string
	{
		return Loc::getMessage($group->getLabelKey()) ?? $group->getLabelKey();
	}
}
