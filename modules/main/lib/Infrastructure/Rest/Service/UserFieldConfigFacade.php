<?php

namespace Bitrix\Main\Infrastructure\Rest\Service;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\Engine\Response\Converter;
use Bitrix\Main\Error;
use Bitrix\Main\Infrastructure\Rest\Exception\InsufficientScopeException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\UserField\UserFieldAccess;
use Bitrix\Main\UserFieldTable;

final class UserFieldConfigFacade
{
	private const ORM_SELECT_FIELDS = [
		'ID',
		'ENTITY_ID',
		'FIELD_NAME',
		'USER_TYPE_ID',
		'XML_ID',
		'SORT',
		'MULTIPLE',
		'MANDATORY',
		'SHOW_FILTER',
		'SHOW_IN_LIST',
		'EDIT_IN_LIST',
		'IS_SEARCHABLE',
		'SETTINGS',
	];

	public function getAccess(string $moduleId, ?array $scopes = null): UserFieldAccess
	{
		$access = UserFieldAccess::getInstance($moduleId);

		if ($scopes !== null && !in_array($moduleId, $scopes, true))
		{
			throw new InsufficientScopeException($moduleId);
		}

		return $access;
	}

	/**
	 * The list keeps one shape whether or not the caller is restricted: the legacy path answered
	 * with the raw type map without restrictions and with a trimmed map under them, so its answer
	 * changed with the rights of the caller.
	 *
	 * @return Result data: array{types: list<array>}
	 */
	public function getTypes(string $moduleId, ?array $scopes = null): Result
	{
		$access = $this->getAccess($moduleId, $scopes);
		$restrictedTypes = array_flip($access->getRestrictedTypes());

		global $USER_FIELD_MANAGER;

		$types = [];
		foreach ($USER_FIELD_MANAGER->GetUserType() as $id => $type)
		{
			if (isset($restrictedTypes[$id]))
			{
				continue;
			}

			$types[] = $type;
		}

		return (new Result())->setData([
			'types' => $types,
		]);
	}

	public function get(string $moduleId, int $id, ?array $scopes = null): Result
	{
		$access = $this->getAccess($moduleId, $scopes);
		if (!$access->canRead($id))
		{
			throw new AccessDeniedException($this->getMessage('MAIN_USER_FIELD_CONTROLLER_ACCESS_VIEW_ERROR'));
		}

		$field = UserFieldTable::getFieldData($id);
		$result = new Result();
		if (is_array($field))
		{
			$result->setData([
				'field' => $this->preparePublicData($field),
			]);
		}

		return $result;
	}

	public function getList(
		string $moduleId,
		array $select = ['*'],
		array $order = [],
		array $filter = [],
		?int $offset = null,
		?int $limit = null,
		?array $scopes = null,
	): Result
	{
		$access = $this->getAccess($moduleId, $scopes);
		$converter = new Converter(Converter::TO_UPPER | Converter::KEYS | Converter::TO_SNAKE);
		$filter = $this->normalizeEntityIdFilterForAccess($this->convertFilterKeys($filter));
		if ($filter instanceof Result)
		{
			return $filter;
		}

		$order = $converter->process($order);
		$rawSelect = $converter->process($select);
		$needsEnrichment = $this->selectNeedsPublicEnrichment($rawSelect);
		$select = $this->normalizeOrmSelect($rawSelect);

		if (!$access->canReadWithFilter($filter))
		{
			throw new AccessDeniedException($this->getMessage('MAIN_USER_FIELD_CONTROLLER_ACCESS_VIEW_ERROR'));
		}

		$filter = $access->prepareFilter($filter);
		$entityIds = $filter['=ENTITY_ID'];
		unset($filter['=ENTITY_ID']);

		$filter = [
			'LOGIC' => 'AND',
			$filter,
			[
				'=ENTITY_ID' => $entityIds,
			],
		];
		$runtime = [];
		if (!empty($select['LANGUAGE']) && is_string($select['LANGUAGE']))
		{
			$runtime[] = UserFieldTable::getLabelsReference('LABELS', $select['LANGUAGE']);
			unset($select['LANGUAGE']);

			$select = array_merge($select, UserFieldTable::getLabelsSelect());
		}

		$fields = [];
		$list = UserFieldTable::getList([
			'select' => $needsEnrichment ? ['ID'] : $select,
			'filter' => $filter,
			'order' => $order,
			'offset' => $offset,
			'limit' => $limit,
			'runtime' => $needsEnrichment ? [] : $runtime,
		]);
		while ($field = $list->fetch())
		{
			if ($needsEnrichment)
			{
				$fullField = UserFieldTable::getFieldData((int)$field['ID']);
				if (!is_array($fullField))
				{
					continue;
				}
				$fields[] = $this->preparePublicData($fullField);
				continue;
			}

			$fields[] = $this->preparePublicData($field);
		}

		return (new Result())->setData([
			'fields' => $fields,
		]);
	}

