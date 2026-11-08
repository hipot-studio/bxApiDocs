<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Bizproc;

use Bitrix\Crm\Category\DealCategory;
use Bitrix\Crm\EntityRequisite;
use Bitrix\Crm\Requisite\AddressRequisiteConverter;
use Bitrix\Crm\Tracking\UI\Details;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\Date;

/**
 * Field casting and test document creation for lead, contact, company and deal workflow documents.
 * CCrmDocument* classes stay the entry points bizproc calls by name and delegate here.
 *
 * @internal
 */
final class WorkflowDocument
{
	private const MULTI_FIELD_TYPES = ['phone', 'email', 'im', 'web'];

	private const ADDRESS_FIELDS = [
		\CCrmOwnerType::Company => ['ADDRESS', 'REG_ADDRESS'],
		\CCrmOwnerType::Contact => ['ADDRESS', 'ADDRESS_2', 'ADDRESS_CITY'],
	];

	public function __construct()
	{
		Loader::requireModule('bizproc');
	}

	public function castFields(int $entityTypeId, array $fields, int $entityId = 0): array
	{
		$documentClass = \CCrmBizProcHelper::ResolveDocumentName($entityTypeId);
		$documentFields = $documentClass::GetDocumentFields(\CCrmOwnerType::ResolveName($entityTypeId));
		$documentId = \CCrmBizProcHelper::ResolveDocumentId($entityTypeId, $entityId);
		$isDeal = $entityTypeId === \CCrmOwnerType::Deal;

		foreach (array_keys($fields) as $key)
		{
			if (!array_key_exists($key, $documentFields))
			{
				unset($fields[$key]);
				continue;
			}

			$fieldType = $documentFields[$key]['Type'];
			if (in_array($fieldType, self::MULTI_FIELD_TYPES, true))
			{
				\CCrmDocument::PrepareEntityMultiFields($fields, mb_strtoupper($fieldType));
				continue;
			}

			$fields[$key] = (is_array($fields[$key]) && !\CBPHelper::IsAssociativeArray($fields[$key]))
				? $fields[$key]
				: [$fields[$key]];

			if ($fieldType === 'user')
			{
				$fields[$key] = \CBPHelper::extractUsers($fields[$key], $documentId);
			}
			elseif ($fieldType === 'select' && str_starts_with((string)$key, 'UF_'))
			{
				\CCrmDocument::InternalizeEnumerationField(
					\CCrmOwnerType::ResolveUserFieldEntityID($entityTypeId),
					$fields,
					$key,
				);
			}
			elseif ($fieldType === 'file')
			{
				$fields[$key] = \CCrmDocument::castFileFieldValues($entityId, $entityTypeId, $key, $fields[$key]);
			}
			elseif ($fieldType === 'S:HTML')
			{
				foreach ($fields[$key] as &$value)
				{
					$value = ['VALUE' => $value];
				}
				unset($value);
			}
			elseif ($isDeal && $fieldType === 'string')
			{
				$fields[$key] = \CBPHelper::makeArrayFlat($fields[$key]);
			}

			if (!($documentFields[$key]['Multiple'] ?? false) && is_array($fields[$key]))
			{
				$fields[$key] = $fields[$key] === [] ? null : array_values($fields[$key])[0];
			}
		}

		if ($entityTypeId === \CCrmOwnerType::Company)
		{
			if (isset($fields['CONTACT_ID']) && !is_array($fields['CONTACT_ID']))
			{
				$fields['CONTACT_ID'] = [$fields['CONTACT_ID']];
			}
			if (isset($fields['ADDRESS_LEGAL']))
			{
				$fields['REG_ADDRESS'] = $fields['ADDRESS_LEGAL'];
				unset($fields['ADDRESS_LEGAL']);
			}
		}

		if (isset($fields['COMMENTS']))
		{
			$fields['COMMENTS'] = \CCrmDocument::sanitizeCommentsValue($fields['COMMENTS']);
		}

		foreach (['BEGINDATE', 'CLOSEDATE'] as $dateField)
		{
			if (isset($fields[$dateField]))
			{
				$fields[$dateField] = self::castDateValue($fields[$dateField]);
			}
		}

		return $fields;
	}

