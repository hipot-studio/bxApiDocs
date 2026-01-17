<?php

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Filter\DateType;
use Bitrix\Main\UI\Filter\FieldAdapter;
use Bitrix\Main\UI\Filter\Theme;
use Bitrix\Main\UI\Filter\Type;

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

Loc::loadMessages(__FILE__);

class CMainUiFilter extends CBitrixComponent
{
	protected $defaultViewSort = 500;
	protected $options;
	protected $themesFolder = "/themes/";
	protected $configName = "config.json";
	protected $theme;
	protected $defaultHeaderSectionId = '';
	protected ?array $presets = null;
	protected ?array $sourcePresets = null;
	protected ?array $defaultPresets = null;
	protected ?array $commonPresets = null;
	protected ?array $currentPreset = null;
	protected ?array $filterRows = null;
	protected ?array $commonOptions = null;

	protected static string $DEFAULT_PRESET_ID = "default_filter";
	protected static string $TMP_PRESET_ID = "tmp_filter";

	protected function prepareResult(): void
	{
		$this->arResult["FILTER_ID"] = $this->arParams["FILTER_ID"] ?? '';
		$this->arResult["GRID_ID"] = $this->arParams["GRID_ID"] ?? '';
		$this->arResult["FIELDS"] = $this->prepareFields();
		$this->arResult["PRESETS"] = array_values($this->getPresets());
		$this->arResult["DEFAULT_PRESETS"] = array_values($this->getDefaultPresets());
		$this->arResult["TARGET_VIEW_ID"] = $this->getViewId();
		$this->arResult["TARGET_VIEW_SORT"] = $this->getViewSort();
		$this->arResult["SETTINGS_URL"] = $this->prepareSettingsUrl();
		$this->arResult["FILTER_ROWS"] = $this->getFilterRows();
		$this->arResult["CURRENT_PRESET"] = $this->getCurrentPreset();
		$this->arResult["ENABLE_LABEL"] = $this->prepareEnableLabel();
		$this->arResult["ENABLE_LIVE_SEARCH"] = $this->prepareEnableLiveSearch();
		$this->arResult["DISABLE_SEARCH"] = $this->prepareDisableSearch();
		$this->arResult["COMPACT_STATE"] = $this->prepareCompactState();
		$this->arResult["LIMITS_ENABLED"] = $this->prepareLimits();
		if ($this->arResult["LIMITS_ENABLED"])
		{
			$this->arResult["DISABLE_SEARCH"] = true;
		}

		$this->arResult["MAIN_UI_FILTER__AND"] = Loc::getMessage('MAIN_UI_FILTER__AND');
		$this->arResult["MAIN_UI_FILTER__MORE"] = Loc::getMessage('MAIN_UI_FILTER__MORE');
		$this->arResult["MAIN_UI_FILTER__BEFORE"] = Loc::getMessage("MAIN_UI_FILTER__BEFORE");
		$this->arResult["MAIN_UI_FILTER__AFTER"] = Loc::getMessage("MAIN_UI_FILTER__AFTER");
		$this->arResult["MAIN_UI_FILTER__NUMBER_MORE"] = Loc::getMessage("MAIN_UI_FILTER__NUMBER_MORE");
		$this->arResult["MAIN_UI_FILTER__NUMBER_LESS"] = Loc::getMessage("MAIN_UI_FILTER__NUMBER_LESS");
		$this->arResult["MAIN_UI_FILTER__PLACEHOLDER_DEFAULT"] = Loc::getMessage("MAIN_UI_FILTER__PLACEHOLDER_DEFAULT");
		$this->arResult["MAIN_UI_FILTER__PLACEHOLDER_WITH_FILTER"] = Loc::getMessage("MAIN_UI_FILTER__PLACEHOLDER_WITH_FILTER");
		$this->arResult["MAIN_UI_FILTER__PLACEHOLDER"] = Loc::getMessage("MAIN_UI_FILTER__PLACEHOLDER");
		$this->arResult["MAIN_UI_FILTER__PLACEHOLDER_LIMITS_EXCEEDED"] = Loc::getMessage("MAIN_UI_FILTER__PLACEHOLDER_LIMITS_EXCEEDED");
		$this->arResult["MAIN_UI_FILTER__QUARTER"] = Loc::getMessage("MAIN_UI_FILTER__QUARTER");
		$this->arResult["MAIN_UI_FILTER__IS_SET_AS_DEFAULT_PRESET"] = Loc::getMessage("MAIN_UI_FILTER__IS_SET_AS_DEFAULT_PRESET");
		$this->arResult["MAIN_UI_FILTER__SET_AS_DEFAULT_PRESET"] = Loc::getMessage("MAIN_UI_FILTER__SET_AS_DEFAULT_PRESET");
		$this->arResult["MAIN_UI_FILTER__EDIT_PRESET_TITLE"] = Loc::getMessage("MAIN_UI_FILTER__EDIT_PRESET_TITLE");
		$this->arResult["MAIN_UI_FILTER__REMOVE_PRESET"] = Loc::getMessage("MAIN_UI_FILTER__REMOVE_PRESET");
		$this->arResult["MAIN_UI_FILTER__DRAG_TITLE"] = Loc::getMessage("MAIN_UI_FILTER__DRAG_TITLE");
		$this->arResult["MAIN_UI_FILTER__DRAG_FIELD_TITLE"] = Loc::getMessage("MAIN_UI_FILTER__DRAG_FIELD_TITLE");
		$this->arResult["MAIN_UI_FILTER__REMOVE_FIELD"] = Loc::getMessage("MAIN_UI_FILTER__REMOVE_FIELD");
		$this->arResult["MAIN_UI_FILTER__CONFIRM_MESSAGE_FOR_ALL"] = Loc::getMessage("MAIN_UI_FILTER__CONFIRM_MESSAGE_FOR_ALL");
		$this->arResult["MAIN_UI_FILTER__CONFIRM_APPLY_FOR_ALL"] = Loc::getMessage("MAIN_UI_FILTER__CONFIRM_APPLY_FOR_ALL");
		$this->arResult["MAIN_UI_FILTER__DATE_NEXT_DAYS_LABEL"] = Loc::getMessage("MAIN_UI_FILTER__DATE_NEXT_DAYS_LABEL");
		$this->arResult["MAIN_UI_FILTER__DATE_PREV_DAYS_LABEL"] = Loc::getMessage("MAIN_UI_FILTER__DATE_PREV_DAYS_LABEL");
		$this->arResult["MAIN_UI_FILTER__DATE_ERROR_TITLE"] = Loc::getMessage("MAIN_UI_FILTER__DATE_ERROR_TITLE");
		$this->arResult["MAIN_UI_FILTER__DATE_ERROR_LABEL"] = Loc::getMessage("MAIN_UI_FILTER__DATE_ERROR_LABEL");
		$this->arResult["MAIN_UI_FILTER__VALUE_REQUIRED"] = Loc::getMessage("MAIN_UI_FILTER__VALUE_REQUIRED");
		$this->arResult["CLEAR_GET"] = $this->prepareClearGet();
		$this->arResult["VALUE_REQUIRED_MODE"] = $this->prepareValueRequiredMode();
		$this->arResult["THEME"] = $this->getTheme();
		$this->arResult["RESET_TO_DEFAULT_MODE"] = $this->prepareResetToDefaultMode();
		$this->arResult["COMMON_PRESETS_ID"] = $this->arParams["COMMON_PRESETS_ID"] ?? null;
		$this->arResult["IS_AUTHORIZED"] = $this->getUser()->isAuthorized();
		$this->arResult["LAZY_LOAD"] = $this->arParams["LAZY_LOAD"] ?? null;
		$this->arResult["VALUE_REQUIRED"] = $this->arParams["VALUE_REQUIRED"] ?? null;
		$this->arResult["FIELDS_STUBS"] = static::getFieldsStubs();
		$this->arResult["INITIAL_FILTER"] = $this->getFilter();
		$this->arResult["USE_CHECKBOX_LIST_FOR_SETTINGS_POPUP"] = (bool)($this->arParams["USE_CHECKBOX_LIST_FOR_SETTINGS_POPUP"] ?? false);
		$this->arResult["RESTRICTED_FIELDS"] = ($this->arParams["RESTRICTED_FIELDS"] ?? []);
		$this->arResult["ENABLE_ADDITIONAL_FILTERS"] = $this->arParams["ENABLE_ADDITIONAL_FILTERS"] ?? null;
		$this->arResult['ENABLE_FIELDS_SEARCH'] = (
			isset($this->arParams['ENABLE_FIELDS_SEARCH'])
			&& $this->arParams['ENABLE_FIELDS_SEARCH'] === 'Y'
		);
		if (
			!empty($this->arParams['HEADERS_SECTIONS'])
			&& is_array($this->arParams['HEADERS_SECTIONS'])
		)
		{
			$this->prepareHeaderSections();
			$this->arResult['FIELDS_WITH_SECTIONS'] = $this->getFieldsAllWithSections($this->arResult['FIELDS']);
		}

		if (isset($this->arParams["MESSAGES"]) && is_array($this->arParams["MESSAGES"]))
		{
			foreach ($this->arParams["MESSAGES"] as $key => $message)
			{
				if (
					str_contains($key, "MAIN_UI_FILTER__")
					&& isset($this->arResult[$key])
				)
				{
					$this->arResult[$key] = $message;
				}
			}
		}
	}

