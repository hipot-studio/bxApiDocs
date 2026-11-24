<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service\SystemTemplateView;

use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;
use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Bizproc\Public\ProcessData\Service\ProcessDataMechanicsResolver;
use Bitrix\Bizproc\Workflow\Template\Converter\TemplateToNodes;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\Tpl;
use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\BizprocDesigner\Internal\Service\Activity\ReadableExpressionContextFactory;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

/**
 * Reads the stored row of a system template for the read-only view of the editor. Only a row carrying
 * SYSTEM_CODE is ever read here - the write paths of the designer resolve by SYSTEM_CODE IS NULL - and
 * neither a draft, a pilot version nor a lease of it is looked at: the view shows the reference as it is.
 *
 * Checks no rights: the id is the output of {@see \Bitrix\Bizproc\Public\Service\AiAgent\SystemTemplateViewService}.
 */
class SystemTemplateSchemeReader
{
	private ?ReadableExpressionContextFactory $readableExpressionContextFactory;

	public function __construct(?ReadableExpressionContextFactory $readableExpressionContextFactory = null)
	{
		$this->readableExpressionContextFactory = $readableExpressionContextFactory;
	}

	/**
	 * @return array{template: array, documentType: array, documentTypeSigned: string, blocks: array,
	 *     connections: array, versionLabel: ?string}|null Null when the id names no system Nodes template.
	 */
	public function read(int $systemTemplateId): ?array
	{
		$tpl = $this->findSystemTemplate($systemTemplateId);
		if ($tpl === null)
		{
			return null;
		}

		$documentType = $tpl->getDocumentComplexType();
		$templateRow = $tpl->collectValues();

		[$blocks, $connections] = (new TemplateToNodes(
			$tpl->getTemplate(),
			Feature::instance()->areComplexNodeConnectionsAvailable(),
			baseContentBlockContext: $this->readableExpressionContextFactory()->createForAuthorizedSchema(
				$templateRow,
				$documentType,
			),
		))->convert();

		return [
			'template' => array_merge($templateRow, [
				'TRACK_ON' => (int)Option::get('bizproc', 'tpl_track_on_' . $systemTemplateId, 0),
				'TEMPLATE_SETTINGS' => $this->processDataSettings($systemTemplateId),
			]),
			'documentType' => $documentType,
			'documentTypeSigned' => \CBPDocument::signDocumentType($documentType),
			'blocks' => $blocks,
			'connections' => $connections,
			'versionLabel' => $this->versionLabel($templateRow['MODIFIED'] ?? null),
		];
	}

	/**
	 * Everything the settings form of one node is built from, taken from the stored row alone, in the
	 * transport {@see \Bitrix\BizprocDesigner\Internal\Service\Activity\SettingsControlsBuilder::build()}
	 * expects.
	 *
	 * isComplexNodeAction tells an action of a complex node - a descendant of an activity the palette
	 * declares complex, which is how the editor types the node - from the node itself.
	 *
	 * @return array{documentType: array, activity: array, workflow: array, isComplexNodeAction: bool}|null
	 *     Null when the id names no system Nodes template or the template has no activity of that name.
	 */
	public function readActivityContext(int $systemTemplateId, string $activityName): ?array
	{
		if ($activityName === '')
		{
			return null;
		}

		$tpl = $this->findSystemTemplate($systemTemplateId);
		if ($tpl === null)
		{
			return null;
		}

		$template = $tpl->getTemplate() ?? [];
		$activity = \CBPWorkflowTemplateLoader::findActivityByName($template, $activityName);
		if (!is_array($activity))
		{
			return null;
		}

		return [
			'documentType' => $tpl->getDocumentComplexType(),
			'activity' => [
				'Type' => (string)($activity['Type'] ?? ''),
				'Name' => $activityName,
				'Properties' => is_array($activity['Properties'] ?? null) ? $activity['Properties'] : [],
			],
			'workflow' => [
				'workflowTemplate' => Json::encode($template),
				'workflowParameters' => Json::encode($tpl->getParameters() ?? []),
				'workflowVariables' => Json::encode($tpl->getVariables() ?? []),
				'workflowConstants' => Json::encode($tpl->getConstants() ?? []),
			],
			'isComplexNodeAction' => $this->hasComplexAncestor($template, $activityName),
		];
	}

	private function hasComplexAncestor(array $template, string $activityName): bool
	{
		$searcher = Container::instance()->getActivitySearcherService();
		foreach ($this->findAncestorTypes($template, $activityName) ?? [] as $ancestorType)
		{
			if ($searcher->searchByCode($ancestorType)?->getNodeType() === ActivityNodeType::COMPLEX->value)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @return string[]|null Types of the activities enclosing the named one, null when there is no such activity.
	 */
	private function findAncestorTypes(array $activities, string $activityName, array $ancestorTypes = []): ?array
	{
		foreach ($activities as $activity)
		{
			if (!is_array($activity))
			{
				continue;
			}

			if (($activity['Name'] ?? null) === $activityName)
			{
				return $ancestorTypes;
			}

			if (is_array($activity['Children'] ?? null))
			{
				$found = $this->findAncestorTypes(
					$activity['Children'],
					$activityName,
					[...$ancestorTypes, (string)($activity['Type'] ?? '')],
				);
				if ($found !== null)
				{
					return $found;
				}
			}
		}

		return null;
	}

	private function findSystemTemplate(int $systemTemplateId): ?Tpl
	{
		if ($systemTemplateId <= 0)
		{
			return null;
		}

		return WorkflowTemplateTable::query()
			->where('ID', $systemTemplateId)
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->whereNotNull('SYSTEM_CODE')
			->setSelect(['*'])
			->setLimit(1)
			->exec()
			->fetchObject()
		;
	}

	/** Same marker the editor reads for a template of its own ({@see ProcessDataMechanicsResolver}). */
	private function processDataSettings(int $templateId): array
	{
		return (new ProcessDataMechanicsResolver())->isMarked([], $templateId)
			? [ProcessDataMechanicsResolver::MARKER => ProcessDataMechanicsResolver::MARKER_VALUE]
			: []
		;
	}

	/** The reference version is named by its date, as the upgrade dialog of the grid names it. */
	private function versionLabel(mixed $modified): ?string
	{
		if (!$modified instanceof DateTime)
		{
			return null;
		}

		return \FormatDate('d.m.Y', $modified->getTimestamp());
	}

	private function readableExpressionContextFactory(): ReadableExpressionContextFactory
	{
		return $this->readableExpressionContextFactory ??= new ReadableExpressionContextFactory();
	}
}
