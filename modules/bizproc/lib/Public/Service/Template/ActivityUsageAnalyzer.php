<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\Template;

use Bitrix\Bizproc\BaseType\NodeReference;
use Bitrix\Bizproc\FieldType;
use CBPActivity;
use CBPHelper;
use CBPRuntime;
use CBPWorkflowTemplateLoader;
use Bitrix\Bizproc\Workflow\Template\SourceType;
use Exception;

class ActivityUsageAnalyzer
{
	protected array $template;
	protected array $parameters = [];
	protected array $variables = [];
	protected array $constants = [];
	protected array $globalConstants = [];
	protected array $globalVariables = [];
	protected array $documentFields = [];
	private ?\Closure $returnDocumentFieldsResolver = null;

	private const FIELD_SUFFIXES = ['', '_printable', '_PRINTABLE'];

	public function __construct(array $template)
	{
		$this->template = $template;
	}

	public function setGlobalConstants(array $constants): static
	{
		$this->globalConstants = $constants;

		return $this;
	}

	public function setGlobalVariables(array $variables): static
	{
		$this->globalVariables = $variables;

		return $this;
	}

	public function setDocumentFields(array $fields): static
	{
		$this->documentFields = $fields;

		return $this;
	}

	/**
	 * Fields of a document returned by a block, by its document type; the document service by default. A
	 * surface knowing more fields than the document service answers declares them here.
	 *
	 * @param callable(array): array $resolver
	 */
	public function setReturnDocumentFieldsResolver(callable $resolver): static
	{
		$this->returnDocumentFieldsResolver = $resolver(...);

		return $this;
	}

	public function setParameters(array $parameters): static
	{
		$this->parameters = $parameters;

		return $this;
	}

	public function setVariables(array $variables): static
	{
		$this->variables = $variables;

		return $this;
	}

	public function setConstants(array $constants): static
	{
		$this->constants = $constants;

		return $this;
	}

	public function analyzeUsages(string $activityName): array
	{
		$brokenLinks = [];

		$activity = CBPWorkflowTemplateLoader::findActivityByName($this->template, $activityName) ?? [];
		if (!$activity)
		{
			return $brokenLinks;
		}

		$resolveActivityInstance = $this->activityInstanceResolver($activity);

		foreach ($this->getActivityUsages($resolveActivityInstance) as $usage)
		{
			[$object, $field] = $usage;
			$returnField = $usage[2] ?? null;

			if ($this->isBrokenUsageField($object, $field, $returnField))
			{
				if ($object === SourceType::Parameter)
				{
					$object = 'Template';
				}

				$link =
					$object === SourceType::Activity
						? $this->buildFieldLink($field, $returnField)
						: $this->buildFieldLink($object, $field)
				;

				$brokenLinks[$link] = $link;
			}
		}

		foreach ($this->collectBrokenNodeReferences($activity, $resolveActivityInstance()) as $link)
		{
			$brokenLinks[$link] = $link;
		}

		return $brokenLinks;
	}

	/**
	 * Activities of the template referencing the data element, in template order. The halves of a pair are
	 * asked about together: every activity is then read once instead of once per source type.
	 *
	 * @param string[] $sourceTypes
	 * @return array<int, array{activityName: string, title: string}>
	 */
	public function analyzeDataElementUsages(array $sourceTypes, string $key): array
	{
		$usages = [];

		foreach (self::collectTemplateActivities($this->template) as $activity)
		{
			if ($this->usesDataElement($activity, $sourceTypes, $key))
			{
				$usages[] = [
					'activityName' => (string)($activity['Name'] ?? ''),
					'title' => (string)($activity['Properties']['Title'] ?? ''),
				];
			}
		}

		return $usages;
	}

	/**
	 * The instance of the activity knows the usages its class declares, and the text of its properties knows
	 * the ones written by the user. The second reading is not a fallback of the first: an activity of a deleted
	 * application is not instantiated at all, and a reference of it would otherwise be answered by nobody.
	 *
	 * @param string[] $sourceTypes
	 */
	private function usesDataElement(array $activity, array $sourceTypes, string $key): bool
	{
		foreach ($this->getActivityUsages($this->activityInstanceResolver($activity)) as $usage)
		{
			if (!is_array($usage))
			{
				continue;
			}

			[$object, $field] = $usage;
			if (in_array($object, $sourceTypes, true) && mb_strtolower((string)$field) === mb_strtolower($key))
			{
				return true;
			}
		}

		return ExpressionReferenceScanner::hasReference($activity['Properties'] ?? null, $sourceTypes, $key);
	}