	protected function prepareHeaderSections(): void
	{
		foreach($this->arParams['HEADERS_SECTIONS'] as $section)
		{
			$this->arResult['HEADERS_SECTIONS'][$section['id']] = $section;
			if (!empty($section['default']))
			{
				$this->defaultHeaderSectionId = $section['id'];
			}
		}
	}

	protected function getFieldsAllWithSections(array $fields): array
	{
		$result = [];
		foreach($fields as $field)
		{
			if (!empty($field['SECTION_ID']))
			{
				$result[$field['SECTION_ID']][] = $field;
			}
			else
			{
				$result[$this->defaultHeaderSectionId][] = $field;
			}
		}

		return $result;
	}

	protected function prepareResetToDefaultMode()
	{
		$result = true;

		if (isset($this->arParams["RESET_TO_DEFAULT_MODE"]) && is_bool($this->arParams["RESET_TO_DEFAULT_MODE"]))
		{
			$result = $this->arParams["RESET_TO_DEFAULT_MODE"];
		}

		return $result;
	}

	protected function prepareValueRequiredMode()
	{
		return isset($this->arParams["VALUE_REQUIRED_MODE"]) && $this->arParams["VALUE_REQUIRED_MODE"] == true;
	}

	protected function prepareClearGet()
	{
		$apply = $this->request->get("apply_filter");
		return !empty($apply);
	}

