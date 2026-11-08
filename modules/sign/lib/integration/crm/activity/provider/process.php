<?php

namespace Bitrix\Sign\Integration\CRM\Activity\Provider;

use Bitrix\Crm\Activity\Provider\Base;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Sign\Config\Feature;

Loc::loadMessages(__FILE__);

/**
 * Deal of the timeline of a B2E document that answers which personnel process created the document.
 * It stands beside the existing SignB2eDocument deal and does not replace it: that one answers what
 * is happening to the document, this one answers where the document came from.
 *
 * The activity is bound to the document and points at the element of the process through
 * ASSOCIATED_ENTITY_ID, so one process that created several documents is answered by one deal per
 * document.
 */
final class Process extends Base
{
	public const PROVIDER_ID = 'SIGN_KEDO_PROCESS';
	public const PROVIDER_TYPE_ID_PROCESS = 'KEDO_PROCESS';

	/**
	 * Handler of crm:OnGetActivityProviders - the only way this provider reaches the registry of crm.
	 * The class is handed over whatever the feature flag says: the registry is also what resolves the
	 * provider of rows that already exist, and closing the flag hides the deal rather than orphans it.
	 *
	 * @return string[]
	 */
	public static function onGetActivityProviders(): array
	{
		return [self::class];
	}

	public static function getId(): string
	{
		return self::PROVIDER_ID;
	}

	public static function isActive(): bool
	{
		return Feature::instance()->isKedoRunProcessEnabled();
	}

	public static function getName(): ?string
	{
		return Loc::getMessage('SIGN_INTEGRATION_CRM_ACTIVITY_PROVIDER_PROCESS_NAME');
	}

	public static function getTypes(): array
	{
		return [
			[
				'NAME' => self::getName(),
				'PROVIDER_ID' => self::PROVIDER_ID,
				'PROVIDER_TYPE_ID' => self::PROVIDER_TYPE_ID_PROCESS,
			],
		];
	}

	public static function generateSubject(
		$providerTypeId = null,
		$direction = \CCrmActivityDirection::Undefined,
		?array $replace = null,
	): string
	{
		return (string)Loc::getMessage('SIGN_INTEGRATION_CRM_ACTIVITY_PROVIDER_PROCESS_SUBJECT', $replace);
	}

	public static function isTypeEditable($providerTypeId = null, $direction = \CCrmActivityDirection::Undefined): bool
	{
		return false;
	}

	public static function isCompletable(): bool
	{
		return false;
	}

	public static function checkPostponePermission($entityId, array $activity, $userId): bool
	{
		return false;
	}

	public static function checkFields($action, &$fields, $id, $params = null): Result
	{
		$result = new Result();

		$fields['PROVIDER_TYPE_ID'] = self::PROVIDER_TYPE_ID_PROCESS;

		if ($action === self::ACTION_ADD && (int)($fields['ASSOCIATED_ENTITY_ID'] ?? 0) <= 0)
		{
			$result->addError(new Error('ASSOCIATED_ENTITY_ID is required for ' . self::class));
		}

		return $result;
	}

	/**
	 * The right to see the deal is counted on the document it stands in and never on the element of
	 * the process it points at: the two permission models are independent, and the person reading the
	 * timeline is reading the document.
	 */
	public static function checkReadPermission(array $activityFields, $userId = null): bool
	{
		if (!self::isActive())
		{
			return false;
		}

		if (!parent::checkReadPermission($activityFields, $userId))
		{
			return false;
		}

		return self::canReadBoundDocument($activityFields, $userId);
	}

	/**
	 * A row bound to anything but a B2E document names no document to ask about, so it is refused
	 * instead of being read through the binding it happens to carry.
	 */
	private static function canReadBoundDocument(array $activityFields, mixed $userId): bool
	{
		if (!Loader::includeModule('crm'))
		{
			return false;
		}

		if ((int)($activityFields['OWNER_TYPE_ID'] ?? 0) !== \CCrmOwnerType::SmartB2eDocument)
		{
			return false;
		}

		$documentId = (int)($activityFields['OWNER_ID'] ?? 0);
		if ($documentId <= 0)
		{
			return false;
		}

		$userId = is_numeric($userId) ? (int)$userId : null;
		if ($userId !== null && $userId <= 0)
		{
			$userId = null;
		}

		return Container::getInstance()
			->getUserPermissions($userId)
			->item()
			->canRead(\CCrmOwnerType::SmartB2eDocument, $documentId)
		;
	}
}
