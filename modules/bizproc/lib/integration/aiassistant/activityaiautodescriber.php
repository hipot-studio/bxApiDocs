<?php

namespace Bitrix\Bizproc\Integration\AiAssistant;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Entity\Activity\ActivityAiDescriptionSource;
use Bitrix\Bizproc\Internal\Entity\Activity\Result\ActivityAiDescriptionResult;
use Bitrix\Bizproc\Internal\Entity\Activity\Setting;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingCategory;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Bizproc\RestActivityTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Builds an AI settings schema for an activity from its own declared properties map.
 * Used as a fallback when the activity has no manual `.ai.php` description.
 */
class ActivityAiAutoDescriber
{
	public const ERROR_ACTIVITY_NOT_FOUND = 'AUTO_DESCRIPTION_ACTIVITY_NOT_FOUND';
	public const ERROR_DISABLED = 'AUTO_DESCRIPTION_DISABLED';
	public const ERROR_NOT_SUITABLE = 'AUTO_DESCRIPTION_NOT_SUITABLE';
	public const CONTEXT_AI_DESCRIPTION = 'AiDescription';

	private const PROPERTIES_MAP_METHOD = 'getPropertiesMap';
	private const PROPERTIES_DIALOG_MAP_METHOD = 'getPropertiesDialogMap';
	private const PASSTHROUGH_SETTING_NAMES_METHOD = 'getAiPassthroughSettingNames';
	private const RESTRICTED_SETTING_NAMES_METHOD = 'getAiRestrictedSettingNames';
	private const ENFORCED_SETTING_VALUES_METHOD = 'getAiEnforcedSettingValues';
	private const SERVICE_PROPERTIES = ['Title', 'EditorComment'];

	/**
	 * Builds an AI settings schema for a REST activity from its RestActivityTable entry.
	 * Non-REST activity codes are not supported; use {@see buildMapSettingsLenient()} instead.
	 *
	 * Takes no pinned-values context: a REST activity declares its schema in the table, with nothing to
	 * resolve from the values a preset applies. Accepting one here promised a caller more than the method does.
	 *
	 * @see buildMapSettingsLenient() for the lenient file-based path used by the gate.
	 */
	public function describe(string $code, array $documentType): Result|ActivityAiDescriptionResult
	{
		if ($this->isRestActivityCode($code))
		{
			return $this->describeRestActivity($code, $documentType);
		}

		// Non-REST activities use the lenient path via the gate (buildMapSettingsLenient).
		// The strict describe() path is intentionally limited to REST activity codes.
		return (new Result())->addError(
			new Error('describe() is only supported for REST activity codes', self::ERROR_NOT_SUITABLE)
		);
	}

	/**
	 * Builds a lenient settings map from the activity's declared properties map.
	 * Unlike the strict (REST) {@see describe()} path, this method never returns NOT_SUITABLE:
	 * - required properties that cannot be expressed go to $skipped rather than aborting;
	 * - an empty map, a disabled activity, or a REST code yields [empty collection, []].
	 *
	 * @param string $code Activity code.
	 * @param array $documentType ['module', 'entityType', 'documentType'].
	 *
	 * @return array{0: SettingCollection, 1: list<string>} [$settings, $skippedPropertyNames]
	 */
	public function buildMapSettingsLenient(string $code, array $documentType, array $context = []): array
	{
		if ($this->isRestActivityCode($code))
		{
			return [new SettingCollection(), []];
		}

		// Collected before the early returns, not after: passthrough names do not come from the properties map
		// and are not derived, so an activity that answers with no schema at all still owes them. A manual
		// `.ai.php` reads them from here, and without them AgentSettingNameValidator refuses the block by name.
		$passthrough = $this->passthroughSettingNames($code, $documentType);

		$activityDescription = $this->findActivityDescription($code);
		if ($activityDescription === null)
		{
			return [new SettingCollection(), $passthrough];
		}

		if (!$activityDescription->isAiAutoDescriptionAllowed())
		{
			return [new SettingCollection(), $passthrough];
		}

		$map = $this->getDeclaredPropertiesMap($code, $activityDescription, $documentType, $context);
		$map = array_diff_key($map, array_flip(self::SERVICE_PROPERTIES));

		if (!$map)
		{
			return [new SettingCollection(), $passthrough];
		}

		[$settings, $skipped] = $this->buildSettingsResultLenient($map, $documentType);

		return [$settings, array_values(array_unique(array_merge($skipped, $passthrough)))];
	}