	protected function prepareDisableSearch()
	{
		$result = false;

		if (isset($this->arParams["DISABLE_SEARCH"]) && $this->arParams["DISABLE_SEARCH"])
		{
			$result = true;
		}

		return $result;
	}

	protected function prepareEnableLiveSearch()
	{
		$this->arResult["ENABLE_LIVE_SEARCH"] = false;

		if (isset($this->arParams["ENABLE_LIVE_SEARCH"]) && is_bool($this->arParams["ENABLE_LIVE_SEARCH"]))
		{
			$this->arResult["ENABLE_LIVE_SEARCH"] = $this->arParams["ENABLE_LIVE_SEARCH"];
		}

		return $this->arResult["ENABLE_LIVE_SEARCH"];
	}

	protected function prepareCompactState()
	{
		$result = false;

		if (isset($this->arParams["COMPACT_STATE"]) && $this->arParams["COMPACT_STATE"])
		{
			$result = true;
		}

		return $result;
	}

	protected function prepareEnableLabel()
	{
		$this->arResult["ENABLE_LABEL"] = false;

		if (isset($this->arParams["ENABLE_LABEL"]) && $this->arParams["ENABLE_LABEL"] === true)
		{
			$this->arResult["ENABLE_LABEL"] = true;
		}

		return $this->arResult["ENABLE_LABEL"];
	}

	protected function prepareLimits()
	{
		$this->arResult["LIMITS"] = [
			"TITLE" => "",
			"DESCRIPTION" => "",
			"BUTTONS" => []
		];

		if (isset($this->arParams["LIMITS"]) && is_array($this->arParams["LIMITS"]))
		{
			if (isset($this->arParams["LIMITS"]["TITLE"]) && !empty($this->arParams["LIMITS"]["TITLE"]))
			{
				$this->arResult["LIMITS"]["TITLE"] = htmlspecialcharsback($this->arParams["LIMITS"]["TITLE"]);
			}

			if (isset($this->arParams["LIMITS"]["DESCRIPTION"]) && !empty($this->arParams["LIMITS"]["DESCRIPTION"]))
			{
				$this->arResult["LIMITS"]["DESCRIPTION"] = htmlspecialcharsback($this->arParams["LIMITS"]["DESCRIPTION"]);
			}

			if (
				isset($this->arParams["LIMITS"]["BUTTONS"]) &&
				is_array($this->arParams["LIMITS"]["BUTTONS"]) &&
				Loader::includeModule("ui")
			)
			{
				foreach ($this->arParams["LIMITS"]["BUTTONS"] as $button)
				{
					if (is_string($button) && !empty($button))
					{
						$this->arResult["LIMITS"]["BUTTONS"][] = htmlspecialcharsback($button);
					}
				}
			}
		}

		return !empty($this->arResult["LIMITS"]["TITLE"]);
	}

	protected function getUserOptions()
	{
		if (!($this->options instanceof \Bitrix\Main\UI\Filter\Options))
		{
			$this->options = new \Bitrix\Main\UI\Filter\Options(
				$this->arParams["FILTER_ID"],
				$this->arParams["FILTER_PRESETS"],
				$this->arParams["COMMON_PRESETS_ID"] ?? null
			);
		}

		return $this->options;
	}

	public function getFilter()
	{
		return $this->getUserOptions()->getFilter($this->arParams["FILTER"] ?? []);
	}

	protected static function prepareSelectValue(array $items = array(), $value = "", $strictMode = false)
	{
		foreach ($items as $item)
		{
			if (
				(!$strictMode && $item["VALUE"] == $value) ||
				($strictMode && $item["VALUE"] === $value)
			)
			{
				return $item;
			}
		}

		return array();
	}

	protected static function prepareMultiselectValue(array $items = array(), array $value = array(), $isStrict = false)
	{
		$result = array();
		$values = array_values($value);

		foreach ($items as $item)
		{
			if (in_array($item["VALUE"], $values, $isStrict))
			{
				$result[] = $item;
			}
		}

		return $result;
	}

	protected static function prepareValue(array $field, array $presetFields, $prefix)
	{
		$fieldValuesKeys = array_keys($field["VALUES"]);
		$fieldName = mb_strpos($field["NAME"], $prefix) !== false ? str_replace($prefix, "", $field["NAME"]) : $field["NAME"];
		$result = array();

		foreach ($fieldValuesKeys as $keyName)
		{
			$currentFieldName = $fieldName.$keyName;
			$result[$keyName] = "";

			if (array_key_exists($currentFieldName, $presetFields))
			{
				$result[$keyName] = $presetFields[$currentFieldName];
			}
		}

		return $result;
	}

