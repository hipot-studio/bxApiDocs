<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity;

use Bitrix\Bizproc\Activity\Dto\ExpressionLabelDictionary;
use Bitrix\Bizproc\Workflow\Template\SourceType;
use Bitrix\Bizproc\Workflow\Template\SystemValueCatalog;
use Bitrix\Main\Application;

/**
 * Builds the label dictionary of one template schema for {@see ReadableExpressionResolver}.
 *
 * Reads nothing by itself: declarations and activities come as arguments. The label of a step output
 * is left to the deferred resolver of the dictionary, because reading it costs an activity descriptor -
 * a file read per new type and a database query per REST activity instance; a caller whose schema
 * itself costs a read defers the whole dictionary ({@see self::buildDeferredDictionary()}).
 *
 * Descriptors are cached per instance, so a step carried by both the acting and the published schema
 * of one template is described once for the two of them.
 *
 * Not final on purpose: {@see self::readActivityOutputProperties()} is the seam a test replaces to
 * count descriptor reads.
 */
class ExpressionLabelDictionaryBuilder
{
	/** Schema block => the source type its declarations answer under. */
	private const DECLARATION_BLOCKS = [
		'PARAMETERS' => SourceType::Parameter,
		'VARIABLES' => SourceType::Variable,
		'CONSTANTS' => SourceType::Constant,
	];

	/** Built-in parameters stand in no block of the schema: the selector offers them as system values. */
	private const BUILT_IN_PARAMETER_SYSTEM_VALUES = [
		'TargetUser' => [SystemValueCatalog::OBJECT_TEMPLATE, 'TargetUser'],
		'TemplateActivatedBy' => [SystemValueCatalog::OBJECT_WORKFLOW, 'TemplateActivatedBy'],
	];

	/** @var array<string, array<string, array>> descriptor key of a node => its output package */
	private array $outputProperties = [];

	/**
	 * @param array $schema Declarations of one template, read by its PARAMETERS, VARIABLES and
	 *        CONSTANTS blocks.
	 * @param array<int, array{Name?: string, Type?: string, Properties?: array}> $activities Flat list
	 *        of the activities of the same template, the way ContentBlockResolver walks it.
	 */
	public static function build(array $schema, array $activities): ExpressionLabelDictionary
	{
		return (new static())->buildDictionary($schema, $activities);
	}

	/**
	 * @param array $schema Declarations of one template, read by its PARAMETERS, VARIABLES and
	 *        CONSTANTS blocks.
	 * @param array<int, array{Name?: string, Type?: string, Properties?: array}> $activities Flat list
	 *        of the activities of the same template.
	 */
	public function buildDictionary(array $schema, array $activities): ExpressionLabelDictionary
	{
		$labels = new ExpressionLabelDictionary();

		$this->declareBuiltInParameters($labels);
		$this->declareSchemaBlocks($labels, $schema);

		$nodes = $this->indexActivitiesByName($activities);
		$labels->declareDeferred(
			SourceType::Activity,
			fn(string $key): array => $this->readActivityOutputLabels($key, $nodes),
		);

		return $labels;
	}

	/**
	 * Dictionary of a schema the caller has not read yet: every source is deferred, so the schema is read
	 * on the first reference that really occurred and not at all when there is none. Built-in parameters
	 * are deferred along with the declarations they stand among, because a declaration of the schema may
	 * name one of them over the catalog and that is unknown until the schema is read.
	 *
	 * @param callable(): array{0: array, 1: array<int, array>} $readSchema Called at most once per
	 *        dictionary; answers with the very pair {@see self::buildDictionary()} takes.
	 */
	public function buildDeferredDictionary(callable $readSchema): ExpressionLabelDictionary
	{
		$labels = new ExpressionLabelDictionary();

		$schema = null;
		$nodes = [];
		$readOnce = function () use (&$schema, &$nodes, $readSchema): void {
			if ($schema !== null)
			{
				return;
			}

			[$readSchemaBlocks, $readActivities] = $readSchema();
			$schema = is_array($readSchemaBlocks) ? $readSchemaBlocks : [];
			$nodes = $this->indexActivitiesByName(is_array($readActivities) ? $readActivities : []);
		};

		$declaredBlocks = [];
		foreach (self::DECLARATION_BLOCKS as $block => $sourceType)
		{
			$labels->declareDeferred(
				$sourceType,
				function () use (&$schema, &$declaredBlocks, $readOnce, $block, $sourceType): array {
					// The whole block answers the first miss of its source: a further miss is a key the block
					// does not declare, not a block left to read.
					if (isset($declaredBlocks[$block]))
					{
						return [];
					}

					$readOnce();
					$declaredBlocks[$block] = true;

					$declared = $this->readDeclarationLabels($schema[$block] ?? null);

					return $sourceType === SourceType::Parameter
						? $declared + $this->readBuiltInParameterLabels()
						: $declared
					;
				},
			);
		}

		$labels->declareDeferred(
			SourceType::Activity,
			function (string $key) use (&$nodes, $readOnce): array {
				$readOnce();

				return $this->readActivityOutputLabels($key, $nodes);
			},
		);

		return $labels;
	}