	/**
	 * Lenient variant of {@see buildSettingsResult()}: unresolvable properties (required or not)
	 * are collected into $skipped instead of aborting with NOT_SUITABLE.
	 *
	 * @return array{0: SettingCollection, 1: list<string>}
	 */
	private function buildSettingsResultLenient(array $map, array $documentType): array
	{
		$converter = new ActivityAiPropertyConverter();
		$settings = new SettingCollection();
		$skipped = [];

		foreach ($map as $name => $field)
		{
			$name = (string)$name;
			$setting = $this->convertProperty($converter, $name, $field, $documentType);
			if ($setting !== null)
			{
				$settings->add($setting);
			}
			else
			{
				$skipped[] = $name;
			}
		}

		return [$settings, $skipped];
	}

	/**
	 * Names the activity declares as passthrough (persisted but intentionally not type-mapped).
	 * Merged into $skipped so reverse validation accepts them by name without touching their values.
	 *
	 * Public because a caller that lost the lenient build to an exception still has to answer with these names
	 * ({@see \Bitrix\Bizproc\Service\AiDescription::buildActivityDescription()}); the call itself never throws.
	 *
	 * @return list<string>
	 */
	public function passthroughSettingNames(string $code, array $documentType): array
	{
		return $this->settingNamesOf($code, self::PASSTHROUGH_SETTING_NAMES_METHOD, $documentType);
	}

	/**
	 * Lifecycle category of every name the activity declares through its three AI hooks, by the compatibility
	 * rule of TPL-01: a passthrough name with no restricted twin is a free map, a restricted one is carried, an
	 * enforced key is enforced. Enforced wins over the others - the server writes that value on every save, so
	 * nothing sent under the name survives, not even what the published template carries.
	 *
	 * An activity that declares no hook answers with an empty map and keeps behaving exactly as it did before
	 * categories existed.
	 *
	 * @return array<string, SettingCategory>
	 */
	public function hookSettingCategories(string $code, array $documentType): array
	{
		$categories = [];

		foreach ($this->passthroughSettingNames($code, $documentType) as $name)
		{
			$categories[$name] = SettingCategory::EditableFreeMap;
		}

		foreach ($this->settingNamesOf($code, self::RESTRICTED_SETTING_NAMES_METHOD, $documentType) as $name)
		{
			$categories[$name] = SettingCategory::Carried;
		}

		foreach ($this->enforcedSettingNames($code, $documentType) as $name)
		{
			$categories[$name] = SettingCategory::Enforced;
		}

		return $categories;
	}

	/**
	 * @return list<string>
	 */
	private function enforcedSettingNames(string $code, array $documentType): array
	{
		$values = $this->callActivityStaticMethod($code, self::ENFORCED_SETTING_VALUES_METHOD, [$documentType]);
		if (!is_array($values))
		{
			return [];
		}

		return array_values(array_filter(array_keys($values), 'is_string'));
	}

	/**
	 * @return list<string>
	 */
	private function settingNamesOf(string $code, string $method, array $documentType): array
	{
		$names = $this->callActivityStaticMethod($code, $method, [$documentType]);
		if (!is_array($names))
		{
			return [];
		}

		return array_values(array_filter($names, 'is_string'));
	}

	private function findActivityDescription(string $code): ?ActivityDescription
	{
		return Container::instance()->getActivitySearcherService()->searchByCode($code);
	}