	protected static function prepareSubtype(array $field, array $presetFields, $prefix)
	{
		$subTypes = $field["SUB_TYPES"];
		$dateselName = mb_strpos($field["NAME"], $prefix) === false ? $field["NAME"].$prefix : $field["NAME"];
		$result = $subTypes[0];

		if (array_key_exists($dateselName, $presetFields))
		{
			foreach ($subTypes as $subType)
			{
				if ($subType["VALUE"] === $presetFields[$dateselName])
				{
					$result = $subType;
				}
			}
		}

		return $result;
	}

	protected static function extractValueFromPreset(array $field, array $presetFields = []): array
	{
		$fieldName = $field["NAME"];
		$fieldNameLabel = $fieldName . "_label";
		$fieldNameLabelAlias = $fieldName . "_name";
		$fieldNameValue = $fieldName . "_value";
		$result = [
			"_label" => "",
			"_value" => "",
		];

		if (array_key_exists($fieldName, $presetFields))
		{
			$result["_value"] = $presetFields[$fieldName];

		}

		if (empty($result["_value"]) && array_key_exists($fieldNameValue, $presetFields))
		{
			$result["_value"] = $presetFields[$fieldNameValue];

		}

		if (array_key_exists($fieldNameLabel, $presetFields))
		{
			$result["_label"] = $presetFields[$fieldNameLabel];
		}

		if (empty($result["_label"]) && array_key_exists($fieldNameLabelAlias, $presetFields))
		{
			$result["_label"] = $presetFields[$fieldNameLabelAlias];
		}

		return $result;
	}

	protected static function prepareCustomEntityValue(array $field, array $presetFields = [])
	{
		$result = self::extractValueFromPreset($field, $presetFields);

		return self::replaceEmptyLabelsWithIds($result);
	}

	protected static function replaceEmptyLabelsWithIds(array $result): array
	{
		if (!empty($result['_value']) && empty($result['_label']))
		{
			$result['_label'] = '#' . $result['_value'];
		}

		return $result;
	}

	protected static function prepareCustomValue(array $field, array $presetFields = array())
	{
		return array_key_exists($field["NAME"], $presetFields) ? $presetFields[$field["NAME"]] : "";
	}

	protected static function prepareDestSelectorValue(array $field, array $presetFields = array(), array $params = array())
	{
		$result = self::extractValueFromPreset($field, $presetFields);

		if (!empty($result["_value"]) && empty($result["_label"]))
		{
			$fieldData = [];
			foreach($params as $paramsItem)
			{
				if (
					!empty($paramsItem['id'])
					&& $paramsItem['id'] == $field['NAME']
				)
				{
					$fieldData = $paramsItem;
					break;
				}
			}

			$value = (
				!empty($fieldData)
				&& !empty($fieldData['params'])
				&& !empty($fieldData['params']['isNumeric'])
				&& $fieldData['params']['isNumeric'] == 'Y'
				&& !empty($fieldData['params']['prefix'])
					? $fieldData['params']['prefix'].$result["_value"]
					: $result["_value"]
			);

			if (is_array($value))
			{
				$result["_label"] = [];

				foreach($value as $val)
				{
					$entityType = Bitrix\Main\UI\Selector\Entities::getEntityType(array(
						'itemCode' => $val
					));
					if (!empty($entityType))
					{
						if ($entityType == 'department')
						{
							$entityType = 'departments';
						}

						$provider = \Bitrix\Main\UI\Selector\Entities::getProviderByEntityType(mb_strtoupper($entityType));
						if ($provider !== false)
						{
							$result["_label"][] = $provider->getItemName($val);
						}
					}
				}
			}
			else
			{
				$entityType = Bitrix\Main\UI\Selector\Entities::getEntityType(array(
					'itemCode' => $value
				));

				if (!empty($entityType))
				{
					if ($entityType == 'department')
					{
						$entityType = 'departments';
					}

					$provider = \Bitrix\Main\UI\Selector\Entities::getProviderByEntityType(mb_strtoupper($entityType));
					if ($provider !== false)
					{
						$result["_label"] = $provider->getItemName($value);
					}
				}
			}
		}

		return self::replaceEmptyLabelsWithIds($result);
	}

	protected static function prepareEntitySelectorValue(array $field, array $presetFields = [])
	{
		$result = self::extractValueFromPreset($field, $presetFields);
		if (!empty($result['_label']))
		{
			return $result;
		}
		$values = $result['_value'];
		if (empty($values))
		{
			return self::replaceEmptyLabelsWithIds($result);
		}

		$fieldAdapter = new \Bitrix\Main\Filter\FieldAdapter\EntitySelectorFieldAdapter($field);
		$result['_label'] =
			$field['MULTIPLE']
				? $fieldAdapter->getLabels((array)$values)
				: $fieldAdapter->getLabel((string)$values);

		return self::replaceEmptyLabelsWithIds($result);
	}

	protected static function compatibleDateselValue($value = "")
	{
		$dateMap = array(
			"" => DateType::NONE,
			"today" => DateType::CURRENT_DAY,
			"yesterday" => DateType::YESTERDAY,
			"tomorrow" => DateType::TOMORROW,
			"week_ago" => DateType::LAST_WEEK,
			"month" => DateType::MONTH,
			"month_ago" => DateType::LAST_MONTH,
			"exact" => DateType::EXACT,
			"after" => DateType::RANGE,
			"before" => DateType::RANGE,
			"interval" => DateType::RANGE
		);

		return array_key_exists($value, $dateMap) ? $dateMap[$value] : $value;
	}