	/**
	 * RETURN of the descriptor of the node plus whatever its ADDITIONAL_RESULT points at - hence the
	 * whole node and not its type alone.
	 *
	 * @return array<string, array> output id => normalized property
	 */
	protected function readActivityOutputProperties(array $activityNode): array
	{
		return \CBPRuntime::getRuntime()->getActivityReturnProperties($activityNode);
	}

	private function declareBuiltInParameters(ExpressionLabelDictionary $labels): void
	{
		foreach ($this->readBuiltInParameterLabels() as $key => $label)
		{
			$labels->declare(SourceType::Parameter, $key, $label);
		}
	}

	/** @return array<string, string> */
	private function readBuiltInParameterLabels(): array
	{
		$labels = [];
		foreach (SourceType::BUILT_IN_PARAMETER_KEYS as $key)
		{
			[$object, $field] = self::BUILT_IN_PARAMETER_SYSTEM_VALUES[$key] ?? [null, null];
			if ($object === null)
			{
				continue;
			}

			$labels[$key] = trim((string)SystemValueCatalog::getLabel($object, $field));
		}

		return $labels;
	}

	private function declareSchemaBlocks(ExpressionLabelDictionary $labels, array $schema): void
	{
		foreach (self::DECLARATION_BLOCKS as $block => $sourceType)
		{
			foreach ($this->readDeclarationLabels($schema[$block] ?? null) as $key => $label)
			{
				$labels->declare($sourceType, (string)$key, $label);
			}
		}
	}

	/**
	 * Labels of one block of declarations, keyed the way the dictionary keys them.
	 *
	 * @return array<string, string>
	 */
	private function readDeclarationLabels(mixed $declarations): array
	{
		if (!is_array($declarations))
		{
			return [];
		}

		$labels = [];
		foreach ($declarations as $key => $declaration)
		{
			$name = is_array($declaration) ? ($declaration['Name'] ?? null) : null;
			if (is_string($name))
			{
				$labels[(string)$key] = trim($name);
			}
		}

		return $labels;
	}

	/**
	 * The output package of one node, read at most once per builder: a read costs a database query for a
	 * step of a REST activity.
	 *
	 * @return array<string, array>
	 */
	private function readOutputPropertiesOnce(array $activityNode): array
	{
		// The package follows the type of the node and the properties its ADDITIONAL_RESULT may point at.
		$key = (string)($activityNode['Type'] ?? '') . "\n" . md5(serialize($activityNode['Properties'] ?? null));

		return $this->outputProperties[$key] ??= $this->readActivityOutputProperties($activityNode);
	}

	/**
	 * @param array<int, array{Name?: string}> $activities
	 * @return array<string, array> activity name => its node
	 */
	private function indexActivitiesByName(array $activities): array
	{
		$nodes = [];
		foreach ($activities as $activity)
		{
			if (!is_array($activity))
			{
				continue;
			}

			$name = (string)($activity['Name'] ?? '');
			if ($name !== '')
			{
				$nodes[$name] = $activity;
			}
		}

		return $nodes;
	}

	/**
	 * Labels of every output of the step the missing key points at, so one reference pays for the whole
	 * package. An unknown step, an unknown type or an unreadable descriptor answers with nothing.
	 *
	 * @param array<string, array> $nodes
	 * @return array<string, string>
	 */
	private function readActivityOutputLabels(string $key, array $nodes): array
	{
		$separatorPosition = strpos($key, ':');
		if ($separatorPosition === false)
		{
			return [];
		}

		$activityName = substr($key, 0, $separatorPosition);
		$node = $nodes[$activityName] ?? null;
		if ($node === null || !is_string($node['Type'] ?? null) || $node['Type'] === '')
		{
			return [];
		}

		try
		{
			$properties = $this->readOutputPropertiesOnce($node);
		}
		catch (\Throwable $e)
		{
			// Fail-closed: a broken descriptor leaves the reference unresolved instead of breaking the text.
			Application::getInstance()->getExceptionHandler()->writeToLog($e);

			return [];
		}

		$labels = [];
		foreach ($properties as $outputId => $property)
		{
			$name = is_array($property) ? ($property['Name'] ?? null) : null;
			if (!is_string($name))
			{
				continue;
			}

			$outputKey = ExpressionLabelDictionary::makeActivityOutputKey($activityName, (string)$outputId);
			$labels[$outputKey] = trim($name);
		}

		return $labels;
	}
}
