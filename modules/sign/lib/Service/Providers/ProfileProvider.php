<?php

namespace Bitrix\Sign\Service\Providers;

use Bitrix\Fileman\UserField\Types\AddressType;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main;
use Bitrix\Main\UserTable;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Factory\Field;
use Bitrix\Sign\Item\B2e\LegalInfoField;
use Bitrix\Sign\Item\B2e\Provider\ProfileFieldData;
use Bitrix\Sign\Service\Cache\Memory\Sign\UserCache;
use Bitrix\Sign\Type\Field\FrontFieldCategory;
use Bitrix\Sign\Type\FieldType;

final class ProfileProvider
{
	public const SOURCE_USER = 1;
	public const SOURCE_UF = 2;

	private ?UserCache $userCache = null;

	private const FIELDS_MAP = [
		'UF_LEGAL_NAME' => 'PROFILE_NAME',
		'UF_LEGAL_LAST_NAME' => 'PROFILE_LAST_NAME',
		'UF_LEGAL_PATRONYMIC_NAME' => 'PROFILE_SECOND_NAME',
		'UF_LEGAL_POSITION' => 'PROFILE_WORK_POSITION',
		'UF_LEGAL_INN' => 'UF_INN',
	];

	private const STORAGE_CONTACT = 'user';
	private const STORAGE_USER_FIELD_PREFIX = 'uf:';

	protected ?Main\Access\AccessibleController $accessController = null;

	public function __construct()
	{
		$this->accessController = null;
	}

	/** @todo Describe all wanted fields from b_user and probably UF */
	private function getContactFieldsMap(): array
	{
		$fields = [
			'PROFILE_NAME' => [
				'type' => FieldType::STRING,
				'caption' => $this->getContactFieldCaptionByName('PROFILE_NAME'),
				'sort' => 100,
				'source' => self::SOURCE_USER,
				'sourceName' => 'NAME',
				'entityId' => 'USER',
				'items' => null,
			],
			'PROFILE_SECOND_NAME' => [
				'type' => FieldType::STRING,
				'caption' => $this->getContactFieldCaptionByName('PROFILE_SECOND_NAME'),
				'sort' => 100,
				'source' => self::SOURCE_USER,
				'sourceName' => 'SECOND_NAME',
				'entityId' => 'USER',
				'items' => null,
			],
			'PROFILE_LAST_NAME' => [
				'type' => FieldType::STRING,
				'caption' => $this->getContactFieldCaptionByName('PROFILE_LAST_NAME'),
				'sort' => 100,
				'source' => self::SOURCE_USER,
				'sourceName' => 'LAST_NAME',
				'entityId' => 'USER',
				'items' => null,
			],
			'PROFILE_EMAIL' => [
				'type' => FieldType::STRING,
				'caption' => $this->getContactFieldCaptionByName('PROFILE_EMAIL'),
				'sort' => 100,
				'source' => self::SOURCE_USER,
				'sourceName' => 'EMAIL',
				'entityId' => 'USER',
				'items' => null,
			],
			'PROFILE_WORK_POSITION' => [
				'type' => FieldType::STRING,
				'caption' => $this->getContactFieldCaptionByName('PROFILE_WORK_POSITION'),
				'sort' => 100,
				'source' => self::SOURCE_USER,
				'sourceName' => 'WORK_POSITION',
				'entityId' => 'USER',
				'items' => null,
			],
			'PROFILE_PERSONAL_BIRTHDAY' => [
				'type' => FieldType::STRING,
				'caption' => $this->getContactFieldCaptionByName('PROFILE_PERSONAL_BIRTHDAY'),
				'sort' => 100,
				'source' => self::SOURCE_USER,
				'sourceName' => 'PERSONAL_BIRTHDAY',
				'entityId' => 'USER',
				'items' => null,
			],
			'PROFILE_PERSONAL_MOBILE' => [
				'type' => FieldType::STRING,
				'caption' => $this->getContactFieldCaptionByName('PROFILE_PERSONAL_MOBILE'),
				'sort' => 100,
				'source' => self::SOURCE_USER,
				'sourceName' => 'PERSONAL_MOBILE',
				'entityId' => 'USER',
				'items' => null,
			],
			'PROFILE_WORK_PHONE' => [
				'type' => FieldType::STRING,
				'caption' => $this->getContactFieldCaptionByName('PROFILE_WORK_PHONE'),
				'sort' => 100,
				'source' => self::SOURCE_USER,
				'sourceName' => 'WORK_PHONE',
				'entityId' => 'USER',
				'items' => null,
			],
		];

		return array_merge($fields, (new ContactInfoProvider())->getFieldsMap());
	}