	protected static function fetchAdditionalFilter($name, $fields)
	{
		if (is_string($name) && is_array($fields))
		{
			if (array_key_exists("{$name}_isEmpty", $fields))
			{
				return 'isEmpty';
			}

			if (array_key_exists("{$name}_hasAnyValue", $fields))
			{
				return 'hasAnyValue';
			}
		}

		return null;
	}

	protected function preparePresetFields($presetRows = array(), $presetFields = array())
	{
		$result = array();

		if (is_array($presetRows) && is_array($presetFields))
		{
			foreach ($presetRows as $rowName)
			{
				$field = $this->getField($rowName);

				if (empty($field))
				{
					continue;
				}

				$value = array_key_exists($rowName, $presetFields) ? $presetFields[$rowName] : "";
				$field['ADDITIONAL_FILTER'] = static::fetchAdditionalFilter($rowName, $presetFields);
				if ($field['ADDITIONAL_FILTER'] === null)
				{
					switch ($field["TYPE"])
					{
						case Type::SELECT :
						{
							if (!empty($value) && is_array($value))
							{
								$values = array_values($value);
								$value = $values[0];
							}

							$field["VALUE"] = self::prepareSelectValue($field["ITEMS"], $value, $field["STRICT"]);
							break;
						}

						case Type::MULTI_SELECT :
						{
							if ($value !== "")
							{
								$value = is_array($value) ? $value : [$value];
								$field["VALUE"] = self::prepareMultiselectValue($field["ITEMS"], $value, $field['STRICT']);
							}
							break;
						}

						case Type::DATE :
						{
							$presetFields[$field["NAME"]."_datesel"] = self::compatibleDateselValue(
								$presetFields[$field["NAME"]."_datesel"] ?? ''
							);
							$field["SUB_TYPE"] = self::prepareSubtype($field, $presetFields, "_datesel");
							$field["VALUES"] = self::prepareValue($field, $presetFields, "_datesel");

							if (is_array($field["YEARS_SWITCHER"]))
							{
								$field["YEARS_SWITCHER"]["VALUE"] = self::prepareSelectValue(
									$field["YEARS_SWITCHER"]["ITEMS"],
									$presetFields[$field["NAME"]."_allow_year"] ?? null,
									$field["STRICT"]
								);
							}
							break;
						}

						case Type::CUSTOM_DATE :
						{
							$days = array();

							if (isset($presetFields[$field["NAME"]."_days"]) && is_array($presetFields[$field["NAME"]."_days"]))
							{
								$days = $presetFields[$field["NAME"]."_days"];
							}

							$months = array();

							if (isset($presetFields[$field["NAME"]."_months"]) && is_array($presetFields[$field["NAME"]."_months"]))
							{
								$months = $presetFields[$field["NAME"]."_months"];
							}

							$years = array();

							if (isset($presetFields[$field["NAME"]."_years"]) && is_array($presetFields[$field["NAME"]."_years"]))
							{
								$years = $presetFields[$field["NAME"]."_years"];
							}

							$field["VALUE"] = array(
								"days" => $days,
								"months" => $months,
								"years" => $years
							);
							break;
						}

						case Type::NUMBER :
						{
							$field["SUB_TYPE"] = self::prepareSubtype($field, $presetFields, "_numsel");
							$field["VALUES"] = self::prepareValue($field, $presetFields, "_numsel");
							break;
						}

						case Type::CUSTOM_ENTITY :
						{
							$field["VALUES"] = self::prepareCustomEntityValue($field, $presetFields);
							break;
						}

						case Type::CUSTOM :
						{
							$field["_VALUE"] = self::prepareCustomValue($field, $presetFields);
							break;
						}

						case Type::ENTITY_SELECTOR:
					{
						$field["VALUES"] = self::prepareEntitySelectorValue($field, $presetFields);
						break;
					}

					case Type::DEST_SELECTOR :
						{
							$field["VALUES"] = self::prepareDestSelectorValue($field, $presetFields, $this->arParams['FILTER']);
							break;
						}

						case Type::STRING :
						case Type::TEXTAREA :
						{
							$field["VALUE"] = $value;
							break;
						}
					}
				}

				$result[] = $field;
			}
		}

		return $result;
	}

	protected function getAdditionalPresetFields($presetId): array
	{
		$userOptions = $this->getUserOptions();
		$additionalFields = $userOptions->getAdditionalPresetFields($presetId);

		if (is_array($additionalFields))
		{
			$additionalRows = \Bitrix\Main\UI\Filter\Options::getRowsFromFields($additionalFields);

			return $this->preparePresetFields($additionalRows, $additionalFields);
		}

		return [];
	}

	protected function isDeletedPreset($presetId): bool
	{
		$userOptions = $this->getUserOptions();
		$commonOptions = $this->getCommonOptions();
		$deletedCommonPresets = $commonOptions['deleted_presets'] ?? [];

		return (
			$userOptions->isDeletedPreset($presetId)
			|| (
				!$this->getUser()->canDoOperation("edit_other_settings")
				&& array_key_exists($presetId, $deletedCommonPresets)
			)
		);
	}

