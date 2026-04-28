<?php

namespace Bitrix\BizprocDesigner\Infrastructure\Controller;

use Bitrix\Bizproc\Api;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Public\Command\WorkflowTemplate\UpdateWorkflowTemplate\UpdateWorkflowTemplateCommand;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\Bizproc\Workflow\Template\Converter\TemplateToNodes;
use Bitrix\Bizproc\Workflow\Template\Converter\SequentialToNodeWorkflow;
use Bitrix\Bizproc\Workflow\Template\Converter\StateMachineToNodeWorkflow;
use Bitrix\BizprocDesigner\Infrastructure\Enum\StartTrigger;
use Bitrix\Main\Application;
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

	public function getAction(
		int $templateId = 0,
		?array $documentType = null,
		?string $startTrigger = null,
		?string $editBlock = null,
	): ?array
	{
		$validatedStartTrigger = $this->validateStartTrigger($startTrigger);

		$data = $this->getTemplateData($templateId, $documentType, $validatedStartTrigger);

		//todo: proto
		if ($data && $editBlock)
		{
			foreach ($data['blocks'] as $block)
			{
				if ($block['id'] === $editBlock && !empty($block['activity']['Children']))
				{
					[$blocks, $connections] = (new TemplateToNodes($block['activity']['Children']))->convert();
					$data['blocks'] = $blocks;
					$data['connections'] = $connections;
					$data['publishedBlocks'] = [];
					$data['publishedConnection'] = [];
					break;
				}
			}
		}

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
			return ['templateDraftId' => 0];
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

		if (
			$tpl->getType() !== Api\Enum\Template\WorkflowTemplateType::Nodes->value
			&& !defined('\Bitrix\Bizproc\Dev\ENV')
		)
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
		$trackOn = (int)\Bitrix\Main\Config\Option::get('bizproc', 'tpl_track_on_' . $id, 0);

		[$blocks, $connections] = (new TemplateToNodes($tplData))->convert();
		[$publishedBlocks, $publishedConnection] = (new TemplateToNodes($tpl->getTemplate()))->convert();

		return [
			'template' => array_merge($tpl->collectValues(), ['TRACK_ON' => $trackOn]),
			'templateId' => $tpl->getId(),
			'draftId' => $draftId,
			'documentType' => $documentType,
			'documentTypeSigned' => \CBPDocument::signDocumentType($documentType),
			'blocks' => $blocks,
			'connections' => $connections,
			'publishedBlocks' => $publishedBlocks,
			'publishedConnection' => $publishedConnection,
		];
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

	/**
	 * @return array{templateId: int, fields: array, user: \CBPWorkflowTemplateUser, draftId: int}|null
	 */
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