	/**
	 * The activities of a template as a flat list, the nested ones included.
	 *
	 * @return array<int, array>
	 */
	public static function collectTemplateActivities(array $activities): array
	{
		$collected = [];

		foreach ($activities as $activity)
		{
			if (!is_array($activity))
			{
				continue;
			}

			$collected[] = $activity;

			if (is_array($activity['Children'] ?? null))
			{
				$collected = array_merge($collected, self::collectTemplateActivities($activity['Children']));
			}
		}

		return $collected;
	}

	/**
	 * Built on the first ask and only once: the usage parse quits before it needs an instance when the
	 * runtime has no root activity to hang one on.
	 */
	private function activityInstanceResolver(array $activity): \Closure
	{
		$activityInstance = false;

		return function () use ($activity, &$activityInstance): ?CBPActivity
		{
			if ($activityInstance === false)
			{
				$activityInstance = $this->createActivityInstance($activity);
			}

			return $activityInstance;
		};
	}

	/**
	 * Nodes a `nodereference` property of the activity points at while the template holds no such node. The
	 * type owns the wording, so the report gets a sentence instead of a synthesized `{=...}` reference the
	 * user never wrote.
	 *
	 * @return string[]
	 */
	private function collectBrokenNodeReferences(array $activity, ?CBPActivity $activityInstance): array
	{
		if ($activityInstance === null)
		{
			return [];
		}

		$brokenLinks = [];
		$properties = $activity['Properties'] ?? [];

		foreach ($activityInstance->getPropertiesTypes() as $propertyName => $propertyType)
		{
			if (($propertyType['Type'] ?? '') !== FieldType::NODEREFERENCE)
			{
				continue;
			}

			// The value is a bare node name: an expression is refused while the settings are saved, so what
			// stands here either names a node of the template or names nothing.
			$nodeName = $properties[$propertyName] ?? null;
			$nodeName = is_scalar($nodeName) ? trim((string)$nodeName) : '';

			if ($nodeName === '' || CBPWorkflowTemplateLoader::findActivityByName($this->template, $nodeName))
			{
				continue;
			}

			$brokenLinks[] = NodeReference::formatBrokenLink($nodeName);
		}

		return $brokenLinks;
	}

	/** The class of an activity declares the types of its properties, so they are read off an instance. */
	private function createActivityInstance(array $activity): ?CBPActivity
	{
		$type = (string)($activity['Type'] ?? '');

		try
		{
			$this->includeActivity($type);
			$activityInstance = CBPActivity::createInstance($type, $activity['Name'] ?? '');
			// A node overrides the filling, so an activity that cannot be filled is skipped just as one that
			// cannot be instantiated.
			$activityInstance?->initializeFromArray($activity['Properties'] ?? []);
		}
		catch (Exception)
		{
			return null;
		}

		return $activityInstance;
	}

	private function includeActivity(string $code): void
	{
		$searcher = \Bitrix\Bizproc\Internal\Service\Container::instance()->getActivitySearcherService();
		$searcher->includeActivityFile($code);
	}

	private function getActivityUsages(\Closure $resolveActivityInstance): array
	{
		$usages = [];
		try
		{
			$this->includeActivity('SequentialWorkflowActivity');
			$rootActivity = CBPActivity::createInstance('SequentialWorkflowActivity', 'Template');
			if (!$rootActivity)
			{
				return [];
			}

			$activityInstance = $resolveActivityInstance();
			if ($activityInstance === null)
			{
				return [];
			}

			$rootActivity->fixUpParentChildRelationship($activityInstance);
			$rootActivity->setProperties($this->parameters);
			$rootActivity->setVariablesTypes($this->variables);

			/** @var CBPActivity[] $children */
			$children = $rootActivity->collectNestedActivities();
			if (is_array($children))
			{
				$usages = $children[0]->collectUsages();
			}
		}
		catch (Exception $e)
		{
			// ignore
		}

		return $usages;
	}