	protected function getPresets(): array
	{
		if ($this->presets === null)
		{
			$this->presets = [];
			$userOptions = $this->getUserOptions();

			$userPresets = $userOptions->getOptions()["filters"] ?? null;
			if (is_array($userPresets))
			{
				foreach ($userPresets as $presetId => $preset)
				{
					if (!$this->isDeletedPreset($presetId))
					{
						$this->presets[$presetId] = array_merge(
							$this->preparePreset($presetId, $preset, (int)($preset["sort"] ?? 0)),
							[
								"ADDITIONAL" => $this->getAdditionalPresetFields($presetId),
								"IS_PINNED" => $userOptions->isPinned($presetId),
							],
						);
					}
				}

				if (!$this->getUser()->canDoOperation("edit_other_settings"))
				{
					$commonPresets = $this->getCommonPresets();
					if (is_array($commonPresets))
					{
						$this->presets = array_merge($this->presets, $commonPresets);
					}
				}

				if (!array_key_exists(static::$DEFAULT_PRESET_ID, $userPresets))
				{
					$this->presets[static::$DEFAULT_PRESET_ID] = $this->createDefaultPreset();
				}
			}
			else
			{
				$this->presets = $this->getDefaultPresets();
			}

			\Bitrix\Main\Type\Collection::sortByColumn(
				$this->presets,
				array("SORT" => array(SORT_NUMERIC, SORT_ASC)),
				'',
				1000
			);
		}

		return $this->presets;
	}

	protected function getCurrentPreset(): array
	{
		if ($this->currentPreset === null)
		{
			$userOptions = $this->getUserOptions();
			$currentPresetId = $userOptions->getCurrentFilterId();
			$this->currentPreset = $this->getPresetById($currentPresetId);

			if (is_array($this->currentPreset))
			{
				$this->currentPreset["FIND"] = $userOptions->getSearchString();
			}
		}

		return $this->currentPreset ?? [];
	}

	protected function getPresetById($presetId): ?array
	{
		foreach ($this->getPresets() as $preset)
		{
			if ($preset["ID"] === $presetId)
			{
				return $preset;
			}
		}

		return null;
	}

	protected function getFilterRows(): array
	{
		if ($this->filterRows === null)
		{
			foreach ($this->arParams["FILTER"] as $field)
			{
				if (!empty($field["default"]))
				{
					$this->filterRows[] = $this->getField($field["id"]);
				}
			}
		}

		return $this->filterRows ?? [];
	}

	protected function getViewId()
	{
		$viewId = "";

		if (isset($this->arParams["RENDER_FILTER_INTO_VIEW"]) &&
			!empty($this->arParams["RENDER_FILTER_INTO_VIEW"]) &&
			is_string($this->arParams["RENDER_FILTER_INTO_VIEW"]))
		{
			$viewId = $this->arParams["RENDER_FILTER_INTO_VIEW"];
		}

		return $viewId;
	}

	protected function getViewSort()
	{
		$viewSort = $this->defaultViewSort;

		if (isset($this->arParams["RENDER_FILTER_INTO_VIEW_SORT"]) &&
			!empty($this->arParams["RENDER_FILTER_INTO_VIEW_SORT"]))
		{
			$viewSort = (int) $this->arParams["RENDER_FILTER_INTO_VIEW_SORT"];
		}

		return $viewSort;
	}

	protected function getUser(): \CUser
	{
		global $USER;
		return $USER;
	}

	protected function getCommonPresets(): ?array
	{
		if ($this->commonPresets === null)
		{
			$sourceCommonPresets = $this->getCommonOptions()["filters"] ?? null;
			if (is_array($sourceCommonPresets))
			{
				$sort = 0;
				foreach ($sourceCommonPresets as $presetId => $preset)
				{
					if (
						($preset["for_all"] ?? false)
						&& !in_array($presetId, [static::$DEFAULT_PRESET_ID, static::$TMP_PRESET_ID])
					)
					{
						$this->commonPresets[$presetId] = array_merge(
							$this->preparePreset($presetId, $preset, $sort),
							[
								"COMMON_PRESET" => true,
							],
						);
						$sort++;
					}
				}
			}
		}

		return $this->commonPresets;
	}

	protected function getSourcePresets(): array
	{
		if ($this->sourcePresets === null)
		{
			$this->sourcePresets = [];

			if (is_array($this->arParams["FILTER_PRESETS"] ?? null))
			{
				$sort = 0;
				foreach ($this->arParams["FILTER_PRESETS"] as $presetId => $preset)
				{
					if (!in_array($presetId, [static::$DEFAULT_PRESET_ID, static::$TMP_PRESET_ID]))
					{
						$this->sourcePresets[] = $this->preparePreset($presetId, $preset, $sort);
						$sort++;
					}
				}
			}
		}

		return $this->sourcePresets;
	}