	private function getSortedFieldsMap(): array
	{
		$contactFields = $this->getContactFieldsMap();
		uasort($contactFields, static fn($a, $b) => ($a['sort'] ?? 100) - ($b['sort'] ?? 100));

		return $contactFields;
	}

	public function setAccessController(\Bitrix\Main\Access\AccessibleController $accessController): self
	{
		$this->accessController = $accessController;

		return $this;
	}

	public function loadFieldData(int $userId, string $fieldName, string $subFieldName = '', bool $originalValue = false): ProfileFieldData
	{
		$result = new ProfileFieldData();
		if (
			$this->accessController !== null
			&& !$this->accessController->check(ActionDictionary::ACTION_B2E_PROFILE_FIELDS_READ)
		)
		{
			return $result;
		}

		$fieldDescription = $this->getDescriptionByFieldName($fieldName);
		if (empty($fieldDescription))
		{
			return $result;
		}

		$fieldSource = $fieldDescription['source'] ?? '';
		$fieldSourceName = $fieldDescription['sourceName'] ?? '';
		if ($fieldSource === self::SOURCE_USER)
		{
			$result->value = $this->getContactFieldValue($userId, $fieldSourceName);
		}
		else if ($fieldSource === self::SOURCE_UF)
		{
			$result->value = Field::getUserFieldValue($userId, $fieldDescription, $subFieldName, $originalValue) ?? '';
			if (empty($result->value))
			{
				$contactFieldName = self::FIELDS_MAP[$fieldDescription['sourceName']] ?? '';
				if (!empty($contactFieldName))
				{
					return $this->loadFieldData($userId, $contactFieldName);
				}
			}

			$result->isLegal = $this->isLegalProfileField($fieldName);
		}

		return $result;
	}

	/**
	 * Which of the asked fields each of the users has a value for. The whole set of the users is read
	 * at once - one query per storage and never one per user, because a document of three hundred
	 * members would otherwise cost three hundred reads of the profile.
	 *
	 * A value is looked for exactly where loadFieldData() looks for it, the fallback of an empty legal
	 * user field to the contact field of the profile included: a caller asking "is it filled" must get
	 * the same answer the filling of a document field gets.
	 *
	 * @param array<mixed> $userIds
	 * @param array<mixed> $fieldNames
	 *
	 * @return array<int, array<string, true>> userId => the asked fields he has a value for
	 */
	public function listFilledFieldNamesByUserIds(array $userIds, array $fieldNames): array
	{
		$userIds = $this->normalizeUserIds($userIds);
		$fieldNames = $this->normalizeFieldNames($fieldNames);
		if ($userIds === [] || $fieldNames === [])
		{
			return [];
		}

		if (
			$this->accessController !== null
			&& !$this->accessController->check(ActionDictionary::ACTION_B2E_PROFILE_FIELDS_READ)
		)
		{
			return [];
		}

		$readChains = [];
		$columnsByStorage = [];
		foreach ($fieldNames as $fieldName)
		{
			$chain = $this->resolveFieldReadChain($fieldName);
			if ($chain === [])
			{
				continue;
			}

			$readChains[$fieldName] = $chain;
			foreach ($chain as [$storage, $column])
			{
				$columnsByStorage[$storage][$column] = $column;
			}
		}

		$valuesByStorage = [];
		foreach ($columnsByStorage as $storage => $columns)
		{
			$valuesByStorage[$storage] = $this->loadColumnValues($storage, array_values($columns), $userIds);
		}

		$filled = [];
		foreach ($readChains as $fieldName => $chain)
		{
			foreach ($chain as [$storage, $column])
			{
				foreach ($userIds as $userId)
				{
					if (($valuesByStorage[$storage][$userId][$column] ?? '') !== '')
					{
						$filled[$userId][$fieldName] = true;
					}
				}
			}
		}

		return $filled;
	}

