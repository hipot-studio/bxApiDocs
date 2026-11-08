<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Section;
use Bitrix\Main\ArgumentException;

/**
 * @internal
 */
final class WebFormSelect
{
	public const WILDCARD = '*';

	private const BASE_FIELDS = [
		WebForm::FIELD_ID => true,
		WebForm::FIELD_CODE => true,
		WebForm::FIELD_NAME => true,
		WebForm::FIELD_ACTIVE => true,
		WebForm::FIELD_SYSTEM => true,
		WebForm::FIELD_LANGUAGE_ID => true,
		WebForm::FIELD_TEMPLATE_ID => true,
		WebForm::FIELD_CREATED_TIME => true,
		WebForm::FIELD_MODIFIED_TIME => true,
		WebForm::FIELD_ACTIVE_CHANGED_BY_ID => true,
		WebForm::FIELD_ACTIVE_CHANGED_TIME => true,
	];

	private const SECTION_FIELD_PATHS = [
		WebForm::SECTION_APPEARANCE => [
			Section\Appearance::FIELD_TITLE,
			Section\Appearance::FIELD_DESCRIPTION,
			Section\Appearance::FIELD_BUTTON_CAPTION,
			Section\Appearance::FIELD_BUTTON_TEXT_COLOR,
			Section\Appearance::FIELD_BUTTON_BACKGROUND_COLOR,
			Section\Appearance::FIELD_BACKGROUND_IMAGE_ID,
			Section\Appearance::FIELD_COPYRIGHT_REMOVED,
		],
		WebForm::SECTION_CRM => [
			Section\CrmSettings::FIELD_ENTITY_SCHEME,
			Section\CrmSettings::FIELD_DUPLICATE_MODE,
			Section\CrmSettings::FIELD_DEAL_CATEGORY_ID,
			Section\CrmSettings::FIELD_DEAL_DUPLICATE_CONTROL_ENABLED,
			Section\CrmSettings::FIELD_SMART_PROCESS_CATEGORY_ID,
			Section\CrmSettings::FIELD_SMART_PROCESS_DUPLICATE_CONTROL_ENABLED,
		],
		WebForm::SECTION_FIELDS => [
			'name',
			'type',
			'label',
			'sort',
			'required',
			'multiple',
			'placeholder',
			'valueType',
			'value',
		],
		WebForm::SECTION_DEPENDENCIES => [
			'logic',
			'rules',
			'rules.condition',
			'rules.condition.fieldName',
			'rules.condition.event',
			'rules.condition.value',
			'rules.condition.operation',
			'rules.action',
			'rules.action.fieldName',
			'rules.action.type',
			'rules.action.value',
		],
		WebForm::SECTION_PRESET_FIELDS => [
			'entityName',
			'fieldName',
			'value',
		],
		WebForm::SECTION_RESPONSIBLE => [
			'userIds',
			'checkWorkTime',
		],
		WebForm::SECTION_AGREEMENTS => [
			'enabled',
			'items',
			'items.agreementId',
			'items.checked',
			'items.required',
		],
		WebForm::SECTION_SUBMISSION => [
			Section\Submission::FIELD_SUCCESS,
			Section\Submission::FIELD_SUCCESS . '.' . Section\SubmissionResult::FIELD_URL,
			Section\Submission::FIELD_SUCCESS . '.' . Section\SubmissionResult::FIELD_TEXT,
			Section\Submission::FIELD_FAILURE,
			Section\Submission::FIELD_FAILURE . '.' . Section\SubmissionResult::FIELD_URL,
			Section\Submission::FIELD_FAILURE . '.' . Section\SubmissionResult::FIELD_TEXT,
			Section\Submission::FIELD_REDIRECT_DELAY,
			Section\Submission::FIELD_REFILL,
			Section\Submission::FIELD_REFILL . '.' . Section\SubmissionRefill::FIELD_ENABLED,
			Section\Submission::FIELD_REFILL . '.' . Section\SubmissionRefill::FIELD_CAPTION,
		],
		WebForm::SECTION_PAYMENT => [
			Section\Payment::FIELD_ENABLED,
			Section\Payment::FIELD_DISABLED_PAY_SYSTEMS_ID,
		],
		WebForm::SECTION_CALLBACK => [
			'enabled',
			'from',
			'text',
		],
		WebForm::SECTION_BOOKING => [
			'enabled',
		],
		WebForm::SECTION_ANALYTICS => [
			'googleAnalyticsId',
			'googleAnalyticsPageViewEnabled',
			'yandexMetricaId',
		],
		WebForm::SECTION_SPAM_PROTECTION => [
			'enabled',
		],
		WebForm::SECTION_INTEGRATIONS => [
			'whatsAppEnabled',
			'ads',
			'ads.direction',
			'ads.providerCode',
			'ads.accountId',
			'ads.accountName',
			'ads.formId',
			'ads.formName',
			'ads.fieldMappings',
			'ads.fieldMappings.crmFieldName',
			'ads.fieldMappings.adsFieldName',
		],
	];

