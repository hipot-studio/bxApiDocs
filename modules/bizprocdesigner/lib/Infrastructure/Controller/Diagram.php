<?php

namespace Bitrix\BizprocDesigner\Infrastructure\Controller;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\Api;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Public\Command\WorkflowTemplate\UpdateWorkflowTemplate\UpdateWorkflowTemplateCommand;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\Bizproc\Workflow\Template\Converter\SequentialToNodeWorkflow;
use Bitrix\BizprocDesigner\Infrastructure\Enum\StartTrigger;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class Diagram extends JsonController
{
	protected function init()
	{
		parent::init();
		Loader::requireModule('bizproc');
	}

	public function getAction(int $templateId = 0, ?array $documentType = null, ?string $startTrigger = null): ?array
	{
		$validatedStartTrigger = $this->validateStartTrigger($startTrigger);

		$data = $this->getTemplateData($templateId, $documentType, $validatedStartTrigger);

		if ($data)
		{
			$companyName = \Bitrix\Main\Config\Option::get('bitrix24', 'site_title');
			$data['companyName'] = $companyName;
		}

		return $data;
	}

	public function publicateAction(): ?array
	{
		$diagramData = $this->prepareDiagramData();

		if ($diagramData === null)
		{
			return null;
		}

		if ($this->getTpl($diagramData['templateId'])?->getType() !== Api\Enum\Template\WorkflowTemplateType::Nodes->value)
		{
			$this->addError(
				new Error(Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_ERROR_TEMPLATE_TYPE'))
			);

			return null;
		}

		return $this->saveTemplate(
			$diagramData['templateId'],
			$diagramData['fields'],
			$diagramData['user'],
			$diagramData['draftId'],
		);
	}

	public function publicateDraftAction(): ?array
	{
		$diagramData = $this->prepareDiagramData();

		if ($diagramData === null)
		{
			return null;
		}

		if ($this->getTpl($diagramData['templateId'])?->getType() !== Api\Enum\Template\WorkflowTemplateType::Nodes->value)
		{
			$this->addError(
				new Error(Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_ERROR_TEMPLATE_TYPE'))
			);

			return null;
		}

		return $this->saveTemplateDraft(
			$diagramData['templateId'],
			$diagramData['fields'],
			$diagramData['user'],
			$diagramData['draftId'],
		);
	}

	public function updateTemplateAction(int $templateId, array $data): ?array
	{
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);
		if (!$user->isAdmin())
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return null;
		}

		return (new UpdateWorkflowTemplateCommand($templateId, $data))->run()->getData();
	}

	private function getTemplateData(int $id, ?array $newDocumentType, ?string $startTrigger = null): ?array
	{
		$tpl = $id > 0 ? $this->getTpl($id) : null;
		$documentType = $tpl ? $tpl->getDocumentComplexType() : $newDocumentType;
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);
		$canWrite = $documentType && \CBPDocument::CanUserOperateDocumentType(
			\CBPCanUserOperateOperation::CreateWorkflow,
			$user->getId(),
			$documentType
		);

		if (!$canWrite)
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return null;
		}

		if ($id === 0 && $documentType)
		{
			$tpl = $this->createEmptyTemplate($documentType, $startTrigger);
		}

		if (!$tpl)
		{
			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getError(['#ID#' => $id]));

			return null;
		}

		$draftId = 0;
		if ($tpl->getType() !== Api\Enum\Template\WorkflowTemplateType::Nodes->value)
		{
			$this->addError(
				new Error(Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_ERROR_TEMPLATE_TYPE'))
			);

			return null;
		}

		$draftTplData = $tpl->getTemplateDraft()->getAll()[0] ?? null;
		if ($draftTplData)
		{
			$draftId = $draftTplData->getId();
			$tplData = $draftTplData->getTemplateData()['TEMPLATE'];
		}
		else
		{
			$tplData = $tpl->getTemplate();
		}
		$tpl = $tpl->collectValues();
		$trackOn = (int)\Bitrix\Main\Config\Option::get('bizproc', 'tpl_track_on_' . $id, 0);
		$root = $tplData[0];

		$blocks = $this->createBlocks($root[NodesToTemplate::ELEMENT_CHILDREN], $documentType);
		$connections = $this->createConnections(
			$root[NodesToTemplate::ELEMENT_PROPERTIES][NodesToTemplate::PROPERTY_LINKS]
		);

		return [
			'template' => array_merge($tpl, ['TRACK_ON' => $trackOn]),
			'templateId' => $tpl['ID'],
			'draftId' => $draftId,
			'documentType' => $documentType,
			'documentTypeSigned' => \CBPDocument::signDocumentType($documentType),
			'blocks' => $blocks,
			'connections' => $connections,
		];
	}

	private function transformBlockActivities(array $activities): array
	{
		foreach ($activities as &$activity)
		{
			$activity['ReturnProperties'] = $this->getActivityReturnProperties($activity);
		}

		return $activities;
	}

	private function getActivityReturnProperties(array|string $activityOrCode): array
	{
		$props = \CBPRuntime::getRuntime()->getActivityReturnProperties($activityOrCode);
		foreach ($props as $id => &$prop)
		{
			$prop['Id'] = $id;
		}

		return array_values($props);
	}

	/**
	 * @param array $documentType
	 * @param string|null $startTrigger
	 * @return \Bitrix\Bizproc\Workflow\Template\Tpl|null
	 */
	private function createEmptyTemplate(array $documentType, ?string $startTrigger = null): ?\Bitrix\Bizproc\Workflow\Template\Tpl
	{
		$tpl = $this->getDefaultTemplateFields($startTrigger);
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);
		$fields = $this->prepareFields($tpl, $documentType);
		$request = new Api\Request\WorkflowTemplateService\SaveTemplateRequest(
			templateId: 0,
			parameters: [],
			fields: $fields,
			user: $user,
			checkAccess: false
		);
		$templateService = new Api\Service\WorkflowTemplateService();
		$result = $templateService->saveTemplate($request);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $this->getTpl($result->getTemplateId());
	}

	private function getDefaultTemplateFields(?string $startTrigger = null): array
	{
		$template = [];

		$converter = new SequentialToNodeWorkflow([]);
		if ($startTrigger)
		{
			$converter->setStartTrigger($startTrigger);
		}
		$template['TEMPLATE'] = $converter->convert();

		$template['NAME'] = Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_TEMPLATE_DEFAULT_TITLE');
		$template['AUTO_EXECUTE'] = \CBPDocumentEventType::None;
		$template['DESCRIPTION'] = '';
		$template['PARAMETERS'] = [];
		$template['VARIABLES'] = [];
		$template['CONSTANTS'] = [];
		$template['TEMPLATE_SETTINGS'] = [];

		return $template;
	}

	private function prepareFields(array $template, array $documentType): array
	{
		return [
			'TEMPLATE' => $template['TEMPLATE'],
			'DOCUMENT_TYPE' => $documentType,
			'NAME' => $template['NAME'],
			'DESCRIPTION' => $template['DESCRIPTION'],
			'PARAMETERS' => $template['PARAMETERS'],
			'VARIABLES' => $template['VARIABLES'],
			'CONSTANTS' => $template['CONSTANTS'],
			'TEMPLATE_SETTINGS' => $template['TEMPLATE_SETTINGS'],
			'AUTO_EXECUTE' => $template['AUTO_EXECUTE'],
		];
	}

	private function prepareDiagramData(): ?array
	{
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);

		$json = Application::getInstance()->getContext()->getRequest()->getJsonList();
		$templateId = (int)$json->get('templateId');
		$documentType = \CBPDocument::unSignDocumentType($json->get('documentTypeSigned'));

		$canWrite = $documentType && \CBPDocument::CanUserOperateDocumentType(
			\CBPCanUserOperateOperation::CreateWorkflow,
			$user->getId(),
			$documentType
		);

		if (!$canWrite)
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return null;
		}

		$blocks = (array)$json->get('blocks');
		$connections = (array)$json->get('connections');
		$converter = new NodesToTemplate($blocks, $connections);

		$template = $json->get('template');
		$template['TEMPLATE'] = $converter->convert();
		$fields = $this->prepareFields($template, $documentType);

		$draftId = (int)$json->get('draftId');

		return [
			'templateId' => $templateId,
			'fields' => $fields,
			'user' => $user,
			'draftId' => $draftId,
		];
	}

	private function saveTemplate(
		int $templateId,
		array $fields,
		\CBPWorkflowTemplateUser $user,
		int $draftId
	): ?array
	{
		$templateService = new Api\Service\WorkflowTemplateService();
		$request = new Api\Request\WorkflowTemplateService\SaveTemplateRequest(
			$templateId,
			[],
			$fields,
			$user,
			false
		);
		$result = $templateService->saveTemplate($request);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}

		return $result->getData();
	}

	private function saveTemplateDraft(
		int $templateId,
		array $fields,
		\CBPWorkflowTemplateUser $user,
		int $draftId,
	): array
	{
		$request = new Api\Request\WorkflowTemplateService\SaveTemplateDraftRequest(
			$templateId,
			[],
			$fields,
			$user,
			false,
			$draftId
		);

		$templateService = new Api\Service\WorkflowTemplateService();
		$result = $templateService->saveTemplateDraft($request);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}

		return $result->getData();
	}

	/**
	 * @param array $activities
	 * @return array|array[]
	 */
	private function createBlocks(array $activities, ?array $documentType): array
	{
		/** @var Searcher $searcher */
		$searcher = ServiceLocator::getInstance()->get('bizproc.runtime.activitysearcher.searcher');

		$defaultActivities =
			$searcher->searchByType(
				[ActivityType::NODE->value, ActivityType::TRIGGER->value],
				$documentType
			)
				->filter(static fn(ActivityDescription $description) => !$description->getExcluded())
				->sort()
		;

		$iconMap = [];
		/* @var ActivityDescription $activity*/
		foreach ($defaultActivities as $activity)
		{
			$class  = $activity->getClass();
			if (!$class)
			{
				continue;
			}

			if ($activity->getPresets())
			{
				foreach ($activity->getPresets() as $preset)
				{
					$presetActivity = $activity->applyPreset($preset);
					$id = $class . '_' .  $preset['ID'];
					$iconMap[$id] = [
						'CODE' => $presetActivity->getIcon(),
						'COLOR' => $presetActivity->getColorIndex(),
					];
				}
			}
			else
			{
				$iconMap[$class] = [
					'CODE' => $activity->getIcon(),
					'COLOR' => $activity->getColorIndex(),
				];
			}
		}

		return array_map(
			static function ($child) use ($iconMap) {
				$node = $child['Node'];
				unset($child['Node']);

				$nodeType = $node['type'] ?? 'simple';
				if (str_ends_with($child['Type'], 'Trigger'))
				{
					$nodeType = 'trigger';
				}

				$activityType = $child['Type'] ?? null;
				if (isset($child['PresetId']))
				{
					$activityType .= '_' . $child['PresetId'];
				}

				$icon = $activityType && isset($iconMap[$activityType]['CODE']) ? $iconMap[$activityType]['CODE'] : null;
				$color = $activityType && isset($iconMap[$activityType]['COLOR']) ? $iconMap[$activityType]['COLOR'] : null;

				return [
					'id' => $node['id'],
					'type' => $nodeType,
					'position' => $node['position'],
					'dimensions' => $node['dimensions'],
					'ports' => $node['ports'],
					'activity' => $child,
					'node' => [
						'type' => $nodeType,
						'title' => $node['node']['title'],
						'colorIndex' => $color,
						'icon' => $icon,
						'updated' => $node['node']['updated'] ?? null,
						'published' => $node['node']['published'] ?? null,
					],
				];
			},
			$this->transformBlockActivities($activities)
		);
	}

	/**
	 * @param mixed $links
	 * @return array|array[]
	 */
	private function createConnections(mixed $links): array
	{
		return array_map(
			static function (array $link) {
				[$sourceBlockId, $targetBlockId] = $link;

				$sourcePortId = 'o0';
				$targetPortId = 'i0';

				if (str_contains($sourceBlockId, ':'))
				{
					[$sourceBlockId, $sourcePortId] = explode(':', $sourceBlockId);
				}

				if (str_contains($targetBlockId, ':'))
				{
					[$targetBlockId, $targetPortId] = explode(':', $targetBlockId);
				}

				$type = null;
				if ($sourcePortId[0] === 'a' && $targetPortId[0] === 't')
				{
					$type = 'aux';
				}

				return [
					'id' => "{$sourceBlockId}_{$targetBlockId}",
					'sourceBlockId' => $sourceBlockId,
					'sourcePortId' => $sourcePortId,
					'targetBlockId' => $targetBlockId,
					'targetPortId' => $targetPortId,
					'type' => $type,
				];
			},
			$links,
		);
	}

	/**
	 * @param int $id
	 * @return \Bitrix\Bizproc\Workflow\Template\Tpl
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	private function getTpl(int $id): ?\Bitrix\Bizproc\Workflow\Template\Tpl
	{
		$tpl = WorkflowTemplateTable::query()
			->where('ID', $id)
			->whereNull('SYSTEM_CODE')
			->setSelect([
				'*',
				'TEMPLATE_SETTINGS',
				'TEMPLATE_DRAFT.TEMPLATE_DATA',
			])
			->setLimit(1)
			->setOrder(['TEMPLATE_DRAFT.CREATED' => 'DESC'])
			->exec()
			->fetchObject();

		return $tpl;
	}

	private function validateStartTrigger(?string $startTrigger): ?string
	{
		if (is_null($startTrigger))
		{
			return null;
		}

		return StartTrigger::tryFrom($startTrigger)?->value;
	}
}