	public function add(string $moduleId, array $field, ?array $scopes = null): Result
	{
		$access = $this->getAccess($moduleId, $scopes);
		$field = $this->prepareFields($field);
		if (!$access->canAdd($field))
		{
			throw new AccessDeniedException($this->getMessage('MAIN_USER_FIELD_CONTROLLER_ACCESS_CREATE_ERROR'));
		}

		$fieldName = $field['FIELD_NAME'] ?? '';
		$entityId = $field['ENTITY_ID'] ?? '';
		$prefix = 'UF_' . $entityId . '_';
		if (!str_starts_with($fieldName, $prefix))
		{
			return (new Result())->addError(
				new Error($this->getMessage('MAIN_USER_FIELD_CONTROLLER_FIELD_NAME_ERROR')),
			);
		}

		$userTypeEntity = new \CUserTypeEntity();
		$id = $userTypeEntity->Add($field);
		if ($id > 0)
		{
			$result = new Result();
			if (($field['USER_TYPE_ID'] ?? null) === 'enumeration')
			{
				$result->addErrors($this->updateEnums($id, (array)($field['ENUM'] ?? []))->getErrors());
			}

			return $result->setData([
				'field' => $this->preparePublicData(UserFieldTable::getFieldData($id)),
			]);
		}

		return $this->createApplicationErrorResult();
	}

	public function update(string $moduleId, int $id, array $field, ?array $scopes = null): Result
	{
		$access = $this->getAccess($moduleId, $scopes);
		if (!$access->canUpdate($id))
		{
			throw new AccessDeniedException($this->getMessage('MAIN_USER_FIELD_CONTROLLER_ACCESS_MODIFY_ERROR'));
		}

		$field = $this->prepareFields($field);
		$currentField = null;
		if (array_key_exists('ENUM', $field))
		{
			$currentField = UserFieldTable::getFieldData($id);
			if (($currentField['USER_TYPE_ID'] ?? null) !== 'enumeration')
			{
				return (new Result())->addError(
					new Error('enum is allowed only for enumeration user fields.'),
				);
			}
		}

		$userTypeEntity = new \CUserTypeEntity();
		if ($userTypeEntity->Update($id, $field))
		{
			$result = new Result();
			if (array_key_exists('ENUM', $field))
			{
				$currentField ??= UserFieldTable::getFieldData($id);
				$result->addErrors(
					$this
						->updateEnums(
							$id,
							(array)$field['ENUM'],
							(array)($currentField['ENUM'] ?? []),
						)
						->getErrors(),
				);
			}

			return $result->setData([
				'field' => $this->preparePublicData(UserFieldTable::getFieldData($id)),
			]);
		}

		return $this->createApplicationErrorResult();
	}

	public function delete(string $moduleId, int $id, ?array $scopes = null): Result
	{
		$access = $this->getAccess($moduleId, $scopes);
		if (!$access->canDelete($id))
		{
			throw new AccessDeniedException($this->getMessage('MAIN_USER_FIELD_CONTROLLER_ACCESS_DELETE_ERROR'));
		}

		$userTypeEntity = new \CUserTypeEntity();
		$userTypeEntity->Delete($id);

		return new Result();
	}