	/**
	 * The storages a value of the field is looked for in, in the order loadFieldData() looks: the user
	 * field itself first, the contact field of the profile it falls back to next.
	 *
	 * @return list<array{0: string, 1: string}> pairs of a storage key and a column of it
	 */
	private function resolveFieldReadChain(string $fieldName): array
	{
		$description = $this->getDescriptionByFieldName($fieldName);
		$sourceName = (string)($description['sourceName'] ?? '');
		if ($sourceName === '')
		{
			return [];
		}

		$source = $description['source'] ?? null;
		if ($source === self::SOURCE_USER)
		{
			return [[self::STORAGE_CONTACT, $sourceName]];
		}

		if ($source !== self::SOURCE_UF)
		{
			return [];
		}

		$entityId = (string)($description['entityId'] ?? '');
		if ($entityId === '')
		{
			return [];
		}

		$chain = [[self::STORAGE_USER_FIELD_PREFIX . $entityId, $sourceName]];
		$contactFieldName = self::FIELDS_MAP[$sourceName] ?? '';
		if ($contactFieldName !== '')
		{
			foreach ($this->resolveFieldReadChain($contactFieldName) as $fallback)
			{
				$chain[] = $fallback;
			}
		}

		return $chain;
	}

	/**
	 * @param list<string> $columns
	 * @param list<int> $userIds
	 *
	 * @return array<int, array<string, string>>
	 */
	private function loadColumnValues(string $storage, array $columns, array $userIds): array
	{
		if ($storage === self::STORAGE_CONTACT)
		{
			return $this->loadContactColumnValues($columns, $userIds);
		}

		return $this->loadUserFieldColumnValues(
			mb_substr($storage, mb_strlen(self::STORAGE_USER_FIELD_PREFIX)),
			$columns,
			$userIds,
		);
	}

	/**
	 * @param list<string> $columns
	 * @param list<int> $userIds
	 *
	 * @return array<int, array<string, string>>
	 */
	private function loadContactColumnValues(array $columns, array $userIds): array
	{
		$rows = UserTable::query()
			->setSelect(array_values(array_unique(array_merge(['ID'], $columns))))
			->whereIn('ID', $userIds)
			->fetchAll()
		;

		return $this->mapRowsByUserId($rows, 'ID', $columns);
	}

	/**
	 * The single values of the user fields of a whole set of entities. The manager of the user fields
	 * reads one entity per call, and this table is the storage it reads itself.
	 *
	 * @param list<string> $columns
	 * @param list<int> $userIds
	 *
	 * @return array<int, array<string, string>>
	 */
	private function loadUserFieldColumnValues(string $entityId, array $columns, array $userIds): array
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();

		$select = array_map(
			static fn(string $column): string => $sqlHelper->quote($column),
			array_merge(['VALUE_ID'], $columns),
		);

		$sql = (new SqlExpression(
			'SELECT ' . implode(', ', $select) . ' FROM ?# WHERE ?# IN (?@)',
			'b_uts_' . mb_strtolower($entityId),
			'VALUE_ID',
			$userIds,
		))->compile();

		try
		{
			$rows = $connection->query($sql)->fetchAll();
		}
		catch (\Throwable)
		{
			// A portal that declares the user fields but never got their table reads as "nothing is
			// filled": the wizard asks this on the way to the send and must not fatal over it.
			return [];
		}