	private const BASE_BACKED_SECTIONS = [
		WebForm::SECTION_APPEARANCE => true,
		WebForm::SECTION_CRM => true,
		WebForm::SECTION_RESPONSIBLE => true,
		WebForm::SECTION_AGREEMENTS => true,
		WebForm::SECTION_SUBMISSION => true,
		WebForm::SECTION_PAYMENT => true,
		WebForm::SECTION_CALLBACK => true,
		WebForm::SECTION_BOOKING => true,
		WebForm::SECTION_ANALYTICS => true,
		WebForm::SECTION_SPAM_PROTECTION => true,
		WebForm::SECTION_INTEGRATIONS => true,
	];

	private const CHILD_SECTIONS = [
		WebForm::SECTION_FIELDS => true,
		WebForm::SECTION_DEPENDENCIES => true,
		WebForm::SECTION_PRESET_FIELDS => true,
		WebForm::SECTION_RESPONSIBLE => true,
		WebForm::SECTION_AGREEMENTS => true,
		WebForm::SECTION_INTEGRATIONS => true,
	];

	/** @var string[] */
	private array $baseFields;

	/** @var array<string, string[]> */
	private array $sectionFieldsBySection;

	/**
	 * @param string[] $baseFields
	 * @param array<string, string[]> $sectionFieldsBySection
	 * @throws ArgumentException
	 */
	public function __construct(array $baseFields = [], array $sectionFieldsBySection = [])
	{
		$this->baseFields = self::normalizeBaseFields($baseFields);
		$this->sectionFieldsBySection = self::normalizeSectionFields($sectionFieldsBySection);
	}

	public static function onlyId(): self
	{
		return new self([WebForm::FIELD_ID]);
	}

	/**
	 * @param string[] $paths
	 * @throws ArgumentException
	 */
	public static function fromPaths(array $paths): self
	{
		if ($paths === [])
		{
			return self::onlyId();
		}

		$baseFields = [];
		$sectionFieldsBySection = [];
		foreach ($paths as $path)
		{
			[$root, $relativePath] = self::parsePath($path);

			if ($relativePath === null)
			{
				if (isset(self::BASE_FIELDS[$root]))
				{
					$baseFields[] = $root;

					continue;
				}

				self::requireSection($root);
				$sectionFieldsBySection[$root] = self::SECTION_FIELD_PATHS[$root];

				continue;
			}

			self::requireSectionField($root, $relativePath);
			$sectionFieldsBySection[$root][] = $relativePath;
		}

		return new self($baseFields, $sectionFieldsBySection);
	}

	/**
	 * @return string[]
	 */
	public function getBaseFields(): array
	{
		return $this->baseFields;
	}

	public function hasBaseField(string $field): bool
	{
		return in_array($field, $this->baseFields, true);
	}

	/**
	 * @return string[]
	 */
	public function getSectionNames(): array
	{
		return array_keys($this->sectionFieldsBySection);
	}

	public function hasSection(string $section): bool
	{
		return isset($this->sectionFieldsBySection[$section]);
	}

	/**
	 * @return string[]
	 */
	public function getSectionFields(string $section): array
	{
		return $this->sectionFieldsBySection[$section] ?? [];
	}

