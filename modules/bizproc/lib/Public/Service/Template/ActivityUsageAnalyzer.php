<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\Template;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\Main\DI\ServiceLocator;
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

		foreach ($this->getActivityUsages($activity) as $usage)
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

		return $brokenLinks;
	}

	private function includeActivity(string $code): void
	{
		CBPRuntime::getRuntime()->includeActivityFile($code);

		// /** @var Searcher $searcher */
		// $searcher = ServiceLocator::getInstance()->get('bizproc.runtime.activitysearcher.searcher');
		// $searcher->includeActivityFile($code);
	}

	private function getActivityUsages(array $activity): array
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

			$this->includeActivity($activity['Type']);
			$activityInstance = CBPActivity::createInstance($activity['Type'], $activity['Name']);
			if (!$activityInstance)
			{
				return [];
			}

			$activityInstance->initializeFromArray($activity['Properties']);

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
		$checkMap[SourceType::Parameter]['TargetUser'] = [];

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
			$documentService = CBPRuntime::getRuntime()->getDocumentService();
			$documentFields = $documentService->getDocumentFields($documentType);

			return (!$documentFields || $this->isFieldMissing($fieldName, $documentFields));
		}

		return true;
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