		return $this->mapRowsByUserId($rows, 'VALUE_ID', $columns);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @param list<string> $columns
	 *
	 * @return array<int, array<string, string>>
	 */
	private function mapRowsByUserId(array $rows, string $userIdColumn, array $columns): array
	{
		$values = [];
		foreach ($rows as $row)
		{
			$userId = (int)($row[$userIdColumn] ?? 0);
			if ($userId < 1)
			{
				continue;
			}

			foreach ($columns as $column)
			{
				$values[$userId][$column] = (string)($row[$column] ?? '');
			}
		}

		return $values;
	}

	/**
	 * @param array<mixed> $userIds
	 *
	 * @return list<int> unique positive identifiers
	 */
	private function normalizeUserIds(array $userIds): array
	{
		$normalized = [];
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0)
			{
				$normalized[$userId] = $userId;
			}
		}

		return array_values($normalized);
	}

	/**
	 * @param array<mixed> $fieldNames
	 *
	 * @return list<string>
	 */
	private function normalizeFieldNames(array $fieldNames): array
	{
		$normalized = [];
		foreach ($fieldNames as $fieldName)
		{
			if (is_string($fieldName) && $fieldName !== '')
			{
				$normalized[$fieldName] = $fieldName;
			}
		}

		return array_values($normalized);
	}

	/**
	 * @return array{PROFILE: array{CAPTION: string, FIELDS: array, MODULE_ID: string}}
	 */
	public function getFieldsForSelector(): array
	{
		return [
			FrontFieldCategory::PROFILE->value => [
				'CAPTION' => Loc::getMessage('SIGN_SERVICE_PROVIDER_PROFILE_CAPTION'),
				'FIELDS' => (new LegalInfoProvider())->getFieldsForSelector(),
				'MODULE_ID' => 'sign',
			]
		];
	}

	public function isProfileField(string $fieldName): bool
	{
		return !empty($this->getDescriptionByFieldName($fieldName));
	}

	public function getDescriptionByFieldName(string $fieldName): array
	{
		$fieldDescription = $this->getContactFieldsMap()[$fieldName] ?? [];

		if (empty($fieldDescription))
		{
			$legalInfoProvider = new LegalInfoProvider();
			$legalUserFields = $legalInfoProvider->getUserFields();
			if (array_key_exists($fieldName, $legalUserFields))
			{
				$fieldDescription = $legalInfoProvider->getFieldDescription($fieldName) ?? [];
			}
		}

		return $fieldDescription;
	}

	private function getContactFieldCaptionByName(string $fieldName): string
	{
		if (!\CModule::IncludeModule('socialnetwork'))
		{
			return '';
		}

		Loc::loadMessages($_SERVER["DOCUMENT_ROOT"] . BX_ROOT . "/modules/socialnetwork/options.php");
		return match ($fieldName) {
			"PROFILE_NAME" => Loc::getMessage("SONET_FIELD_NAME"),
			"PROFILE_SECOND_NAME" => Loc::getMessage("SONET_FIELD_SECOND_NAME"),
			"PROFILE_LAST_NAME" => Loc::getMessage("SONET_FIELD_LAST_NAME"),
			"PROFILE_EMAIL" => Loc::getMessage("SONET_FIELD_EMAIL"),
			"PROFILE_PERSONAL_BIRTHDAY" => Loc::getMessage("SONET_FIELD_PERSONAL_BIRTHDAY"),
			"PROFILE_PERSONAL_PROFESSION" => Loc::getMessage("SONET_FIELD_PERSONAL_PROFESSION"),
			"PROFILE_PERSONAL_WWW" => Loc::getMessage("SONET_FIELD_PERSONAL_WWW"),
			"PROFILE_PERSONAL_ICQ" => Loc::getMessage("SONET_FIELD_PERSONAL_ICQ"),
			"PROFILE_PERSONAL_GENDER" => Loc::getMessage("SONET_FIELD_PERSONAL_GENDER"),
			"PROFILE_PERSONAL_NOTES" => Loc::getMessage("SONET_FIELD_PERSONAL_NOTES"),
			"PROFILE_PERSONAL_PHONE" => Loc::getMessage("SONET_FIELD_PERSONAL_PHONE"),
			"PROFILE_PERSONAL_FAX" => Loc::getMessage("SONET_FIELD_PERSONAL_FAX"),
			"PROFILE_PERSONAL_MOBILE" => Loc::getMessage("SONET_FIELD_PERSONAL_MOBILE"),
			"PROFILE_PERSONAL_PAGER" => Loc::getMessage("SONET_FIELD_PERSONAL_PAGER"),
			"PROFILE_PERSONAL_COUNTRY" => Loc::getMessage("SONET_FIELD_PERSONAL_COUNTRY"),
			"PROFILE_PERSONAL_STATE" => Loc::getMessage("SONET_FIELD_PERSONAL_STATE"),
			"PROFILE_PERSONAL_CITY" => Loc::getMessage("SONET_FIELD_PERSONAL_CITY"),
			"PROFILE_PERSONAL_ZIP" => Loc::getMessage("SONET_FIELD_PERSONAL_ZIP"),
			"PROFILE_PERSONAL_STREET" => Loc::getMessage("SONET_FIELD_PERSONAL_STREET"),
			"PROFILE_PERSONAL_MAILBOX" => Loc::getMessage("SONET_FIELD_PERSONAL_MAILBOX"),
			"PROFILE_WORK_COMPANY" => Loc::getMessage("SONET_FIELD_WORK_COMPANY"),
			"PROFILE_WORK_DEPARTMENT" => Loc::getMessage("SONET_FIELD_WORK_DEPARTMENT"),
			"PROFILE_WORK_POSITION" => Loc::getMessage("SONET_FIELD_WORK_POSITION"),
			"PROFILE_MANAGERS" => Loc::getMessage("SONET_FIELD_MANAGERS"),
			"PROFILE_WORK_WWW" => Loc::getMessage("SONET_FIELD_WORK_WWW"),
			"PROFILE_WORK_PROFILE" => Loc::getMessage("SONET_FIELD_WORK_PROFILE"),
			"PROFILE_WORK_NOTES" => Loc::getMessage("SONET_FIELD_WORK_NOTES"),
			"PROFILE_WORK_PHONE" => Loc::getMessage("SONET_FIELD_WORK_PHONE"),
			"PROFILE_WORK_FAX" => Loc::getMessage("SONET_FIELD_WORK_FAX"),
			"PROFILE_WORK_PAGER" => Loc::getMessage("SONET_FIELD_WORK_PAGER"),
			"PROFILE_WORK_COUNTRY" => Loc::getMessage("SONET_FIELD_WORK_COUNTRY"),
			"PROFILE_WORK_STATE" => Loc::getMessage("SONET_FIELD_WORK_STATE"),
			"PROFILE_WORK_CITY" => Loc::getMessage("SONET_FIELD_WORK_CITY"),
			"PROFILE_WORK_ZIP" => Loc::getMessage("SONET_FIELD_WORK_ZIP"),
			"PROFILE_WORK_STREET" => Loc::getMessage("SONET_FIELD_WORK_STREET"),
			"PROFILE_WORK_MAILBOX" => Loc::getMessage("SONET_FIELD_WORK_MAILBOX"),
			"PROFILE_FULL_NAME" => Loc::getMessage('SIGN_SERVICE_PROVIDER_PROFILE_FIELD_CAPTION_FULL_NAME'),
			default => ''
		};
	}

	private function getContactFieldValue(int $userId, ?string $fieldSourceName): string
	{
		if (empty($fieldSourceName))
		{
			return '';
		}

		$userModel = null;
		if ($this->userCache && in_array($fieldSourceName, $this->userCache->getCachedFields(), true))
		{
			$userModel = $this->userCache->getLoadedModel($userId);
		}
		$userModel ??= UserTable::getById($userId)->fetchObject();

		return (string)($userModel?->get($fieldSourceName) ?? '');
	}

	public function updateFieldData(int $userId, string $fieldName, string|array $value): Main\Result
	{
		global $USER_FIELD_MANAGER;

		if (!$this->isProfileField($fieldName))
		{
			return (new Main\Result())->addError(new Main\Error("Profile field with name: `$fieldName` doesnt exist"));
		}

		$description = $this->getDescriptionByFieldName($fieldName);
		$entityId = $description['entityId'] ?? null;
		if ($entityId === null)
		{
			return (new Main\Result())->addError(new Main\Error('Field has no description'));
		}

		if (
			$description['type'] === FieldType::ADDRESS
			&& Main\Loader::includeModule('fileman')
		)
		{
			$fieldDescription = $this->getDescriptionByFieldName($fieldName);
			$ufValue = $USER_FIELD_MANAGER->GetUserFieldValue(
				entity_id: $fieldDescription['entityId'],
				field_id: $fieldName,
				value_id: $userId,
				LANG: LANGUAGE_ID
			);
			[,, $addressId] = AddressType::parseValue($ufValue);

			$value = $this->prepareUpdateAddressType($value, (int)($addressId ?? 0));
		}

		$isSuccess = $USER_FIELD_MANAGER->Update($entityId, $userId, [$fieldName => $value]);
		if (!$isSuccess)
		{
			return (new Main\Result())->addError(new Main\Error('Field update end not successfully'));
		}

		return new Main\Result();
	}

	public function getFormattedName(?string $firstName, ?string $lastName, ?string $secondName): string
	{
		return str_replace(
			[
				'#NAME#',
				'#LAST_NAME#',
				'#SECOND_NAME#',
				'#NAME_SHORT#',
				'#LAST_NAME_SHORT#',
				'#SECOND_NAME_SHORT#',
			],
			[
				$firstName,
				$lastName,
				$secondName,
				mb_substr($firstName, 0, 1).".",
				mb_substr($lastName, 0, 1).".",
				mb_substr($secondName, 0, 1).".",
			],
			$this->getNameFormat(),
		);
	}

	public function getNameFormat(): string
	{
		$culture = Application::getInstance()->getContext()?->getCulture();

		if ($culture)
		{
			return $culture->getNameFormat();
		}

		return '#NAME# #LAST_NAME#';
	}

	private function prepareUpdateAddressType(array|string $value, int $addressId)
	{
		try
		{
			return Main\Web\Json::encode([
				'id' => $addressId,
				'languageId' => LANGUAGE_ID,
				'latitude' => 0,
				'longitude' => 0,
				'fieldCollection' => $value,
				'links' => [],
				'location' => null,
			], JSON_UNESCAPED_UNICODE);
		}
		catch (\Exception $e)
		{
			return null;
		}
	}

	public function setCache(?UserCache $cache): static
	{
		$this->userCache = $cache;

		return $this;
	}

	private function isLegalProfileField(string $fieldName): bool
	{
		$legalFields = (new LegalInfoProvider())->getFieldsItems();
		$filtered = array_filter($legalFields, static fn(LegalInfoField $field) => $field->name === $fieldName);

		return !empty($filtered);
	}

	public function isFieldCodeUserProfileField(string $fieldCode): bool
	{
		if (!str_starts_with($fieldCode, \Bitrix\Sign\Factory\Field::USER_FIELD_CODE_PREFIX))
		{
			return false;
		}

		$fieldName = $this->getProfileFieldNameByFieldCode($fieldCode);

		return $this->isProfileField($fieldName);
	}

	public function getProfileFieldNameByFieldCode(string $fieldCode): string
	{
		return mb_substr($fieldCode, mb_strlen(\Bitrix\Sign\Factory\Field::USER_FIELD_CODE_PREFIX));
	}
}
