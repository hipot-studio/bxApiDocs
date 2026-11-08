<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Internal\Integration\Crm;

use Bitrix\Calendar\Internal\Service\PublicBooking\Source\BookingSourceInterface;
use Bitrix\Calendar\Internal\Service\PublicBooking\Source\SourceDescriptor;
use Bitrix\Calendar\Internal\Service\PublicBooking\Source\TrustedBookingConfig;
use Bitrix\Crm\WebForm\Internals\FieldTable;
use Bitrix\Main\Loader;

/**
 * Booking source strategy for CRM web forms.
 *
 * Authority source: SETTINGS_DATA stored server-side in b_crm_webform_field.
 * For a resourcebooking field the booking config (users/resources value) is stored
 * flat in SETTINGS_DATA itself (see crm Options\Fields, resourcebooking write branch),
 * not nested under a 'settingsData' key. Client-supplied settingsData is never used here.
 *
 * Not final: form loading and config resolution are exposed via protected seams so the
 * sec/active gate can be unit-tested without a real CRM form.
 */
class CrmWebFormBookingSource implements BookingSourceInterface
{
	private const SOURCE_TYPE = 'crm-webform';

	public function supports(SourceDescriptor $descriptor): bool
	{
		return $descriptor->getType() === self::SOURCE_TYPE;
	}

	public function resolve(SourceDescriptor $descriptor): ?TrustedBookingConfig
	{
		if (!Loader::includeModule('crm'))
		{
			return null;
		}

		$formId = (int)$descriptor->getParam('formId');
		$sec = (string)$descriptor->getParam('sec');
		$fieldCode = (string)$descriptor->getParam('fieldCode');

		if ($formId <= 0 || $sec === '' || $fieldCode === '')
		{
			return null;
		}

		// Cheap pre-check: load only the b_crm_webform row before the sec/active gate,
		// so anonymous formId scanning with a wrong sec does not trigger the field read.
		$formRow = $this->loadFormRow($formId);
		if ($formRow === null)
		{
			return null;
		}

		if (!$formRow->checkSecurityCode($sec))
		{
			return null;
		}

		// Same gate as every other public form surface (embed REST, fill component):
		// a deactivated form must stop serving booking availability.
		if (!$formRow->isActive())
		{
			return null;
		}

		return $this->readTrustedConfig($formId, $fieldCode);
	}

	/**
	 * Reads the single resourcebooking field row (one b_crm_webform_field query) and builds the
	 * trusted config from its server-side SETTINGS_DATA. Kept protected as a seam so unit tests can
	 * assert it runs only after the sec/active gate passes, without hitting the DB.
	 */
	protected function readTrustedConfig(int $formId, string $fieldCode): ?TrustedBookingConfig
	{
		$row = FieldTable::query()
			->setSelect(['CODE', 'TYPE', 'SETTINGS_DATA'])
			->where('FORM_ID', $formId)
			->where('CODE', $fieldCode)
			->where('TYPE', FieldTable::TYPE_ENUM_RESOURCEBOOKING)
			->setOrder(['SORT' => 'ASC', 'ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;

		if (!is_array($row))
		{
			return null;
		}

		$entityFieldName = $this->extractEntityFieldName($row['CODE']);

		return self::buildConfig($row, $entityFieldName);
	}

	// Loads the security gate data (single b_crm_webform read). Returns null when the form is missing.
	protected function loadFormRow(int $formId): ?\Bitrix\Crm\WebForm\Form
	{
		$form = new \Bitrix\Crm\WebForm\Form();

		return $form->loadOnlySecurityData($formId) ? $form : null;
	}

	/**
	 * Web-form field names are built as {CRM entity name}_{entity field name}.
	 * ResolveID keeps the same first-recognized-prefix rule as the web-form provider,
	 * without loading that provider's complete field metadata tree.
	 */
	protected function extractEntityFieldName(string $fieldCode): ?string
	{
		$entityName = '';
		foreach (explode('_', $fieldCode) as $fieldCodePart)
		{
			$entityName .= ($entityName === '' ? '' : '_') . $fieldCodePart;
			$entityTypeId = \CCrmOwnerType::ResolveID($entityName);
			if ($entityTypeId)
			{
				$fieldName = mb_substr($fieldCode, mb_strlen($entityName) + 1);

				return $fieldName !== '' && $this->hasEntityUserField($entityTypeId, $fieldName)
					? $fieldName
					: null;
			}
		}

		return null;
	}

	// Checks one user-field row instead of loading the web-form provider's full metadata tree.
	protected function hasEntityUserField(int $entityTypeId, string $fieldName): bool
	{
		$entityId = \CCrmOwnerType::ResolveUserFieldEntityID($entityTypeId);
		if ($entityId === '')
		{
			return false;
		}

		$result = \CUserTypeEntity::getList([], [
			'ENTITY_ID' => $entityId,
			'FIELD_NAME' => $fieldName,
		]);

		return $result && is_array($result->fetch());
	}

	public static function buildConfig(array $bookingField, ?string $entityFieldName): ?TrustedBookingConfig
	{
		$settingsData = $bookingField['SETTINGS_DATA'] ?? null;
		if (!is_array($settingsData))
		{
			return null;
		}

		$userIds = self::normalizeIdList($settingsData['users']['value'] ?? null);
		$resourceIds = self::normalizeIdList($settingsData['resources']['value'] ?? null);

		return new TrustedBookingConfig($userIds, $resourceIds, $entityFieldName);
	}

	// Normalises an array or pipe-separated string to a deduplicated int[] of positive IDs.
	// Inlined here because this class is the sole consumer; no need for a separate utility class.
	private static function normalizeIdList(mixed $value): array
	{
		if ($value === null || $value === '')
		{
			return [];
		}

		if (is_array($value))
		{
			$raw = $value;
		}
		elseif (is_string($value))
		{
			$raw = explode('|', $value);
		}
		else
		{
			return [];
		}

		$result = [];
		foreach ($raw as $item)
		{
			if (!is_int($item) && !(is_string($item) && preg_match('/^[0-9]+$/D', $item) === 1))
			{
				continue;
			}

			$id = (int)$item;
			if ($id > 0)
			{
				$result[] = $id;
			}
		}

		return array_values(array_unique($result));
	}

}