	protected function getDefaultPresets(): array
	{
		if ($this->defaultPresets === null)
		{
			$this->defaultPresets = $this->getSourcePresets();

			if (!$this->getUser()->canDoOperation('edit_other_settings'))
			{
				$commonPresets = $this->getCommonPresets();
				if ($commonPresets)
				{
					$this->defaultPresets = $commonPresets;
				}
			}

			if (!array_key_exists(static::$DEFAULT_PRESET_ID, $this->defaultPresets))
			{
				$this->defaultPresets[static::$DEFAULT_PRESET_ID] = $this->createDefaultPreset();
			}
		}

		return $this->defaultPresets;
	}

	protected function createDefaultPreset(): array
	{
		return [
			"ID" => static::$DEFAULT_PRESET_ID,
			"TITLE" => Loc::getMessage("MAIN_UI_FILTER__DEFAULT_FILTER_TITLE"),
			"SORT" => 0,
			"FIELDS" => $this->getFilterRows(),
			"IS_DEFAULT" => true,
			"FOR_ALL" => true
		];
	}

	protected function preparePreset(string $presetId, array $sourcePreset = [], int $sort = 0): array
	{
		$sourceFields = $sourcePreset["fields"] ?? [];
		$rows = [];
		if (is_string($sourcePreset["filter_rows"] ?? null))
		{
			$rows = explode(",", $sourcePreset["filter_rows"]);
		}
		elseif(is_array($sourcePreset["fields"] ?? null))
		{
			$rows = \Bitrix\Main\UI\Filter\Options::getRowsFromFields($sourcePreset["fields"]);
		}

		return [
			"ID" => $presetId,
			"TITLE" => $sourcePreset["name"] ?? '',
			"SORT" => $sort,
			"FIELDS" => $this->preparePresetFields($rows, $sourceFields),
			"IS_PINNED" => $sourcePreset["default"] ?? false,
			"FOR_ALL" => !($this->arParams["FILTER_PRESETS"][$presetId]["disallow_for_all"] ?? false),
			"IS_DEFAULT" => array_key_exists($presetId, $this->arParams["FILTER_PRESETS"]),
		];
	}

	protected function getField($fieldId)
	{
		$fields = $this->prepareFields();
		$resultField = array();

		if (!empty($fields) && is_array($fields))
		{
			foreach ($fields as $fieldFields)
			{
				if ($fieldFields["NAME"] === $fieldId ||
					$fieldFields["NAME"]."_datesel" === $fieldId ||
					$fieldFields["NAME"]."_numsel" === $fieldId)
				{
					$resultField = $fieldFields;
				}
			}
		}

		return $resultField;
	}

	static function prepareField($field, $filterId = '')
	{
		return array_merge(
			FieldAdapter::adapt($field, $filterId),
			['STRICT' => isset($field['strict']) && $field['strict'] === true],
			['REQUIRED' => isset($field['required']) && $field['required'] === true],
			['VALUE_REQUIRED' => isset($field['valueRequired']) && $field['valueRequired'] === true],
		);
	}

	static function getFieldsStubs()
	{
		return [
			static::prepareField([
				'id' => 'string',
				'type' => 'string',
				'name' => 'string',
			]),
			static::prepareField([
				'id' => 'list',
				'type' => 'list',
				'name' => 'list',
				'items' => [],
			]),
			static::prepareField([
				'id' => 'date',
				'type' => 'date',
				'name' => 'date',
			]),
			static::prepareField([
				'id' => 'custom_date',
				'type' => 'custom_date',
				'name' => 'custom_date',
			]),
			static::prepareField([
				'id' => 'number',
				'type' => 'number',
				'name' => 'number',
			]),
			static::prepareField([
				'id' => 'checkbox',
				'type' => 'checkbox',
				'name' => 'checkbox',
			]),
			static::prepareField([
				'id' => 'custom_entity',
				'type' => 'custom_entity',
				'name' => 'custom_entity',
			]),
		];
	}

	protected function prepareFields()
	{
		if (!isset($this->arResult["FIELDS"]) || !is_array($this->arResult["FIELDS"]))
		{
			$this->arResult["FIELDS"] = array();
			$sourceFields = $this->arParams["FILTER"] ?? [];

			if (is_array($sourceFields) && !empty($sourceFields))
			{
				foreach ($sourceFields as $sourceField)
				{
					$this->arResult["FIELDS"][] = static::prepareField($sourceField, $this->arParams['FILTER_ID']);
				}
			}
		}

		return $this->arResult["FIELDS"];
	}

	protected function prepareSettingsUrl()
	{
		$path = $this->getPath();
		return join("/", array($path, "settings.ajax.php"));
	}

	protected function checkRequiredParams()
	{
		$errors = new \Bitrix\Main\ErrorCollection();

		if (!isset($this->arParams["FILTER_ID"]) ||
			empty($this->arParams["FILTER_ID"]) ||
			!is_string($this->arParams["FILTER_ID"]))
		{
			$errors->add(array(new \Bitrix\Main\Error(Loc::getMessage("MAIN_UI_FILTER__FILTER_ID_NOT_SET"))));
		}

		foreach ($errors->toArray() as $error)
		{
			ShowError($error);
		}

		return $errors->isEmpty();
	}

