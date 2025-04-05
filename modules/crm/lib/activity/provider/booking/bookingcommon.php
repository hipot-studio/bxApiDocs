<?php

namespace Bitrix\Crm\Activity\Provider\Booking;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Timeline;

class BookingCommon
{
	public static function makeBindings(array $entity): array
	{
		$bindings = [];

		foreach ($entity['clients'] as $client)
		{
			$clientTypeModule = $client['type']['module'] ?? '';
			$clientTypeCode = $client['type']['code'] ?? '';

			if ($clientTypeModule !== 'crm')
			{
				continue;
			}

			$ownerTypeId = \CCrmOwnerType::ResolveID($clientTypeCode);
			if (!in_array($ownerTypeId, [\CCrmOwnerType::Contact, \CCrmOwnerType::Company], true))
			{
				continue;
			}

			$ownerId = isset($client['id']) ? (int)$client['id'] : 0;
			if (!$ownerId)
			{
				continue;
			}

			$bindings[] = [
				'OWNER_TYPE_ID' => $ownerTypeId,
				'OWNER_ID' => $ownerId,
			];
		}

		foreach ($entity['externalData'] as $externalData)
		{
			$isCrm = isset($externalData['moduleId']) && $externalData['moduleId'] === 'crm';
			$ownerTypeId = \CCrmOwnerType::ResolveID($externalData['entityTypeId']);
			$ownerId = isset($externalData['value']) ? (int)$externalData['value'] : 0;

			if (
				$isCrm
				&& (
					$ownerTypeId === \CCrmOwnerType::Deal
					|| \CCrmOwnerType::isPossibleDynamicTypeId($ownerTypeId)
				)
				&& $ownerId
			)
			{
				$bindings[] = [
					'OWNER_TYPE_ID' => $ownerTypeId,
					'OWNER_ID' => $ownerId,
				];
			}
		}

		return $bindings;
	}

	public static function getActivitySubject(string $typeName, string $name): string
	{
		return sprintf('%s: %s', $typeName, $name);
	}

	public static function sendPullEventOnAdd(array $activity): void
	{
		$activityController = Timeline\ActivityController::getInstance();

		foreach ($activity['BINDINGS'] as $binding)
		{
			$identifier = new ItemIdentifier($binding['OWNER_TYPE_ID'], $binding['OWNER_ID']);

			$activityController->sendPullEventOnAddScheduled($identifier, $activity);
		}
	}

	public static function sendPullEventOnUpdate(array $activity): void
	{
		$activityController = Timeline\ActivityController::getInstance();

		foreach ($activity['BINDINGS'] as $binding)
		{
			$identifier = new ItemIdentifier($binding['OWNER_TYPE_ID'], $binding['OWNER_ID']);

			$activityController->sendPullEventOnUpdateScheduled($identifier, $activity);
		}
	}

	public static function sendPullEventOnDelete(array $activity): void
	{
		$activityController = Timeline\ActivityController::getInstance();

		foreach ($activity['BINDINGS'] as $binding)
		{
			$identifier = new ItemIdentifier($binding['OWNER_TYPE_ID'], $binding['OWNER_ID']);

			$activityController->sendPullEventOnDelete($identifier, $activity['ID']);
		}
	}

	public static function updateActivity(
		string $providerId,
		string $typeId,
		array $entity,
		array $bindings,
	): int|null
	{
		$existingActivity = \CCrmActivity::getList(
			[],
			[
				'=PROVIDER_ID' => $providerId,
				'=PROVIDER_TYPE_ID' => $typeId,
				'=ASSOCIATED_ENTITY_ID' => $entity['id'],
				'=COMPLETED' => 'N',
				'CHECK_PERMISSIONS' => 'N',
			]
		)?->fetch();

		if (!$existingActivity)
		{
			return null;
		}

		$existingActivity['BINDINGS'] = $bindings;
		$existingActivity['SETTINGS']['FIELDS'] = $entity;

		$updated = \CCrmActivity::update($existingActivity['ID'], $existingActivity, false);

		if ($updated)
		{
			self::sendPullEventOnUpdate($existingActivity);
		}

		return $existingActivity['ID'];
	}

	public static function createActivity(
		string $providerId,
		string $typeId,
		array $entity,
		array $bindings,
		string $subject,
	): int|null
	{
		$authorId = $entity['createdBy'];

		$fields = [
			'TYPE_ID' => \CCrmActivityType::Provider,
			'PROVIDER_ID' => $providerId,
			'PROVIDER_TYPE_ID' => $typeId,
			'ASSOCIATED_ENTITY_ID' => $entity['id'],
			'SUBJECT' => $subject,
			'IS_HANDLEABLE' => 'Y',
			'IS_INCOMING_CHANNEL' => 'N',
			'COMPLETED' => 'N',
			'STATUS' => \CCrmActivityStatus::Waiting,
			'RESPONSIBLE_ID' => $authorId,
			'PRIORITY' => \CCrmActivityPriority::Medium,
			'AUTHOR_ID' => $authorId,
			'BINDINGS' => $bindings,
			'SETTINGS' => [
				'FIELDS' => $entity,
			],
		];

		$activityId = (int)\CCrmActivity::add($fields, false);

		if ($activityId)
		{
			self::sendPullEventOnAdd(['ID' => $activityId, ...$fields]);
		}

		return $activityId;
	}
}