	/**
	 * @throws \Exception the stage belongs to another category
	 */
	public function castDealCategoryAndStage(array $fields, ?int $presentCategoryId = null): array
	{
		if (!isset($fields['STAGE_ID']))
		{
			return $fields;
		}

		if ($fields['STAGE_ID'] === '')
		{
			unset($fields['STAGE_ID']);

			return $fields;
		}

		$stageId = $fields['STAGE_ID'];
		$stageCategoryId = DealCategory::resolveFromStageID($stageId);
		if ($presentCategoryId === null && !isset($fields['CATEGORY_ID']))
		{
			$fields['CATEGORY_ID'] = $stageCategoryId;

			return $fields;
		}

		$categoryId = $presentCategoryId ?? (int)$fields['CATEGORY_ID'];
		if ($categoryId !== $stageCategoryId)
		{
			Loc::loadMessages(dirname(__DIR__, 5) . '/classes/general/crm_document.php');

			throw new \Exception(
				Loc::getMessage('CRM_DOCUMENT_DEAL_STAGE_MISMATCH_ERROR', [
					'#CATEGORY#' => DealCategory::getName($categoryId),
					'#TARG_CATEGORY#' => DealCategory::getName($stageCategoryId),
					'#TARG_STAGE#' => DealCategory::getStageName($stageId, $stageCategoryId),
				]) ?? ''
			);
		}

		return $fields;
	}

	public function createAddressRequisite(int $entityTypeId, int $entityId, array $fields): void
	{
		if (!self::hasAddress($entityTypeId, $fields))
		{
			return;
		}

		$presetId = EntityRequisite::getDefaultPresetId($entityTypeId);
		if ($presetId > 0)
		{
			(new AddressRequisiteConverter($entityTypeId, $presetId, false))->processEntity($entityId);
		}
	}

	/**
	 * @throws \Exception the entity was not added
	 */
	public function createTestDocument(int $entityTypeId, array $fields, int $createdById): ?string
	{
		if ($fields === [])
		{
			return null;
		}

		$fields = $this->castFields($entityTypeId, $fields);
		if ($entityTypeId === \CCrmOwnerType::Deal)
		{
			$fields = $this->castDealCategoryAndStage($fields);
		}

		$entity = self::entity($entityTypeId);
		$id = $entity->Add(
			$fields,
			true,
			[
				'DISABLE_USER_FIELD_CHECK' => true,
				'REGISTER_SONET_EVENT' => true,
				'CURRENT_USER' => $createdById,
			]
		);

		if (!$id || $id <= 0)
		{
			throw new \Exception((string)$entity->LAST_ERROR);
		}

		$this->createAddressRequisite($entityTypeId, (int)$id, $fields);

		if (isset($fields['TRACKING_SOURCE_ID']))
		{
			Details::saveEntityData($entityTypeId, $id, $fields);
		}

		return \CCrmOwnerType::ResolveName($entityTypeId) . '_' . $id;
	}

	private static function entity(int $entityTypeId): \CCrmLead|\CCrmContact|\CCrmCompany|\CCrmDeal
	{
		return match ($entityTypeId)
		{
			\CCrmOwnerType::Lead => new \CCrmLead(true),
			\CCrmOwnerType::Contact => new \CCrmContact(true),
			\CCrmOwnerType::Company => new \CCrmCompany(true),
			\CCrmOwnerType::Deal => new \CCrmDeal(true),
		};
	}

	private static function hasAddress(int $entityTypeId, array $fields): bool
	{
		foreach (self::ADDRESS_FIELDS[$entityTypeId] ?? [] as $field)
		{
			if (($fields[$field] ?? '') !== '')
			{
				return true;
			}
		}

		return false;
	}

	private static function castDateValue(mixed $value): mixed
	{
		if ($value instanceof Date)
		{
			return (string)$value;
		}

		if (is_object($value) && method_exists($value, '__toString') && Date::isCorrect((string)$value))
		{
			return (string)$value;
		}

		return $value;
	}
}