	public function prepareFields(array $fields): array
	{
		$allowedKeys = [
			'editFormLabel' => true,
			'helpMessage' => true,
			'multiple' => true,
			'userTypeId' => true,
			'fieldName' => true,
			'enum' => true,
			'entityId' => true,
			'xmlId' => true,
			'sort' => true,
			'mandatory' => true,
			'showFilter' => true,
			'isSearchable' => true,
			'settings' => true,
			'editInList' => true,
		];

		$fields = array_intersect_key($fields, $allowedKeys);

		foreach (['multiple', 'mandatory', 'showInList', 'editInList', 'isSearchable'] as $ynField)
		{
			if (array_key_exists($ynField, $fields) && is_bool($fields[$ynField]))
			{
				$fields[$ynField] = $fields[$ynField] ? 'Y' : 'N';
			}
		}

		if (isset($fields['settings']) && is_array($fields['settings']))
		{
			foreach ($fields['settings'] as $settingKey => $settingValue)
			{
				if (is_bool($settingValue))
				{
					$fields['settings'][$settingKey] = $settingValue ? 'Y' : 'N';
				}
			}
		}

		if (($fields['showFilter'] ?? null) === 'Y')
		{
			$fields['showFilter'] = 'E';
		}

		if (isset($fields['helpMessage']) && !is_array($fields['helpMessage']))
		{
			$fields['helpMessage'] = [];
		}

		$fields['helpMessage'] = array_map(
			static fn($tooltip) => mb_substr(trim($tooltip), 0, 255),
			$fields['helpMessage'] ?? [],
		);

		return (new Converter(Converter::TO_UPPER | Converter::KEYS | Converter::TO_SNAKE))->process($fields);
	}

	public function preparePublicData(array $field): array
	{
		foreach (UserFieldTable::getLabelFields() as $labelName)
		{
			if (isset($field[$labelName]) && !is_array($field[$labelName]))
			{
				$field[$labelName] = [
					Loc::getCurrentLang() => $field[$labelName],
				];
			}
		}

		$settings = $field['SETTINGS'] ?? null;
		$field = (
			new Converter(
				Converter::KEYS
				| Converter::TO_CAMEL
				| Converter::LC_FIRST
				| Converter::RECURSIVE,
			)
		)->process($field);
		$field['settings'] = $settings;

		return $field;
	}

	private function prepareEnums(array $newEnums, array $currentEnums): array
	{
		$deletedEnum = [];
		$storedEnum = [];
		$updatedEnum = [];

		foreach ($currentEnums as $enumItem)
		{
			$storedEnum[$enumItem['ID']] = $enumItem;
			$deletedEnum[$enumItem['ID']] = true;
		}

		$countAdded = 0;
		foreach ($newEnums as $enumItem)
		{
			if (!is_array($enumItem))
			{
				continue;
			}

			if (!empty($enumItem['id']))
			{
				if (empty($enumItem['xmlId']))
				{
					$enumItem['xmlId'] = $storedEnum[$enumItem['id']]['XML_ID'];
				}
				if (empty($enumItem['def']))
				{
					$enumItem['def'] = $storedEnum[$enumItem['id']]['DEF'];
				}

				unset($deletedEnum[$enumItem['id']]);
			}

			$itemKey = ($enumItem['id'] > 0 ? $enumItem['id'] : 'n' . ($countAdded++));
			$def = $enumItem['def'] ?? null;
			if (is_bool($def))
			{
				$def = $def ? 'Y' : 'N';
			}

			$itemDescription = [
				'VALUE' => $enumItem['value'],
				'DEF' => $def === 'Y' ? 'Y' : 'N',
				'SORT' => $enumItem['sort'],
			];

			if (!empty($enumItem['xmlId']))
			{
				$itemDescription['XML_ID'] = $enumItem['xmlId'];
			}

			$enumItem['sort'] = (int)$enumItem['sort'];
			if ($enumItem['sort'] > 0)
			{
				$itemDescription['SORT'] = $enumItem['sort'];
			}

			$updatedEnum[$itemKey] = $itemDescription;
		}

		foreach ($deletedEnum as $deletedId => $unused)
		{
			$updatedEnum[$deletedId] = [
				'ID' => $deletedId,
				'DEL' => 'Y',
			];
		}

		return $updatedEnum;
	}

	private function updateEnums(int $id, array $enums, array $currentEnums = []): Result
	{
		$enumValuesManager = new \CUserFieldEnum();
		if (!$enumValuesManager->setEnumValues($id, $this->prepareEnums($enums, $currentEnums)))
		{
			return $this->createApplicationErrorResult(false);
		}

		return new Result();
	}

	private function createApplicationErrorResult(bool $addCommonError = true): Result
	{
		$result = new Result();
		global $APPLICATION;

		$exception = $APPLICATION->GetException();
		if (($exception instanceof \CAdminException) && is_array($exception->messages))
		{
			foreach ($exception->messages as $message)
			{
				$result->addError(new Error($message['text'] ?? $message));
			}
		}

		$APPLICATION->ResetException();
		if ($addCommonError && $result->isSuccess())
		{
			$result->addError(new Error($this->getMessage('MAIN_USER_FIELD_CONTROLLER_ERROR')));
		}

		return $result;
	}