	/**
	 * @return array<string, string[]>
	 */
	public function getSectionFieldsBySection(): array
	{
		return $this->sectionFieldsBySection;
	}

	/**
	 * @return string[]
	 */
	public function getBaseBackedSectionNames(): array
	{
		return array_values(array_filter(
			$this->getSectionNames(),
			static fn(string $section): bool => isset(self::BASE_BACKED_SECTIONS[$section]),
		));
	}

	/**
	 * @return string[]
	 */
	public function getChildSectionNames(): array
	{
		return array_values(array_filter(
			$this->getSectionNames(),
			static fn(string $section): bool => isset(self::CHILD_SECTIONS[$section]),
		));
	}

	/**
	 * @return string[]
	 */
	public function getPaths(): array
	{
		$paths = $this->baseFields;
		foreach ($this->sectionFieldsBySection as $section => $fields)
		{
			foreach ($fields as $field)
			{
				$paths[] = $section . '.' . $field;
			}
		}

		return $paths;
	}

	/**
	 * @return string[]
	 */
	public static function getAllowedPaths(): array
	{
		$paths = array_keys(self::BASE_FIELDS);
		foreach (self::SECTION_FIELD_PATHS as $section => $fields)
		{
			$paths[] = $section;
			foreach ($fields as $field)
			{
				$paths[] = $section . '.' . $field;
			}
		}

		return $paths;
	}

	public static function isAllowedPath(string $path): bool
	{
		try
		{
			self::fromPaths([$path]);

			return true;
		}
		catch (ArgumentException)
		{
			return false;
		}
	}

	/**
	 * @param string[] $fields
	 * @return string[]
	 * @throws ArgumentException
	 */
	private static function normalizeBaseFields(array $fields): array
	{
		$normalized = [WebForm::FIELD_ID];
		foreach ($fields as $field)
		{
			self::requireBaseField($field);
			$normalized[] = $field;
		}

		return array_values(array_unique($normalized));
	}

	/**
	 * @param array<string, string[]> $sectionFieldsBySection
	 * @return array<string, string[]>
	 * @throws ArgumentException
	 */
	private static function normalizeSectionFields(array $sectionFieldsBySection): array
	{
		$normalized = [];
		foreach ($sectionFieldsBySection as $section => $fields)
		{
			self::requireSection($section);
			if ($fields === [])
			{
				$fields = self::SECTION_FIELD_PATHS[$section];
			}

			foreach ($fields as $field)
			{
				self::requireSectionField($section, $field);
				$normalized[$section][] = $field;
			}

			$normalized[$section] = array_values(array_unique($normalized[$section] ?? []));
		}

		return $normalized;
	}

	/**
	 * @return array{string, string|null}
	 * @throws ArgumentException
	 */
	private static function parsePath(string $path): array
	{
		if ($path === '')
		{
			throw new ArgumentException('Empty web form select path', 'path');
		}

		$parts = explode('.', $path);
		if (in_array(self::WILDCARD, $parts, true))
		{
			throw new ArgumentException(sprintf('Wildcard is not supported in web form select path `%s`', $path), 'path');
		}

		$root = array_shift($parts);

		return [$root, $parts === [] ? null : implode('.', $parts)];
	}

	/**
	 * @throws ArgumentException
	 */
	private static function requireBaseField(string $field): void
	{
		if (!isset(self::BASE_FIELDS[$field]))
		{
			throw new ArgumentException(sprintf('Unknown web form base field `%s`', $field), 'field');
		}
	}

	/**
	 * @throws ArgumentException
	 */
	private static function requireSection(string $section): void
	{
		if (!isset(self::SECTION_FIELD_PATHS[$section]))
		{
			throw new ArgumentException(sprintf('Unknown web form section `%s`', $section), 'section');
		}
	}

	/**
	 * @throws ArgumentException
	 */
	private static function requireSectionField(string $section, string $field): void
	{
		self::requireSection($section);
		if (!in_array($field, self::SECTION_FIELD_PATHS[$section], true))
		{
			throw new ArgumentException(
				sprintf('Unknown web form section path `%s.%s`', $section, $field),
				'path',
			);
		}
	}
}