	private function buildCheckMap(): array
	{
		$checkMap = [
			SourceType::DocumentField => $this->documentFields,
			SourceType::GlobalConstant => $this->globalConstants,
			SourceType::GlobalVariable => $this->globalVariables,
			SourceType::Variable => $this->variables,
			SourceType::Constant => $this->constants,
			SourceType::Parameter => $this->parameters,
		];
		foreach (SourceType::BUILT_IN_PARAMETER_KEYS as $builtInKey)
		{
			$checkMap[SourceType::Parameter][$builtInKey] = [];
		}

		return $checkMap;
	}

	private function isBrokenUsageField(string $object, string $field, ?string $returnField): bool
	{
		if ($this->isFieldMissingInMap($object, $field))
		{
			return true;
		}

		if ($object === SourceType::Activity && $this->isActivityReturnFieldMissing($field, $returnField))
		{
			return true;
		}

		return false;
	}

	private function isFieldMissingInMap(string $object, string $field): bool
	{
		$checkMap = $this->buildCheckMap();

		return array_key_exists($object, $checkMap) && $this->isFieldMissing($field, $checkMap[$object]);
	}

	private function isActivityReturnFieldMissing(string $activityName, string $returnField): bool
	{
		$runtime = CBPRuntime::getRuntime();

		$activityUsage = CBPWorkflowTemplateLoader::findActivityByName($this->template, $activityName);
		$returnProperties = $runtime->getActivityReturnProperties($activityUsage);

		if (!$this->isFieldMissing($returnField, $returnProperties))
		{
			return false;
		}

		return $this->isDocumentReturnFieldMissing($returnField, $returnProperties);
	}

	private function isDocumentReturnFieldMissing(string $returnField, array $returnProperties): bool
	{
		if (!str_contains($returnField, '.'))
		{
			return true;
		}

		$fieldToCheck = $returnField;
		$suffixParts = [];
		while ($fieldToCheck !== '')
		{
			if (!$this->isFieldMissing($fieldToCheck, $returnProperties))
			{
				$property = $this->getFieldIfExists($fieldToCheck, $returnProperties);
				$fieldName = implode('.', array_reverse($suffixParts));

				return $this->checkReturnDocumentField($property, $fieldName);
			}

			$lastDot = strrpos($fieldToCheck, '.');
			if ($lastDot === false)
			{
				break;
			}
			$suffixParts[] = substr($fieldToCheck, $lastDot + 1);
			$fieldToCheck = substr($fieldToCheck, 0, $lastDot);
		}

		return true;
	}

	private function checkReturnDocumentField(array $property, string $fieldName): bool
	{
		$propertyType = $property['Type'];
		$documentType = $property['Default'] ?? null;

		if ($propertyType === FieldType::DOCUMENT && $documentType && CBPHelper::normalizeComplexDocumentId($documentType))
		{
			$documentFields = $this->resolveReturnDocumentFields($documentType);

			return (!$documentFields || $this->isFieldMissing($fieldName, $documentFields));
		}

		return true;
	}

	private function resolveReturnDocumentFields(array $documentType): array
	{
		if ($this->returnDocumentFieldsResolver !== null)
		{
			return ($this->returnDocumentFieldsResolver)($documentType);
		}

		return CBPRuntime::getRuntime()->getDocumentService()->getDocumentFields($documentType) ?: [];
	}

	private function isFieldMissing(string $field, array $haystack): bool
	{
		return $this->findFieldKey($field, $haystack) === null;
	}

	private function getFieldIfExists(string $field, array $haystack): ?array
	{
		$key = $this->findFieldKey($field, $haystack);

		return $key !== null ? $haystack[$key] : null;
	}

	private function findFieldKey(string $field, array $haystack): ?string
	{
		foreach (self::FIELD_SUFFIXES as $suffix)
		{
			$key = $field . $suffix;
			if (array_key_exists($key, $haystack))
			{
				return $key;
			}
		}

		return null;
	}

	private function buildFieldLink(string $object, string $field): string
	{
		return '{=' . $object . ':' . $field . '}';
	}
}
