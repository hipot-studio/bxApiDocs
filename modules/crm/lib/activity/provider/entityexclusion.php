<?php

namespace Bitrix\Crm\Activity\Provider;

use Bitrix\Crm\Badge;
use Bitrix\Crm\Exclusion\Access;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use CCrmActivityDirection;
use CCrmOwnerType;

final class EntityExclusion extends Base
{
	public const PROVIDER_ID = 'CRM_ENTITY_EXCLUSION';
	public const PROVIDER_TYPE_ID_DEFAULT = 'ENTITY_EXCLUSION';

	private const IS_REACTION_REQUIRED_BY_DEFAULT = true;

	public static function getId(): string
	{
		return self::PROVIDER_ID;
	}

	public static function getTypeId(array $activity): string
	{
		return $activity['PROVIDER_TYPE_ID'] ?? self::PROVIDER_TYPE_ID_DEFAULT;
	}

	public static function getName(): string
	{
		return Loc::getMessage('CRM_ACTIVITY_PROVIDER_ENTITY_EXCLUSION_NAME') ?? '';
	}

	public static function hasPlanner(array $activity): bool
	{
		return false;
	}

	public static function isTypeEditable($providerTypeId = null, $direction = CCrmActivityDirection::Undefined): bool
	{
		return false;
	}

	public static function generateSubject($providerTypeId = null, $direction = CCrmActivityDirection::Undefined, array $replace = null): ?string
	{
		return Loc::getMessage(
			'CRM_ACTIVITY_PROVIDER_ENTITY_EXCLUSION_SUBJECT',
			['#COPILOT_NAME#' => AIManager::getCopilotName()]
		);
	}

	public static function getTypesFilterPresets(): array
	{
		return [
			[
				'NAME' => Loc::getMessage('CRM_ACTIVITY_PROVIDER_ENTITY_EXCLUSION_NAME'),
			],
		];
	}

	public static function getTypes(): array
	{
		return [
			[
				'NAME' => self::getName(),
				'PROVIDER_ID' => self::getId(),
				'PROVIDER_TYPE_ID' => self::PROVIDER_TYPE_ID_DEFAULT
			]
		];
	}

	public static function checkReadPermission(array $activityFields, $userId = null): bool
	{
		if (!parent::checkReadPermission($activityFields, $userId))
		{
			return false;
		}

		return (new Access($userId))->canRead();
	}

	public static function checkUpdatePermission(array $activityFields, $userId = null): bool
	{
		if (!parent::checkUpdatePermission($activityFields, $userId))
		{
			return false;
		}

		return (new Access($userId))->canWrite();
	}

	public static function checkFields($action, &$fields, $id, $params = null): Result
	{
		$previousFields = isset($params['PREVIOUS_FIELDS']) && is_array($params['PREVIOUS_FIELDS'])
			? $params['PREVIOUS_FIELDS']
			: []
		;

		if (empty($fields['SUBJECT']))
		{
			$fields['SUBJECT'] = self::generateSubject();
		}

		if (isset($fields['END_TIME']) && $fields['END_TIME'] !== '')
		{
			$fields['DEADLINE'] = $fields['END_TIME'];
		}

		if (
			$action === self::ACTION_UPDATE
			&& isset($fields['COMPLETED'])
			&& $fields['COMPLETED'] === 'Y'
		)
		{
			$fields['SETTINGS'] = array_merge(
				is_array($previousFields['SETTINGS'] ?? null) ? $previousFields['SETTINGS'] : [],
				is_array($fields['SETTINGS'] ?? null) ? $fields['SETTINGS'] : [],
			);
			unset($fields['SETTINGS']['REACTION_REQUIRES']);
		}

		return new Result();
	}

	/**
	 * @inheritdoc
	 */
	public static function onAfterUpdate(
		int $id,
		array $changedFields,
		array $oldFields,
		array $newFields,
		array $params = null
	): void
	{
		parent::onAfterUpdate($id, $changedFields, $oldFields, $newFields, $params);
	}

	/**
	 * @inheritdoc
	 */
	public static function syncBadges(int $activityId, array $activityFields, array $bindings): void
	{
		$badge = Container::getInstance()->getBadge(
			Badge\Badge::ENTITY_EXCLUSION_STATUS,
			Badge\Type\EntityExclusionStatus::REACTION_REQUIRES_VALUE,
		);

		$sourceIdentifier = new Badge\SourceIdentifier(
			Badge\SourceIdentifier::CRM_OWNER_TYPE_PROVIDER,
			CCrmOwnerType::Activity,
			$activityId,
		);

		$isCompleted = isset($activityFields['COMPLETED']) && $activityFields['COMPLETED'] === 'Y';
		if ($isCompleted)
		{
			foreach ($bindings as $singleBinding)
			{
				$itemIdentifier = new ItemIdentifier((int)$singleBinding['OWNER_TYPE_ID'], (int)$singleBinding['OWNER_ID']);
				$badge->unbind($itemIdentifier, $sourceIdentifier);
			}

			return;
		}

		$isReactionRequired = isset($activityFields['SETTINGS']['REACTION_REQUIRES'])
			&& $activityFields['SETTINGS']['REACTION_REQUIRES']
		;
		if ($isReactionRequired)
		{
			foreach ($bindings as $singleBinding)
			{
				$itemIdentifier = new ItemIdentifier((int)$singleBinding['OWNER_TYPE_ID'], (int)$singleBinding['OWNER_ID']);
				$badge->bind($itemIdentifier, $sourceIdentifier);
			}
		}
	}

	public function createActivity(string $typeId, array $fields, array $options = []): Result
	{
		$fields['IS_INCOMING_CHANNEL'] = 'Y';
		$fields['COMPLETED'] = 'N';

		if (empty($fields['SUBJECT']))
		{
			$fields['SUBJECT'] = self::generateSubject();
		}

		if (empty($fields['RESPONSIBLE_ID']))
		{
			$fields['RESPONSIBLE_ID'] = Container::getInstance()->getContext()->getUserId();
		}

		$fields['SETTINGS']['REACTION_REQUIRES'] = self::IS_REACTION_REQUIRED_BY_DEFAULT;

		return parent::createActivity($typeId, $fields, $options);
	}
}