	private function getDeclaredPropertiesMap(
		string $code,
		ActivityDescription $activityDescription,
		array $documentType,
		array $context = []
	): array
	{
		// $context carries the values the block is already pinned to - today the properties a preset applies.
		// Activities that resolve their option lists from another setting need it: the field list of a CRM
		// trigger comes from its own `Document`, so an empty context answers with an empty SELECT, and the
		// agent is handed a required setting with no allowed values it could possibly know.
		// Empty stays the default: without pinned values an activity must answer with its full schema rather
		// than one trimmed to a half-filled dialog, which is what the designer sees on first open.
		$context[self::CONTEXT_AI_DESCRIPTION] = true;
		$map = $this->callActivityStaticMethod($code, self::PROPERTIES_MAP_METHOD, [$documentType, $context]);
		if (!is_array($map) || !$map)
		{
			$dialog = new PropertiesDialog(
				$this->resolveActivityFilePath($code, $activityDescription),
				['documentType' => $documentType]
			);
			$rawDialogMap = $this->callActivityStaticMethod($code, self::PROPERTIES_DIALOG_MAP_METHOD, [$dialog]);
			// Dialog map is a list of field descriptors; convert to a properties map keyed by FieldName.
			$map = $this->reindexDialogMap($rawDialogMap);
		}

		if (!is_array($map))
		{
			return [];
		}

		return array_filter($map, 'is_array');
	}

	/**
	 * Filters a dialog map returned by `getPropertiesDialogMap()` to a clean properties map.
	 *
	 * `getPropertiesDialogMap()` already returns an ASSOCIATIVE array whose **keys are property names**
	 * (PascalCase, e.g. `Users`, `Name`, `AccessType`). This is the canonical `Setting.name` used
	 * everywhere else - it matches the keys of `getPropertiesMap()` and the keys in `.ai.php`.
	 *
	 * `FieldName` inside each descriptor is the HTML form-control name (snake_case, e.g.
	 * `requested_users`) - it is NOT the property name and must NOT be used as the map key.
	 *
	 * Numeric or empty-string keys indicate synthetic UI-only entries (e.g. section headers) and
	 * are dropped. Non-array values are likewise dropped.
	 *
	 * @param mixed $rawMap Return value of `getPropertiesDialogMap()`.
	 * @return array<string, array>|null Keyed by property name (map key), or null if $rawMap is not an array.
	 */
	private function reindexDialogMap(mixed $rawMap): ?array
	{
		if (!is_array($rawMap))
		{
			return null;
		}

		$map = [];
		foreach ($rawMap as $key => $field)
		{
			if (!is_string($key) || $key === '' || !is_array($field))
			{
				// Numeric-keyed or non-array entries are synthetic/UI-only - skip.
				continue;
			}

			$map[$key] = $field;
		}

		return $map ?: null;
	}