	protected function includeScripts($folder)
	{
		$tmpl = $this->getTemplate();
		$absPath = $_SERVER["DOCUMENT_ROOT"].$tmpl->GetFolder().$folder;
		$relPath = $tmpl->GetFolder().$folder;

		if (is_dir($absPath))
		{
			$dir = opendir($absPath);

			if($dir)
			{
				while(($file = readdir($dir)) !== false)
				{
					$ext = getFileExtension($file);

					if ($ext === 'js' && !(str_contains($file, 'map.js') || str_contains($file, 'min.js')))
					{
						$tmpl->addExternalJs($relPath.$file);
					}
				}

				closedir($dir);
			}
		}
	}

	protected function includeStyles($folder)
	{
		$tmpl = $this->getTemplate();
		$absPath = $_SERVER["DOCUMENT_ROOT"].$tmpl->GetFolder().$folder;
		$relPath = $tmpl->GetFolder().$folder;

		if (is_dir($absPath))
		{
			$dir = opendir($absPath);

			if($dir)
			{
				while(($file = readdir($dir)) !== false)
				{
					$ext = getFileExtension($file);

					if ($ext === 'css' && !(str_contains($file, 'map.css') || str_contains($file, 'min.css')))
					{
						$tmpl->addExternalCss($relPath.$file);
					}
				}

				closedir($dir);
			}
		}
	}

	protected function initOptions(): void
	{
		$hasOptions = \Bitrix\Main\UI\Filter\Options::hasOptions($this->arParams["FILTER_ID"]);
		if (!$hasOptions)
		{
			$this->getUserOptions()->save();
		}
	}

	protected function saveOptions()
	{
		$request = $this->request;

		if ($request->getPost("apply_filter") === "Y")
		{
			$options = $this->getUserOptions();
			$options->setFilterSettings($request->get("filter_id"), $request->toArray());
			$options->save();
		}
	}

	protected function getCommonOptions()
	{
		if (!$this->commonOptions)
		{
			$this->commonOptions = CUserOptions::getOption("main.ui.filter.common", $this->arParams["FILTER_ID"], array());
		}

		return $this->commonOptions;
	}

	protected function initParams()
	{
		if (!isset($this->arParams["FILTER_PRESETS"]) || !is_array($this->arParams["FILTER_PRESETS"]))
		{
			$this->arParams["FILTER_PRESETS"] = array();
		}

		if (!isset($this->arParams["CONFIG"]) || !is_array($this->arParams["CONFIG"]))
		{
			$this->arParams["CONFIG"] = array();
		}

		if (isset($this->arParams["VALUE_REQUIRED"]) && $this->arParams["VALUE_REQUIRED"] === true)
		{
			$allowValueRequiredParam = false;

			foreach ($this->arParams["FILTER"] as $field)
			{
				if (!$allowValueRequiredParam && isset($field["required"]) && $field["required"] === true)
				{
					$allowValueRequiredParam = true;
				}
			}

			$this->arParams["VALUE_REQUIRED"] = $allowValueRequiredParam;
		}
	}

	protected function getTheme()
	{
		if (!$this->theme && isset($this->arParams["THEME"]) && !empty($this->arParams["THEME"]))
		{
			$availableThemes = Theme::getList();
			if (in_array($this->arParams["THEME"], $availableThemes))
			{
				$this->theme = $this->arParams["THEME"];
			}
		}

		if (!$this->theme)
		{
			$this->theme = Theme::DEFAULT_FILTER;
		}

		return $this->theme;
	}

	protected function includeTheme()
	{
		$theme = $this->getTheme();
		if ($theme !== Theme::DEFAULT_FILTER)
		{
			$themePath = mb_strtolower($theme);
			$themePath = $this->themesFolder.$themePath."/";

			$this->includeStyles($themePath);
			$this->includeScripts($themePath);
		}
	}

	protected function getConfig($path)
	{
		$file = new \Bitrix\Main\IO\File($path);
		$config = array();

		if ($file->isExists())
		{
			$jsonConfig = $file->getContents();

			if (!empty($jsonConfig))
			{
				$config = \Bitrix\Main\Web\Json::decode($jsonConfig);
			}
		}

		return $config;
	}

	protected function getAbsoluteThemesPath()
	{
		$templatePath = $this->getTemplate()->getFolder();
		$relativeThemesPath = $templatePath.$this->themesFolder;
		$absolutePath = \Bitrix\Main\IO\Path::convertRelativeToAbsolute($relativeThemesPath);
		return $absolutePath;
	}

	public function prepareConfig()
	{
		$themesPath = $this->getAbsoluteThemesPath();
		$themeId = $this->getTheme();
		$themeFolder = mb_strtolower($themeId);
		$defaultConfigPath = $themesPath."/".$this->configName;
		$themeConfigPath = $themesPath."/".$themeFolder."/".$this->configName;
		$defaultConfig = $this->getConfig($defaultConfigPath);
		$themeConfig = $this->getConfig($themeConfigPath);
		$paramsConfig = $this->arParams["CONFIG"];
		return array_merge($defaultConfig, $themeConfig, $paramsConfig);
	}

	public function executeComponent()
	{
		if ($this->checkRequiredParams())
		{
			$this->initParams();
			$this->initOptions();
			$this->saveOptions();
			$this->prepareResult();
			$this->includeComponentTemplate();
			$this->includeTheme();
		}
	}
}