	private function convertFilterKeys(array $filter): array
	{
		$result = [];
		$converter = new Converter(Converter::TO_UPPER | Converter::TO_SNAKE);

		foreach ($filter as $key => $value)
		{
			if ($key === 'LOGIC')
			{
				$result['LOGIC'] = is_string($value) ? strtoupper($value) : $value;
				continue;
			}

			if (is_int($key))
			{
				$result[$key] = is_array($value) ? $this->convertFilterKeys($value) : $value;
				continue;
			}

			$operator = '';
			$field = (string)$key;
			if (preg_match('/^([=!<>@%*]+)(.+)$/', $field, $matches))
			{
				$operator = $matches[1];
				$field = $matches[2];
			}

			// Avoid double-convert: LegacyQueryConverter already emits UPPER_SNAKE (`ID`).
			// Applying TO_SNAKE again turns `ID` into broken `I_D`.
			if (preg_match('/[a-z]/', $field))
			{
				$field = $converter->process($field);
			}
			else
			{
				$field = strtoupper($field);
			}

			$result[$operator . $field] = $value;
		}

		return $result;
	}

	/**
	 * UserFieldAccess::prepareFilter understands bare ENTITY_ID only.
	 * Only equality / IN are accepted for entityId.
	 *
	 * @param array<string, mixed> $filter
	 * @return array<string, mixed>|Result
	 */
	private function normalizeEntityIdFilterForAccess(array $filter): array|Result
	{
		$result = [];
		foreach ($filter as $key => $value)
		{
			if ($key === 'LOGIC')
			{
				$result['LOGIC'] = $value;
				continue;
			}

			if (is_int($key) && is_array($value))
			{
				$nested = $this->normalizeEntityIdFilterForAccess($value);
				if ($nested instanceof Result)
				{
					return $nested;
				}
				$result[$key] = $nested;
				continue;
			}

			if (is_string($key) && ltrim($key, '=!<>@%*') === 'ENTITY_ID')
			{
				$operator = substr($key, 0, strlen($key) - strlen('ENTITY_ID'));
				if (!in_array($operator, ['', '=', '@'], true))
				{
					return (new Result())->addError(
						new Error('Only "=" and "in" operators are allowed for entityId.', 'filter'),
					);
				}

				$result['ENTITY_ID'] = $value;
				continue;
			}

			$result[$key] = $value;
		}

		return $result;
	}

	/**
	 * @param array<string, mixed>|list<string> $select
	 */
	private function selectNeedsPublicEnrichment(array $select): bool
	{
		if ($select === [] || $select === ['*'] || in_array('*', $select, true))
		{
			return false;
		}

		$computed = [
			'EDIT_FORM_LABEL' => true,
			'LIST_COLUMN_LABEL' => true,
			'LIST_FILTER_LABEL' => true,
			'ERROR_MESSAGE' => true,
			'HELP_MESSAGE' => true,
			'ENUM' => true,
			'LANGUAGE' => true,
			'LANGUAGE_ID' => true,
		];

		foreach ($select as $key => $value)
		{
			$field = is_int($key) ? (string)$value : (string)$key;
			if (isset($computed[strtoupper($field)]))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed>|list<string> $select
	 * @return array<string, mixed>|list<string>
	 */
	private function normalizeOrmSelect(array $select): array
	{
		if ($select === [] || $select === ['*'] || in_array('*', $select, true))
		{
			return ['*'];
		}

		$language = null;
		if (!empty($select['LANGUAGE']) && is_string($select['LANGUAGE']))
		{
			$language = $select['LANGUAGE'];
		}

		$allowed = array_fill_keys(self::ORM_SELECT_FIELDS, true);
		$result = [];
		foreach ($select as $key => $value)
		{
			if ($key === 'LANGUAGE')
			{
				continue;
			}

			$field = is_int($key) ? (string)$value : (string)$key;
			$field = strtoupper($field);
			if (isset($allowed[$field]))
			{
				$result[] = $field;
			}
		}

		if ($result === [])
		{
			$result = ['*'];
		}

		if ($language !== null)
		{
			$result['LANGUAGE'] = $language;
		}

		return $result;
	}

	private function getMessage(string $code): string
	{
		Loc::loadLanguageFile(dirname(__DIR__, 3) . '/controller/userfieldconfig.php');

		return Loc::getMessage($code);
	}
}