	private function callActivityStaticMethod(string $code, string $method, array $parameters): mixed
	{
		try
		{
			// without the included activity file callStaticMethod() returns an error-shaped array,
			// which the caller could mistake for a valid properties map
			if (!\CBPRuntime::getRuntime()->includeActivityFile($code))
			{
				return null;
			}

			return \CBPActivity::callStaticMethod($code, $method, $parameters);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	/**
	 * Builds a strict settings schema for a REST activity from its RestActivityTable entry.
	 *
	 * Intentional asymmetry with the file-based lenient path (buildMapSettingsLenient):
	 * REST properties come from a machine-readable database record (RestActivityTable.PROPERTIES)
	 * rather than a PHP file. A required property whose type cannot be expressed means the DB record
	 * is malformed - returning a partial schema would silently hide data loss. For REST activities
	 * the strict NOT_SUITABLE response is therefore preferable to partial/lenient acceptance.
	 * File-based activities tolerate partial schemas because their property maps are richer and
	 * partial coverage is a normal authoring reality (not a data-integrity violation).
	 */
	private function describeRestActivity(string $code, array $documentType): Result|ActivityAiDescriptionResult
	{
		$normalizedCode = $this->normalizeActivityCode($code);
		$internalCode = mb_substr($normalizedCode, mb_strlen(\CBPRuntime::REST_ACTIVITY_PREFIX));

		$queryResult = RestActivityTable::getList([
			'filter' => ['=INTERNAL_CODE' => $internalCode],
			// Only PROPERTIES are selected; RETURN_PROPERTIES are intentionally excluded
			// from the settings schema - they are output fields, not inputs the AI agent sets.
			'select' => ['PROPERTIES'],
			'cache' => ['ttl' => 3600],
			'limit' => 1,
		]);

		$row = $queryResult->fetch();
		if ($row === false)
		{
			return (new Result())->addError(
				new Error('REST activity not found in database', self::ERROR_ACTIVITY_NOT_FOUND)
			);
		}

		$rawProperties = is_array($row['PROPERTIES']) ? $row['PROPERTIES'] : [];

		$map = [];
		$langId = defined('LANGUAGE_ID') ? LANGUAGE_ID : 'EN';
		foreach ($rawProperties as $name => $property)
		{
			if (!is_array($property))
			{
				continue;
			}

			if (isset($property['NAME']) && is_array($property['NAME']))
			{
				$property['NAME'] = RestActivityTable::getLocalization($property['NAME'], $langId);
			}

			if (isset($property['DESCRIPTION']) && is_array($property['DESCRIPTION']))
			{
				$property['DESCRIPTION'] = RestActivityTable::getLocalization($property['DESCRIPTION'], $langId);
			}

			$map[(string)$name] = $property;
		}

		$map = array_diff_key($map, array_flip(self::SERVICE_PROPERTIES));

		return $this->buildSettingsResult($code, $map, $documentType);
	}

	private function buildSettingsResult(
		string $code,
		array $map,
		array $documentType,
	): Result|ActivityAiDescriptionResult
	{
		$converter = new ActivityAiPropertyConverter();
		$settings = new SettingCollection();
		$skippedSettings = [];

		foreach ($map as $name => $field)
		{
			$name = (string)$name;
			$setting = $this->convertProperty($converter, $name, $field, $documentType);
			if ($setting !== null)
			{
				$settings->add($setting);

				continue;
			}

			if ($this->isRequiredProperty($field))
			{
				return (new Result())->addError(new Error(
					"Required property '{$name}' cannot be expressed in the settings schema",
					self::ERROR_NOT_SUITABLE
				));
			}

			$skippedSettings[] = $name;
		}

		return new ActivityAiDescriptionResult(
			code: $code,
			settings: $settings,
			source: ActivityAiDescriptionSource::Auto,
			skippedSettings: $skippedSettings,
		);
	}

	private function convertProperty(
		ActivityAiPropertyConverter $converter,
		string $name,
		array $field,
		array $documentType
	): ?Setting
	{
		try
		{
			return $converter->convertMap([$name => $field], $documentType)->findFirstByName($name);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function isRequiredProperty(array $field): bool
	{
		try
		{
			$normalized = FieldType::normalizeProperty($field);
		}
		catch (\Throwable)
		{
			return false;
		}

		return !empty($normalized['Required']);
	}

	private function resolveActivityFilePath(string $code, ActivityDescription $activityDescription): string
	{
		$path = $activityDescription->getPathToActivity();
		if ($path === '')
		{
			return '';
		}

		return $path . '/' . $this->normalizeActivityCode($code) . '.php';
	}

	private function normalizeActivityCode(string $code): string
	{
		$normalizedCode = mb_strtolower(trim($code));
		if (str_starts_with($normalizedCode, 'cbp'))
		{
			$normalizedCode = mb_substr($normalizedCode, 3);
		}

		return $normalizedCode;
	}

	public function isRestActivityCode(string $code): bool
	{
		return str_starts_with($this->normalizeActivityCode($code), \CBPRuntime::REST_ACTIVITY_PREFIX);
	}
}
