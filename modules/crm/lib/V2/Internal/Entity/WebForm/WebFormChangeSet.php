<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Section;
use Bitrix\Main\ArgumentException;

/**
 * Immutable set of web form changes: one value per explicitly specified writable path.
 *
 * A path absent from the set is not changed at all, while a specified path holding null clears
 * the scalar it points to. Sections are changed through their field paths, collections only as
 * a whole: an empty list clears the collection, and null is not a valid collection value.
 *
 * What the server derives on its own is read-only and out of the contract: `id`, `code`, `system`,
 * the creation and activation stamps, the `booking` section, whose flag follows the field
 * composition of the form, and the order of a field, which is the position of the element in the
 * list sent. The id of the background image is read-only as well: only uploading a file fills it,
 * and taken from the input it would let any row of the file storage be published on the public page
 * of the form.
 *
 * The activity is writable in one context only: a form takes it while it is being created, and a
 * stored form is started and stopped by operations of its own. So every answer about what may be
 * written is given for a {@see WebFormWriteContext}, while the values themselves are held in either
 * - which write an input stands for is known to the caller that received it, not to the set it
 * turns into.
 *
 * @internal
 */
final readonly class WebFormChangeSet
{
	public const KIND_SCALAR = 'scalar';
	public const KIND_SECTION = 'section';
	public const KIND_COLLECTION = 'collection';

	private const ITEM_TYPE_INT = 'int';

	private const ROOT_PATHS = [
		WebForm::FIELD_NAME => self::KIND_SCALAR,
		WebForm::FIELD_ACTIVE => self::KIND_SCALAR,
		WebForm::FIELD_LANGUAGE_ID => self::KIND_SCALAR,
		WebForm::FIELD_TEMPLATE_ID => self::KIND_SCALAR,
		WebForm::SECTION_FIELDS => self::KIND_COLLECTION,
		WebForm::SECTION_DEPENDENCIES => self::KIND_COLLECTION,
		WebForm::SECTION_PRESET_FIELDS => self::KIND_COLLECTION,
	];

	/**
	 * Paths a form takes while it is being created and never afterwards. A stored form is started
	 * and stopped by operations of its own, which weigh the plan of the portal and the fitness of
	 * the form before they do; carried by a change of the configuration, the activity would be a
	 * second way to start a form - one going around everything those operations weigh.
	 */
	private const CREATION_ONLY_PATHS = [
		WebForm::FIELD_ACTIVE,
	];

	private const SECTION_PATHS = [
		WebForm::SECTION_APPEARANCE => [
			Section\Appearance::FIELD_TITLE => self::KIND_SCALAR,
			Section\Appearance::FIELD_DESCRIPTION => self::KIND_SCALAR,
			Section\Appearance::FIELD_BUTTON_CAPTION => self::KIND_SCALAR,
			Section\Appearance::FIELD_BUTTON_TEXT_COLOR => self::KIND_SCALAR,
			Section\Appearance::FIELD_BUTTON_BACKGROUND_COLOR => self::KIND_SCALAR,
			Section\Appearance::FIELD_COPYRIGHT_REMOVED => self::KIND_SCALAR,
		],
		WebForm::SECTION_CRM => [
			Section\CrmSettings::FIELD_ENTITY_SCHEME => self::KIND_SCALAR,
			Section\CrmSettings::FIELD_DUPLICATE_MODE => self::KIND_SCALAR,
			Section\CrmSettings::FIELD_DEAL_CATEGORY_ID => self::KIND_SCALAR,
			Section\CrmSettings::FIELD_DEAL_DUPLICATE_CONTROL_ENABLED => self::KIND_SCALAR,
			Section\CrmSettings::FIELD_SMART_PROCESS_CATEGORY_ID => self::KIND_SCALAR,
			Section\CrmSettings::FIELD_SMART_PROCESS_DUPLICATE_CONTROL_ENABLED => self::KIND_SCALAR,
		],
		WebForm::SECTION_RESPONSIBLE => [
			Section\Responsible::FIELD_USER_IDS => self::KIND_COLLECTION,
			Section\Responsible::FIELD_CHECK_WORK_TIME => self::KIND_SCALAR,
		],
		WebForm::SECTION_AGREEMENTS => [
			Section\Agreements::FIELD_ENABLED => self::KIND_SCALAR,
			Section\Agreements::FIELD_ITEMS => self::KIND_COLLECTION,
		],
		WebForm::SECTION_SUBMISSION => [
			Section\Submission::FIELD_SUCCESS => self::KIND_SECTION,
			Section\Submission::FIELD_SUCCESS . '.' . Section\SubmissionResult::FIELD_URL => self::KIND_SCALAR,
			Section\Submission::FIELD_SUCCESS . '.' . Section\SubmissionResult::FIELD_TEXT => self::KIND_SCALAR,
			Section\Submission::FIELD_FAILURE => self::KIND_SECTION,
			Section\Submission::FIELD_FAILURE . '.' . Section\SubmissionResult::FIELD_URL => self::KIND_SCALAR,
			Section\Submission::FIELD_FAILURE . '.' . Section\SubmissionResult::FIELD_TEXT => self::KIND_SCALAR,
			Section\Submission::FIELD_REDIRECT_DELAY => self::KIND_SCALAR,
			Section\Submission::FIELD_REFILL => self::KIND_SECTION,
			Section\Submission::FIELD_REFILL . '.' . Section\SubmissionRefill::FIELD_ENABLED => self::KIND_SCALAR,
			Section\Submission::FIELD_REFILL . '.' . Section\SubmissionRefill::FIELD_CAPTION => self::KIND_SCALAR,
		],
		WebForm::SECTION_PAYMENT => [
			Section\Payment::FIELD_ENABLED => self::KIND_SCALAR,
			Section\Payment::FIELD_DISABLED_PAY_SYSTEMS_ID => self::KIND_COLLECTION,
		],
		WebForm::SECTION_CALLBACK => [
			Section\Callback::FIELD_ENABLED => self::KIND_SCALAR,
			Section\Callback::FIELD_FROM => self::KIND_SCALAR,
			Section\Callback::FIELD_TEXT => self::KIND_SCALAR,
		],
		WebForm::SECTION_ANALYTICS => [
			Section\Analytics::FIELD_GOOGLE_ANALYTICS_ID => self::KIND_SCALAR,
			Section\Analytics::FIELD_GOOGLE_ANALYTICS_PAGE_VIEW_ENABLED => self::KIND_SCALAR,
			Section\Analytics::FIELD_YANDEX_METRICA_ID => self::KIND_SCALAR,
		],
		WebForm::SECTION_SPAM_PROTECTION => [
			Section\SpamProtection::FIELD_ENABLED => self::KIND_SCALAR,
		],
		WebForm::SECTION_INTEGRATIONS => [
			Section\Integrations::FIELD_WHATS_APP_ENABLED => self::KIND_SCALAR,
			Section\Integrations::FIELD_ADS => self::KIND_COLLECTION,
		],
	];

	private const COLLECTION_ITEM_TYPES = [
		WebForm::SECTION_FIELDS => Section\Field::class,
		WebForm::SECTION_DEPENDENCIES => Section\DependencyGroup::class,
		WebForm::SECTION_PRESET_FIELDS => Section\PresetField::class,
		WebFormPath::RESPONSIBLE_USER_IDS => self::ITEM_TYPE_INT,
		WebFormPath::AGREEMENT_ITEMS => Section\AgreementItem::class,
		WebFormPath::ADS => Section\AdIntegration::class,
		WebFormPath::DISABLED_PAY_SYSTEMS_ID => self::ITEM_TYPE_INT,
	];

	/**
	 * Properties every object below the root carries on the writing side, keyed by the path of that
	 * object with the indexes of the collections elided: every element of a collection has one shape.
	 *
	 * The order of a field is absent on purpose - the server derives it from the position of the
	 * element, so accepting the property would mean checking a value and then disregarding it. The
	 * queue of the responsible users and the pay systems switched off are absent as well: their
	 * elements are identifiers and carry no properties at all.
	 */
	private const ITEM_PROPERTIES = [
		WebForm::SECTION_FIELDS => [
			Section\Field::FIELD_NAME,
			Section\Field::FIELD_TYPE,
			Section\Field::FIELD_LABEL,
			Section\Field::FIELD_REQUIRED,
			Section\Field::FIELD_MULTIPLE,
			Section\Field::FIELD_PLACEHOLDER,
			Section\Field::FIELD_VALUE_TYPE,
			Section\Field::FIELD_VALUE,
		],
		WebForm::SECTION_DEPENDENCIES => [
			Section\DependencyGroup::FIELD_LOGIC,
			Section\DependencyGroup::FIELD_RULES,
		],
		WebFormPath::DEPENDENCY_RULES => [
			Section\DependencyRule::FIELD_CONDITION,
			Section\DependencyRule::FIELD_ACTION,
		],
		WebFormPath::DEPENDENCY_CONDITION => [
			Section\DependencyCondition::FIELD_FIELD_NAME,
			Section\DependencyCondition::FIELD_EVENT,
			Section\DependencyCondition::FIELD_VALUE,
			Section\DependencyCondition::FIELD_OPERATION,
		],
		WebFormPath::DEPENDENCY_ACTION => [
			Section\DependencyAction::FIELD_FIELD_NAME,
			Section\DependencyAction::FIELD_TYPE,
			Section\DependencyAction::FIELD_VALUE,
		],
		WebForm::SECTION_PRESET_FIELDS => [
			Section\PresetField::FIELD_ENTITY_NAME,
			Section\PresetField::FIELD_FIELD_NAME,
			Section\PresetField::FIELD_VALUE,
		],
		WebFormPath::AGREEMENT_ITEMS => [
			Section\AgreementItem::FIELD_AGREEMENT_ID,
			Section\AgreementItem::FIELD_CHECKED,
			Section\AgreementItem::FIELD_REQUIRED,
		],
		WebFormPath::ADS => [
			Section\AdIntegration::FIELD_DIRECTION,
			Section\AdIntegration::FIELD_PROVIDER_CODE,
			Section\AdIntegration::FIELD_ACCOUNT_ID,
			Section\AdIntegration::FIELD_ACCOUNT_NAME,
			Section\AdIntegration::FIELD_FORM_ID,
			Section\AdIntegration::FIELD_FORM_NAME,
			Section\AdIntegration::FIELD_FIELD_MAPPINGS,
		],
		WebFormPath::AD_FIELD_MAPPINGS => [
			Section\AdFieldMapping::FIELD_CRM_FIELD_NAME,
			Section\AdFieldMapping::FIELD_ADS_FIELD_NAME,
		],
	];

	/** @var array<string, mixed> */
	private array $values;

	/**
	 * @param array<string, mixed> $valuesByPath
	 * @throws ArgumentException
	 */
	public function __construct(array $valuesByPath = [])
	{
		$normalized = [];
		foreach ($valuesByPath as $path => $value)
		{
			$path = (string)$path;
			$normalized[$path] = self::normalizeValue($path, $value);
		}

		$this->values = $normalized;
	}

	/**
	 * Turns a stored form into the very set of changes sending it back would carry: every writable
	 * path in the value the form holds.
	 *
	 * It is built here because the writable paths are declared here. A second list of them, kept by
	 * whoever needs a stored form as a change set, would drift from this one silently: a path left
	 * out of it reads as a value the form does not hold rather than as a value nobody looked at.
	 *
	 * A path the form does not hold at all is left out instead of being specified. That is the state
	 * of a section the read projection did not ask for, and of a collection that was not read - null
	 * is no value for a collection, so there is no way to specify one. A scalar the form holds as
	 * null is specified as null, which is what a write sending the form back would carry for it.
	 *
	 * The context is the write the form is sent back by, and the set carries exactly what that write
	 * carries: a document of a change says nothing about the activity, because a change is not how a
	 * form is started.
	 *
	 * @throws ArgumentException when an entity of the form carries no property a writable path names
	 */
	public static function ofStoredForm(WebForm $storedForm, WebFormWriteContext $context): self
	{
		$values = [];
		foreach (self::getWritablePaths($context) as $path)
		{
			$kind = self::kindOf($path);
			if ($kind === self::KIND_SECTION)
			{
				continue;
			}

			$segments = explode('.', $path);
			$property = (string)array_pop($segments);
			$holder = self::resolveStoredHolder($storedForm, $segments, $path);
			if ($holder === null)
			{
				continue;
			}

			$value = self::readStoredProperty($holder, $property, $path);
			if ($value === null && $kind === self::KIND_COLLECTION)
			{
				continue;
			}

			$values[$path] = $value;
		}

		return new self($values);
	}

	/**
	 * Returns a copy of the set where the path is specified with the given value.
	 *
	 * Section paths are not accepted: a section is changed through its own field paths.
	 *
	 * @throws ArgumentException
	 */
	public function with(string $path, mixed $value): self
	{
		return new self(array_merge($this->values, [$path => $value]));
	}

	public function isEmpty(): bool
	{
		return $this->values === [];
	}

	/**
	 * Paths specified by this change set, in the order they were specified.
	 *
	 * @return string[]
	 */
	public function paths(): array
	{
		return array_keys($this->values);
	}

	/**
	 * Tells whether the path itself is specified. Null value of a specified path means clearing.
	 */
	public function has(string $path): bool
	{
		return array_key_exists($path, $this->values);
	}

	/**
	 * Value of a specified path.
	 *
	 * @throws ArgumentException when the path is not specified: use has() to tell the states apart
	 */
	public function get(string $path): mixed
	{
		if (!array_key_exists($path, $this->values))
		{
			throw new ArgumentException(sprintf('Web form change path `%s` is not specified', $path), 'path');
		}

		return $this->values[$path];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getValues(): array
	{
		return $this->values;
	}

	/**
	 * Tells whether the path itself or anything below it is changed. Unlike has(), it answers the
	 * same question for a scalar, a collection and a whole section.
	 *
	 * @throws ArgumentException
	 */
	public function touches(string $path): bool
	{
		self::kindOf($path);

		if (array_key_exists($path, $this->values))
		{
			return true;
		}

		$prefix = $path . '.';
		foreach (array_keys($this->values) as $specifiedPath)
		{
			if (str_starts_with($specifiedPath, $prefix))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Values specified inside the section, keyed by path relative to that section.
	 *
	 * @return array<string, mixed>
	 * @throws ArgumentException
	 */
	public function getSectionValues(string $section): array
	{
		self::requireSection($section);

		$prefix = $section . '.';
		$sectionValues = [];
		foreach ($this->values as $path => $value)
		{
			if (str_starts_with($path, $prefix))
			{
				$sectionValues[substr($path, strlen($prefix))] = $value;
			}
		}

		return $sectionValues;
	}

	/**
	 * @throws ArgumentException
	 */
	public static function kindOf(string $path): string
	{
		[$root, $relativePath] = self::parsePath($path);

		if ($relativePath === null)
		{
			if (isset(self::ROOT_PATHS[$root]))
			{
				return self::ROOT_PATHS[$root];
			}

			if (isset(self::SECTION_PATHS[$root]))
			{
				return self::KIND_SECTION;
			}
		}
		elseif (isset(self::SECTION_PATHS[$root][$relativePath]))
		{
			return self::SECTION_PATHS[$root][$relativePath];
		}

		throw new ArgumentException(sprintf('Unknown web form change path `%s`', $path), 'path');
	}

	public static function isWritablePath(string $path, WebFormWriteContext $context): bool
	{
		try
		{
			self::kindOf($path);
		}
		catch (ArgumentException)
		{
			return false;
		}

		return self::isCarriedBy($path, $context);
	}

	/**
	 * @return string[]
	 */
	public static function getWritablePaths(WebFormWriteContext $context): array
	{
		$paths = array_keys(self::ROOT_PATHS);
		foreach (self::SECTION_PATHS as $section => $sectionPaths)
		{
			$paths[] = $section;
			foreach (array_keys($sectionPaths) as $relativePath)
			{
				$paths[] = $section . '.' . $relativePath;
			}
		}

		return array_values(array_filter(
			$paths,
			static fn (string $path): bool => self::isCarriedBy($path, $context),
		));
	}

	/**
	 * Properties of one object of the write contract: an element of a collection, or an object
	 * standing inside such an element. The path carries no index - every element has one shape.
	 *
	 * @return string[]
	 * @throws ArgumentException
	 */
	public static function getWritableItemProperties(string $itemPath): array
	{
		if (!isset(self::ITEM_PROPERTIES[$itemPath]))
		{
			throw new ArgumentException(
				sprintf('Web form path `%s` carries no writable item properties', $itemPath),
				'itemPath',
			);
		}

		return self::ITEM_PROPERTIES[$itemPath];
	}

	/**
	 * The whole shape of the write contract: properties of every object of it, keyed by the path of
	 * that object. The empty path stands for the form itself, a section path for a section, and the
	 * path of a collection for an element of it.
	 *
	 * This is what the published document of the API is checked against, so that a property
	 * documented on one side and absent on the other can not go unnoticed.
	 *
	 * @return array<string, string[]>
	 */
	public static function getWritableProperties(WebFormWriteContext $context): array
	{
		$rootProperties = array_filter(
			array_keys(self::ROOT_PATHS),
			static fn (string $path): bool => self::isCarriedBy($path, $context),
		);

		$properties = ['' => array_merge(array_values($rootProperties), array_keys(self::SECTION_PATHS))];

		foreach (self::SECTION_PATHS as $section => $sectionPaths)
		{
			foreach (array_keys($sectionPaths) as $relativePath)
			{
				$parts = explode('.', $relativePath);
				$property = (string)array_pop($parts);
				$owner = $parts === [] ? $section : $section . '.' . implode('.', $parts);
				$properties[$owner][] = $property;
			}
		}

		return array_merge($properties, self::ITEM_PROPERTIES);
	}

	/**
	 * Whether the write of the given context carries the path at all. Only the paths of the form
	 * itself are told apart by the context: nothing below a section is taken by one write and
	 * refused by the other.
	 */
	private static function isCarriedBy(string $path, WebFormWriteContext $context): bool
	{
		return $context === WebFormWriteContext::Creation
			|| !in_array($path, self::CREATION_ONLY_PATHS, true)
		;
	}

	/**
	 * The object a writable path names the property of, or null when the form does not hold it.
	 *
	 * The segments of a path are walked as the properties they are named after: the correspondence
	 * of a path and a property is what the read and the write of a form already share, so a stored
	 * form is turned into a change set by it instead of by a second map of the same thing.
	 *
	 * @param string[] $segments the path without the property it ends with
	 * @throws ArgumentException
	 */
	private static function resolveStoredHolder(WebForm $storedForm, array $segments, string $path): ?object
	{
		$holder = $storedForm;
		foreach ($segments as $segment)
		{
			$holder = self::readStoredProperty($holder, $segment, $path);
			if (!is_object($holder))
			{
				return null;
			}
		}

		return $holder;
	}

	/**
	 * @throws ArgumentException when the entity carries no such property, which means a writable
	 *         path and the entity behind it have grown apart
	 */
	private static function readStoredProperty(object $holder, string $property, string $path): mixed
	{
		if (!property_exists($holder, $property))
		{
			throw new ArgumentException(
				sprintf(
					'Web form entity `%s` carries no property `%s` of the writable path `%s`',
					$holder::class,
					$property,
					$path,
				),
				'path',
			);
		}

		return $holder->{$property};
	}

	/**
	 * @throws ArgumentException
	 */
	private static function normalizeValue(string $path, mixed $value): mixed
	{
		$kind = self::kindOf($path);
		if ($kind === self::KIND_SECTION)
		{
			throw new ArgumentException(
				sprintf('Web form section `%s` is changed through its field paths, not as a whole', $path),
				'path',
			);
		}

		if ($kind === self::KIND_COLLECTION)
		{
			return self::normalizeCollection($path, $value);
		}

		if ($value !== null && !is_scalar($value))
		{
			throw new ArgumentException(
				sprintf('Web form change path `%s` accepts a scalar value or null', $path),
				'value',
			);
		}

		return $value;
	}

	/**
	 * @return array<int, mixed>
	 * @throws ArgumentException
	 */
	private static function normalizeCollection(string $path, mixed $value): array
	{
		if (!is_array($value))
		{
			throw new ArgumentException(
				sprintf('Web form collection `%s` accepts only a full list of items', $path),
				'value',
			);
		}

		$itemType = self::COLLECTION_ITEM_TYPES[$path];
		foreach ($value as $item)
		{
			$isKnownItem = $itemType === self::ITEM_TYPE_INT ? is_int($item) : $item instanceof $itemType;
			if (!$isKnownItem)
			{
				throw new ArgumentException(
					sprintf('Web form collection `%s` accepts only `%s` items', $path, $itemType),
					'value',
				);
			}
		}

		return array_values($value);
	}

	/**
	 * @throws ArgumentException
	 */
	private static function requireSection(string $section): void
	{
		if (self::kindOf($section) !== self::KIND_SECTION)
		{
			throw new ArgumentException(sprintf('Web form change path `%s` is not a section', $section), 'section');
		}
	}

	/**
	 * @return array{string, string|null}
	 * @throws ArgumentException
	 */
	private static function parsePath(string $path): array
	{
		if ($path === '')
		{
			throw new ArgumentException('Empty web form change path', 'path');
		}

		$parts = explode('.', $path);
		$root = array_shift($parts);

		return [$root, $parts === [] ? null : implode('.', $parts)];
	}
}
