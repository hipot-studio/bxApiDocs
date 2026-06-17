<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Api\Request\WorkflowTemplateService\PrepareParametersRequest;
use Bitrix\Bizproc\Api\Request\WorkflowStateService\GetAverageWorkflowDurationRequest;
use Bitrix\Bizproc\Api\Service\WorkflowTemplateService;
use Bitrix\Bizproc\Api\Service\WorkflowStateService;
use Bitrix\Bizproc\Internal\Service\Document\DocumentsResolver;
use Bitrix\Bizproc\Public\Service\Workflow\StarterService;
use Bitrix\Bizproc\Starter\Dto\ContextDto;
use Bitrix\Bizproc\Starter\Dto\DocumentDto;
use Bitrix\Bizproc\Starter\Dto\EventDto;
use Bitrix\Bizproc\Starter\Enum\Face;
use Bitrix\Bizproc\Starter\Starter;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;

class BizprocWorkflowStart extends \CBitrixComponent
{
	private const ERROR_CODE_EMPTY_MODULE_ID = 'empty_module_id';
	private const ERROR_CODE_EMPTY_ENTITY = 'empty_entity';
	private const ERROR_CODE_EMPTY_DOCUMENT_TYPE = 'empty_document_type';
	private const ERROR_CODE_EMPTY_DOCUMENT_ID = 'empty_document_id';
	private const ERROR_CODE_ACCESS_DENIED = 'access_denied';
	private const ERROR_CODE_REQUIRED_CONSTANTS = 'required_constants';
	private const ERROR_CODE_EMPTY_AUTOSTART_PARAMETERS = 'empty_autostart_parameters';
	private const ERROR_CODE_TEMPLATE_NOT_FOUND = 'template_not_found';
	private const ERROR_CODE_CONSTANTS_NOT_FOUND = 'constants_not_found';
	private const ERROR_CODE_EDIT_CONSTANTS_ACCESS_DENIED = 'edit_constants_access_denied';
	private const ERROR_CODE_START_WORKFLOW = 'StartWorkflowError';
	private const ERROR_CODE_CHECK_WORKFLOW_PARAMETERS = 'CheckWorkflowParameters';

	public function onPrepareComponentParams($arParams)
	{
		$request = Main\Application::getInstance()->getContext()->getRequest();

		$arParams['MODULE_ID'] = trim(
			empty($arParams['MODULE_ID']) ? ($request->get('module_id') ?? '') : $arParams['MODULE_ID']
		);
		$arParams['ENTITY'] = trim(empty($arParams['ENTITY']) ? ($request->get('entity') ?? '') : $arParams['ENTITY']);
		$arParams['DOCUMENT_TYPE'] = trim(
			empty($arParams['DOCUMENT_TYPE']) ? ($request->get('document_type') ?? '') : $arParams['DOCUMENT_TYPE']
		);
		$arParams['DOCUMENT_ID'] = trim(
			empty($arParams['DOCUMENT_ID']) ? ($request->get('document_id') ?? '') : $arParams['DOCUMENT_ID']
		);
		$arParams['TEMPLATE_ID'] =
			isset($arParams['TEMPLATE_ID'])
				? (int)$arParams['TEMPLATE_ID']
				: (int)($request->get('workflow_template_id') ?? 0)
		;
		$arParams['AUTO_EXECUTE_TYPE'] =
			isset($arParams['AUTO_EXECUTE_TYPE'])
				? (int)$arParams['AUTO_EXECUTE_TYPE']
				: null
		;
		$arParams['DOCUMENTS'] =
			is_array($arParams['DOCUMENTS'] ?? null)
				? $arParams['DOCUMENTS']
				: $request->get('documents')
		;
		if (!is_array($arParams['DOCUMENTS']))
		{
			$arParams['DOCUMENTS'] = [];
		}

		$arParams['SIGNED_DOCUMENTS'] =
			is_array($arParams['SIGNED_DOCUMENTS'] ?? null)
				? $arParams['SIGNED_DOCUMENTS']
				: ($arParams['signedDocuments'] ?? $request->get('signedDocuments'))
		;
		if (!is_array($arParams['SIGNED_DOCUMENTS']))
		{
			$arParams['SIGNED_DOCUMENTS'] = [];
		}

		$arParams['ACTION'] = $arParams['ACTION'] ?? null;

		$arParams['SET_TITLE'] = (($arParams['SET_TITLE'] ?? 'Y') === 'N' ? 'N' : 'Y');

		if (Main\Loader::includeModule('bizproc'))
		{
			$arParams['DOCUMENTS'] = ($arParams['DOCUMENTS'] !== [])
				? $this->normalizeDocuments($arParams['DOCUMENTS'])
				: $this->normalizeSignedDocuments($arParams['SIGNED_DOCUMENTS'])
			;

			if (is_string($arParams['SIGNED_DOCUMENT_TYPE'] ?? null) && $arParams['SIGNED_DOCUMENT_TYPE'])
			{
				$unsignedDocumentType = CBPDocument::unSignDocumentType(
					htmlspecialcharsback($arParams['SIGNED_DOCUMENT_TYPE'])
				);

				$arParams['MODULE_ID'] = $unsignedDocumentType ? $unsignedDocumentType[0] : '';
				$arParams['ENTITY'] = $unsignedDocumentType ? $unsignedDocumentType[1] : '';
				$arParams['DOCUMENT_TYPE'] = $unsignedDocumentType ? $unsignedDocumentType[2] : '';
			}

			if (is_string($arParams['SIGNED_DOCUMENT_ID'] ?? null) && $arParams['SIGNED_DOCUMENT_ID'])
			{
				$unsignedDocumentId = CBPDocument::unSignDocumentType(
					htmlspecialcharsback($arParams['SIGNED_DOCUMENT_ID'])
				);

				$arParams['DOCUMENT_ID'] = $unsignedDocumentId ? $unsignedDocumentId[2] : '';
			}

			$this->applyFirstDocumentToParams($arParams);
		}

		return $arParams;
	}

	public function executeComponent(): bool
	{
		$request = Main\Application::getInstance()->getContext()->getRequest();

		if (!Main\Loader::includeModule('bizproc'))
		{
			return false;
		}

		if ($this->getTemplateName() === 'slider')
		{
			$this->prepareSliderResult();

			if (isset($this->arResult['errors']))
			{
				$this->includeComponentTemplate('error');

				return false;
			}

			if ($this->isSingleStart())
			{
				if ($this->arResult['isConstantsTuned'] && !$this->arResult['hasParameters'])
				{
					$result = $this->startWorkflow(
						$this->arResult['template']['ID'],
						$this->getComplexDocumentType(),
						$this->getComplexDocumentId(),
					);

					$this->arResult['errors'] = $this->prepareErrorsForJs($result['errors']);
					$this->arResult['workflowId'] = $result['workflowId'];
				}

				$this->includeComponentTemplate('single_start');
			}
			else if($this->isConstantAction())
			{
				$this->includeComponentTemplate('edit_constants');
			}
			else
			{
				$this->includeComponentTemplate($this->isAutostart() ? 'autostart' : '');
			}

			return true;
		}

		$errors = $this->checkParams();
		if ($errors)
		{
			return $this->showErrorMessages($errors);
		}

		$this->arResult['DOCUMENT_ID'] = $this->arParams['DOCUMENT_ID'];
		$this->arResult['DOCUMENT_TYPE'] = $this->arParams['DOCUMENT_TYPE'];
		$this->arResult['back_url'] = trim((string)($request->get('back_url') ?? ''));

		if ($this->isAutostart())
		{
			$this->autoStartParametersAction((int)$this->arParams['AUTO_EXECUTE_TYPE']);

			return true;
		}

		$complexDocumentType = $this->getComplexDocumentType();
		$complexDocumentId = $this->getComplexDocumentId();

		$this->arParams['DOCUMENT_TYPE'] = $complexDocumentType;
		$this->arParams['DOCUMENT_ID'] = $complexDocumentId;
		$this->arParams['USER_GROUPS'] = $this->getUserGroupArray($complexDocumentType, $complexDocumentId);

		if ($this->arParams['SET_TITLE'] === 'Y')
		{
			$GLOBALS['APPLICATION']->SetTitle(Loc::getMessage('BPABS_TITLE'));
		}

		if (!$this->canUserStartWorkflowOnDocument($complexDocumentId))
		{
			return $this->showErrorMessages([$this->getErrorByCode(self::ERROR_CODE_ACCESS_DENIED)]);
		}

		if (!empty($request->get('cancel')) && !empty($this->arResult['back_url']))
		{
			LocalRedirect(str_replace('#WF#', '', $this->arResult['back_url']));
		}

		$this->arResult['SHOW_MODE'] = 'SelectWorkflow';
		$this->arResult['TEMPLATES'] = $this->getTemplatesForStart($complexDocumentType, $complexDocumentId);
		$this->arResult['PARAMETERS_VALUES'] = [];
		$this->arResult['ERROR_MESSAGE'] = '';

		$runtime = CBPRuntime::GetRuntime();
		$runtime->StartRuntime();
		$this->arResult['DocumentService'] = $runtime->GetService('DocumentService');

		$templateId = $this->arParams['TEMPLATE_ID'];
		if (
			$this->isSingleStart()
			&& empty($request->get('CancelStartParamWorkflow'))
			&& array_key_exists($templateId, $this->arResult['TEMPLATES'])
		)
		{
			$this->startParametersAction($templateId, $complexDocumentType, $complexDocumentId);

			return true;
		}

		$this->IncludeComponentTemplate();

		return true;
	}

	private function prepareSliderResult(): void
	{
		$errors = $this->checkParams();
		if ($errors)
		{
			$this->arResult = ['errors' => $errors];

			return;
		}

		if ($this->isSingleStart())
		{
			$complexDocumentType = $this->getComplexDocumentType();
			$complexDocumentId = $this->getComplexDocumentId();

			if (!$this->canUserStartWorkflowOnDocument($complexDocumentId))
			{
				$this->arResult = ['errors' => [$this->getErrorByCode(self::ERROR_CODE_ACCESS_DENIED)]];

				return;
			}

			$templateId = (int)$this->arParams['TEMPLATE_ID'];
			$triggerType = $this->arParams['TRIGGER_TYPE'] ?? null;

			$template = $this->getTemplateById($templateId, $complexDocumentType, $triggerType);
			if (!$template)
			{
				$this->arResult = ['errors' => [$this->getErrorByCode(self::ERROR_CODE_TEMPLATE_NOT_FOUND)]];

				return;
			}

			$workflowStateService = new WorkflowStateService();
			$averageDuration = $workflowStateService->getAverageWorkflowDuration(
				new GetAverageWorkflowDurationRequest($templateId)
			);

			$isConstantsTuned = CBPWorkflowTemplateLoader::isConstantsTuned($templateId);
			if (!$isConstantsTuned && !$this->canUserCreateWorkflowOnDocumentType($complexDocumentType))
			{
				unset($template['CONSTANTS']);
			}

			$this->arResult = [
				'template' => $template,
				'isConstantsTuned' => $isConstantsTuned,
				'hasParameters' =>  is_array($template['PARAMETERS'] ?? null) && $template['PARAMETERS'],
				'duration' => $averageDuration->isSuccess() ? $averageDuration->getRoundedAverageDuration() : null,
				'documentType' => $complexDocumentType,
				'signedDocumentType' => CBPDocument::signDocumentType($complexDocumentType),
				'signedDocumentId' => CBPDocument::signDocumentType($complexDocumentId),
				'triggerType' => $triggerType,
			];

			return;
		}

		if ($this->isAutostart())
		{
			$autostartData = $this->prepareAutostartData((int)$this->arParams['AUTO_EXECUTE_TYPE']);
			if (isset($autostartData['errorCode']))
			{
				$this->arResult = ['errors' => [$this->getErrorByCode($autostartData['errorCode'])]];

				return;
			}

			$this->arResult = [
				'templates' => $autostartData['templates'],
				'documents' => $autostartData['documents'],
				'autoExecuteType' => (int)$this->arParams['AUTO_EXECUTE_TYPE'],
			];

			return;
		}

		if ($this->isConstantAction())
		{
			$complexDocumentType = $this->getComplexDocumentType();

			if (!$this->canUserCreateWorkflowOnDocumentType($complexDocumentType))
			{
				$this->arResult = ['errors' => [$this->getErrorByCode(self::ERROR_CODE_EDIT_CONSTANTS_ACCESS_DENIED)]];

				return;
			}

			$templateId = (int)$this->arParams['TEMPLATE_ID'];
			$triggerType = $this->arParams['TRIGGER_TYPE'] ?? null;

			$template = $this->getTemplateById($templateId, $complexDocumentType, $triggerType);
			if (!$template)
			{
				$this->arResult = ['errors' => [$this->getErrorByCode(self::ERROR_CODE_TEMPLATE_NOT_FOUND)]];

				return;
			}

			if (empty($template['CONSTANTS']))
			{
				$this->arResult = ['errors' => [$this->getErrorByCode(self::ERROR_CODE_CONSTANTS_NOT_FOUND)]];

				return;
			}

			$this->arResult = [
				'template' => $template,
				'documentType' => $complexDocumentType,
				'signedDocumentType' => CBPDocument::signDocumentType($complexDocumentType),
			];

			return;
		}

		$this->arResult = ['errors' => [$this->getErrorByCode(self::ERROR_CODE_ACCESS_DENIED)]];
	}

	private function getTemplateById(int $templateId, array $complexDocumentType, ?string $triggerType = null): ?array
	{
		$filter = [
			'ID' => $templateId,
			'ACTIVE' => 'Y',
			'IS_SYSTEM' => 'N',
			'<AUTO_EXECUTE' => CBPDocumentEventType::Automation,
		];

		if (!$triggerType)
		{
			$filter['DOCUMENT_TYPE'] = $complexDocumentType;
		}

		$template = CBPWorkflowTemplateLoader::getList(
			[],
			$filter,
			false,
			false,
			['ID', 'NAME', 'DESCRIPTION', 'PARAMETERS', 'CONSTANTS'],
		)->fetch();

		return is_array($template) ? $template : null;
	}

	private function startParametersAction(int $templateId, array $complexDocumentType, array $complexDocumentId): void
	{
		$errors = [];

		$template = $this->arResult['TEMPLATES'][$templateId];
		$hasParameters = is_array($template['PARAMETERS']) && $template['PARAMETERS'];
		$canStartWorkflow = !$hasParameters;

		$parameters = [];
		if ($hasParameters && $this->isDoStartParamWorkflowAction())
		{
			['errors' => $errors, 'parameters' => $parameters] =
				$this->prepareStartParametersFromRequest($template['PARAMETERS'], $complexDocumentType)
			;
			$canStartWorkflow = !$errors;
		}

		$isConstantsTuned = CBPWorkflowTemplateLoader::isConstantsTuned($templateId);
		if (!$isConstantsTuned)
		{
			$errors[] = $this->getErrorByCode(self::ERROR_CODE_REQUIRED_CONSTANTS);
			$canStartWorkflow = false;
		}

		if ($canStartWorkflow)
		{
			$startResult = $this->startWorkflow($templateId, $complexDocumentType, $complexDocumentId, $parameters);
			if ($startResult['errors'])
			{
				$this->arResult['SHOW_MODE'] = 'StartWorkflowError';
				$errors = array_merge($errors, $startResult['errors']);
			}
			else
			{
				$this->arResult['SHOW_MODE'] = 'StartWorkflowSuccess';
				if (!empty($this->arResult['back_url']))
				{
					LocalRedirect(str_replace('#WF#', $startResult['workflowId'], $this->arResult['back_url']));
				}
			}
		}
		else
		{
			$this->arResult['PARAMETERS_VALUES'] = $this->restoreWorkflowStartParameters($template['PARAMETERS']);
			$this->arResult['SHOW_MODE'] = $isConstantsTuned ? 'WorkflowParameters' : 'StartWorkflowError';
		}

		if ($errors)
		{
			$this->arResult['ERROR_MESSAGE'] = $this->createErrorMessage($errors);
		}

		$this->IncludeComponentTemplate();
	}

	private function prepareStartParametersFromRequest(array $templateParameters, array $complexDocumentType): array
	{
		$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();

		$response =
			(new WorkflowTemplateService())
				->prepareParameters(
					new PrepareParametersRequest(
						templateParameters: $templateParameters,
						requestParameters: array_merge($request->toArray(), $request->getFileList()->toArray()),
						complexDocumentType: $complexDocumentType,
					)
			)
		;

		$errors = [];
		if (!$response->isSuccess())
		{
			foreach ($response->getErrors() as $error)
			{
				$errors[] = $this->createCheckWorkflowParametersError($error->jsonSerialize());
			}
		}

		return ['errors' => $errors, 'parameters' => $response->getParameters()];
	}

	private function getTemplatesForStart(array $complexDocumentType, array $complexDocumentId): array
	{
		// todo: use?
		// CBPDocument::getTemplatesForStart(
		// 	$this->getCurrentUserId(),
		// 	$complexDocumentType,
		// 	$complexDocumentId,
		// 	['UserGroups' => $this->arParams['USER_GROUPS'] ?? $this->getUserGroupArray()],
		// );

		$dbWorkflowTemplate = CBPWorkflowTemplateLoader::getList(
			['SORT' => 'ASC', 'NAME' => 'ASC'],
			[
				'DOCUMENT_TYPE' => $complexDocumentType,
				'ACTIVE' => 'Y',
				'IS_SYSTEM' => 'N',
				'<AUTO_EXECUTE' => CBPDocumentEventType::Automation,
			],
			false,
			false,
			['ID', 'NAME', 'DESCRIPTION', 'MODIFIED', 'USER_ID', 'PARAMETERS', 'AUTO_EXECUTE']
		);

		$templates = [];
		while ($template = $dbWorkflowTemplate->GetNext())
		{
			$templates[$template['ID']] = $template;
			$templates[$template['ID']]['URL'] = htmlspecialcharsbx(
				$GLOBALS['APPLICATION']->GetCurPageParam(
					'workflow_template_id=' . $template['ID'] . '&' . bitrix_sessid_get(),
					['workflow_template_id', 'sessid']
				)
			);
		}

		if ($templates && mb_strtolower((string)($complexDocumentType[0] ?? '')) === 'webdav')
		{
			return $this->filterTemplatesByStartWorkflowAccess($templates, $complexDocumentType, $complexDocumentId);
		}

		return $templates;
	}

	private function filterTemplatesByStartWorkflowAccess(
		array $templates,
		array $complexDocumentType,
		array $complexDocumentId
	): array
	{
		$states = CBPDocument::GetDocumentStates($complexDocumentType, $complexDocumentId);

		$result = [];
		foreach ($templates as $key => $template)
		{
			$checkAccessParameters = ['WorkflowTemplateId' => $key, 'DocumentStates' => $states];
			if ($this->canUserStartWorkflowOnDocument($complexDocumentId, $checkAccessParameters))
			{
				$result[$key] = $template;
			}
		}

		return $result;
	}

	private function startWorkflow(
		int $templateId,
		array $complexDocumentType,
		array $complexDocumentId,
		array $workflowParameters = []
	): array
	{
		$starter = $this->getStarter($templateId, $complexDocumentType, $complexDocumentId, $workflowParameters);
		$starter->setValidateParameters(false);
		$result = $starter->start();
		$errors = [];

		if (!$result->isSuccess())
		{
			foreach ($result->getErrors() as $error)
			{
				$errors[] = $this->createStartWorkflowError($error->jsonSerialize());
			}
		}

		$workflowIds = $result->getWorkflowIds();

		return ['errors' => $errors, 'workflowId' => $workflowIds[0] ?? null];
	}

	private function getStarter(
		int $templateId,
		array $complexDocumentType,
		array $complexDocumentId,
		array $workflowParameters
	): Starter
	{
		$currentUserId = $this->getCurrentUserId();
		$triggerType = $this->arParams['TRIGGER_TYPE'] ?? null;

		$context = new ContextDto('bizproc', Face::WEB);

		if ($triggerType)
		{
			return (new StarterService())->getStarterForManualEventScenario(
				templateIds: [$templateId],
				context: $context,
				events: [
					new EventDto(
						code: $triggerType,
						documents: [new DocumentDto($complexDocumentId, $complexDocumentType)],
						eventType: CBPDocumentEventType::Manual,
						userId: $currentUserId,
					),
				],
				userId: $currentUserId,
				parameters: $workflowParameters,
			);
		}

		return (new StarterService())->getStarterForManualDocumentScenario(
			templateIds: [$templateId],
			context: $context,
			document: new DocumentDto(
				complexDocumentId: $complexDocumentId,
				complexDocumentType: $complexDocumentType,
			),
			userId: $currentUserId,
			parameters: $workflowParameters,
		);
	}

	private function restoreWorkflowStartParameters(array $templateParameters): array
	{
		$hasParametersInRequest = $this->isDoStartParamWorkflowAction();
		$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();

		$restored = [];
		foreach ($templateParameters as $key => $property)
		{
			$restored[$key] = $this->convertParameterValues(
				$hasParametersInRequest ? $request->get($key) : $property['Default']
			);
		}

		return $restored;
	}

	private function isDoStartParamWorkflowAction(): bool
	{
		$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();

		return $request->isPost() && !empty($request->get('DoStartParamWorkflow'));
	}

	private function isAutostart(): bool
	{
		return $this->arParams['AUTO_EXECUTE_TYPE'] !== null;
	}

	private function isSingleStart(): bool
	{
		return $this->arParams['TEMPLATE_ID'] > 0 && $this->arParams['ACTION'] === null;
	}

	private function isConstantAction(): bool
	{
		return $this->arParams['TEMPLATE_ID'] > 0 &&  $this->arParams['ACTION'] === 'CHANGE_CONSTANTS';
	}

	protected function autoStartParametersAction(int $execType): bool
	{
		$autostartData = $this->prepareAutostartData($execType);
		if (isset($autostartData['errorCode']))
		{
			return $this->showErrorMessages([$this->getErrorByCode($autostartData['errorCode'])]);
		}

		$this->arResult['TEMPLATES'] = $autostartData['templates'];
		$this->arResult['DOCUMENTS'] = $autostartData['documents'];
		$this->arResult['DOCUMENT_TYPE'] = $autostartData['documents'][0]['documentType'] ?? [];
		$this->arResult['DOCUMENT_ID'] = $autostartData['documents'][0]['documentId'] ?? [];
		$this->arParams['DOCUMENT_TYPE'] = $this->arResult['DOCUMENT_TYPE'];
		$this->arParams['DOCUMENT_ID'] = $this->arResult['DOCUMENT_ID'];

		$runtime = CBPRuntime::GetRuntime();
		$runtime->StartRuntime();
		$this->arResult['DocumentService'] = $runtime->GetService('DocumentService');
		$this->arResult['EXEC_TYPE'] = $execType;

		$this->IncludeComponentTemplate('autostart');

		return true;
	}

	private function prepareAutostartData(int $execType): array
	{
		$documents = $this->getAutostartDocuments();
		if (empty($documents))
		{
			return ['errorCode' => self::ERROR_CODE_ACCESS_DENIED];
		}

		$accessibleDocuments = [];
		$templatesById = [];
		foreach ($documents as $document)
		{
			$documentType = $document['documentType'] ?? null;
			if (!is_array($documentType))
			{
				continue;
			}

			$documentId = (isset($document['documentId']) && is_array($document['documentId']))
				? $document['documentId']
				: null
			;
			$documentStates = CBPWorkflowTemplateLoader::getDocumentTypeStates($documentType, $execType);
			$userGroups = $this->getUserGroupsForAutostartDocument($documentType, $documentId);

			if (!$this->canStartAutostartForDocument($documentType, $documentId, $documentStates, $userGroups))
			{
				continue;
			}

			$accessibleDocuments[] = [
				'documentType' => $documentType,
				'documentId' => $documentId,
			];

			foreach ($this->getTemplatesWithParametersFromStates($documentStates, $documentType) as $template)
			{
				$templatesById[$template['ID']] ??= $template;
			}
		}

		if (empty($accessibleDocuments))
		{
			return ['errorCode' => self::ERROR_CODE_ACCESS_DENIED];
		}

		$templates = array_values($templatesById);
		if (empty($templates))
		{
			return ['errorCode' => self::ERROR_CODE_EMPTY_AUTOSTART_PARAMETERS];
		}

		return [
			'documents' => $accessibleDocuments,
			'templates' => $templates,
		];
	}

	private function getTemplatesWithParametersFromStates(array $documentStates, array $documentType): array
	{
		$templates = [];
		foreach ($documentStates as $template)
		{
			if (!is_array($template['TEMPLATE_PARAMETERS']) || !$template['TEMPLATE_PARAMETERS'])
			{
				continue;
			}

			$templates[] = [
				'ID' => $template['TEMPLATE_ID'],
				'NAME' => $template['TEMPLATE_NAME'],
				'DESCRIPTION' => $template['TEMPLATE_DESCRIPTION'],
				'DOCUMENT_TYPE' => $documentType,
				'PARAMETERS' => $this->getTemplateParametersFromState($template),
			];
		}

		return $templates;
	}

	private function getTemplateParametersFromState(array $template): array
	{
		$parameters = [];
		foreach ($template['TEMPLATE_PARAMETERS'] as $parameterKey => $parameter)
		{
			if ($parameterKey === 'TargetUser')
			{
				continue;
			}

			$parameter['Default'] = $this->convertParameterValues($parameter['Default']);
			$parameters["bizproc{$template['TEMPLATE_ID']}_{$parameterKey}"] = $parameter;
		}

		return $parameters;
	}

	private function convertParameterValues(mixed $values): mixed
	{
		if (!is_array($values))
		{
			return CBPHelper::convertParameterValues($values);
		}

		$convertedValues = [];
		foreach ($values as $key => $value)
		{
			$convertedValues[$key] = CBPHelper::convertParameterValues($value);
		}

		return $convertedValues;
	}

	private function checkParams(): array
	{
		$errors = [];

		if (empty($this->arParams['MODULE_ID']))
		{
			$errors[] = $this->getErrorByCode(self::ERROR_CODE_EMPTY_MODULE_ID);
		}

		if (empty($this->arParams['ENTITY']))
		{
			$errors[] = $this->getErrorByCode(self::ERROR_CODE_EMPTY_ENTITY);
		}

		if (empty($this->arParams['DOCUMENT_TYPE']))
		{
			$errors[] = $this->getErrorByCode(self::ERROR_CODE_EMPTY_DOCUMENT_TYPE);
		}

		if (
			empty($this->arParams['DOCUMENT_ID'])
			&& ($this->arParams['AUTO_EXECUTE_TYPE'] === null && $this->arParams['ACTION'] === null)
		)
		{
			$errors[] = $this->getErrorByCode(self::ERROR_CODE_EMPTY_DOCUMENT_ID);
		}

		if ($this->arParams['AUTO_EXECUTE_TYPE'] === null && !check_bitrix_sessid())
		{
			$errors[] = $this->getErrorByCode(self::ERROR_CODE_ACCESS_DENIED);
		}

		return $errors;
	}

	private function getUserGroupArray(array $complexDocumentType, array $complexDocumentId): array
	{
		$userGroups = CBPDocument::getUserGroups(
			$complexDocumentType,
			$complexDocumentId,
			$this->getCurrentUserId()
		);

		if (is_array($userGroups))
		{
			return $userGroups;
		}

		return Main\Engine\CurrentUser::get()->getUserGroups();
	}

	private function canUserStartWorkflowOnDocument(array $complexDocumentId, array $parameters = []): bool
	{
		if (empty($complexDocumentId[2]))
		{
			return false;
		}

		if (!isset($parameters['UserGroups']))
		{
			$parameters['UserGroups'] = $this->arParams['USER_GROUPS'] ?? [];
		}

		return CBPDocument::canUserOperateDocument(
			CBPCanUserOperateOperation::StartWorkflow,
			$this->getCurrentUserId(),
			$complexDocumentId,
			$parameters
		);
	}

	private function canUserStartWorkflowOnDocumentType(array $complexDocumentType, array $parameters = []): bool
	{
		if (!isset($parameters['UserGroups']))
		{
			$parameters['UserGroups'] = $this->arParams['USER_GROUPS'] ?? [];
		}

		return CBPDocument::canUserOperateDocumentType(
			CBPCanUserOperateOperation::StartWorkflow,
			$this->getCurrentUserId(),
			$complexDocumentType,
			$parameters
		);
	}

	private function canUserCreateWorkflowOnDocumentType(array $complexDocumentType): bool
	{
		return CBPDocument::canUserOperateDocumentType(
			CBPCanUserOperateOperation::CreateWorkflow,
			$this->getCurrentUserId(),
			$complexDocumentType,
		);
	}

	private function getComplexDocumentType(): array
	{
		return $this->getComplexDocumentTypeOrNull() ?? [];
	}

	private function getComplexDocumentId(): array
	{
		return $this->getComplexDocumentIdOrNull() ?? [];
	}

	private function getComplexDocumentTypeOrNull(): ?array
	{
		if (is_array($this->arParams['DOCUMENT_TYPE'] ?? null))
		{
			return $this->arParams['DOCUMENT_TYPE'];
		}

		if (
			empty($this->arParams['MODULE_ID'])
			|| empty($this->arParams['ENTITY'])
			|| empty($this->arParams['DOCUMENT_TYPE'])
		)
		{
			return null;
		}

		return [$this->arParams['MODULE_ID'], $this->arParams['ENTITY'], $this->arParams['DOCUMENT_TYPE']];
	}

	private function getComplexDocumentIdOrNull(): ?array
	{
		if (is_array($this->arParams['DOCUMENT_ID'] ?? null))
		{
			return $this->arParams['DOCUMENT_ID'];
		}

		if (
			empty($this->arParams['MODULE_ID'])
			|| empty($this->arParams['ENTITY'])
			|| empty($this->arParams['DOCUMENT_ID'])
		)
		{
			return null;
		}

		return [$this->arParams['MODULE_ID'], $this->arParams['ENTITY'], $this->arParams['DOCUMENT_ID']];
	}

	private function getAutostartDocuments(): array
	{
		$documents = $this->arParams['DOCUMENTS'] ?? [];
		if (!empty($documents))
		{
			return $documents;
		}

		$documentType = $this->getComplexDocumentTypeOrNull();
		if ($documentType === null)
		{
			return [];
		}

		return [[
			'documentType' => $documentType,
			'documentId' => $this->getComplexDocumentIdOrNull(),
		]];
	}

	private function canStartAutostartForDocument(
		array $documentType,
		?array $documentId,
		array $documentStates,
		array $userGroups,
	): bool
	{
		if (
			$documentId !== null
			&& $this->canUserStartWorkflowOnDocument(
				$documentId,
				[
					'UserGroups' => $userGroups,
				],
			)
		)
		{
			return true;
		}

		return $this->canUserStartWorkflowOnDocumentType(
			$documentType,
			[
				'UserGroups' => $userGroups,
				'DocumentStates' => $documentStates,
			],
		);
	}

	private function getUserGroupsForAutostartDocument(array $documentType, ?array $documentId): array
	{
		if ($documentId !== null && !empty($documentId[2]))
		{
			return $this->getUserGroupArray($documentType, $documentId);
		}

		return Main\Engine\CurrentUser::get()->getUserGroups();
	}

	private function normalizeDocuments(mixed $documents): array
	{
		if (!is_array($documents))
		{
			return [];
		}

		return $this->normalizeResolvedDocuments(['documents' => $documents]);
	}

	private function normalizeSignedDocuments(mixed $documents): array
	{
		if (!is_array($documents))
		{
			return [];
		}

		return $this->normalizeResolvedDocuments(['signedDocuments' => $documents]);
	}

	private function normalizeResolvedDocuments(array $payload): array
	{
		$result = [];
		foreach ($this->resolveDocumentsFromPayload($payload) as $resolvedDocument)
		{
			$complexDocumentType = $resolvedDocument->complexDocumentType;
			$result[$complexDocumentType->getKey()] = [
				'documentType' => $complexDocumentType->toArray(),
				'documentId' => $resolvedDocument->complexDocumentId?->toArray(),
			];
		}

		return array_values($result);
	}

	private function resolveDocumentsFromPayload(array $payload): array
	{
		$result = (new DocumentsResolver())->resolveUniqueDocumentTypesFromPayload($payload);
		if (!$result->isSuccess())
		{
			return [];
		}

		return $result->getDocuments()?->documents ?? [];
	}

	private function applyFirstDocumentToParams(array &$arParams): void
	{
		$firstDocument = $arParams['DOCUMENTS'][0] ?? null;
		if (!is_array($firstDocument))
		{
			return;
		}

		$documentType = $firstDocument['documentType'] ?? null;
		if (is_array($documentType))
		{
			$arParams['MODULE_ID'] = (string)($documentType[0] ?? '');
			$arParams['ENTITY'] = (string)($documentType[1] ?? '');
			$arParams['DOCUMENT_TYPE'] = (string)($documentType[2] ?? '');
		}

		$documentId = $firstDocument['documentId'] ?? null;
		if (is_array($documentId))
		{
			$arParams['DOCUMENT_ID'] = (string)($documentId[2] ?? '');
		}
	}

	private function showErrorMessages(array $errors): bool
	{
		ShowError($this->createErrorMessage($errors));

		return false;
	}

	private function createErrorMessage(array $errors): string
	{
		return (new CAdminException($errors))->GetString();
	}

	private function getErrorByCode(string $code): array
	{
		$text = match ($code)
		{
			self::ERROR_CODE_EMPTY_MODULE_ID => Loc::getMessage('BPATT_NO_MODULE_ID'),
			self::ERROR_CODE_EMPTY_ENTITY => Loc::getMessage('BPABS_EMPTY_ENTITY'),
			self::ERROR_CODE_EMPTY_DOCUMENT_TYPE => Loc::getMessage('BPABS_EMPTY_DOC_TYPE'),
			self::ERROR_CODE_EMPTY_DOCUMENT_ID => Loc::getMessage('BPABS_EMPTY_DOC_ID'),
			self::ERROR_CODE_ACCESS_DENIED => Loc::getMessage('BIZPROC_CMP_WORKFLOW_START_TEMPLATE_NO_PERMISSIONS'),
			self::ERROR_CODE_REQUIRED_CONSTANTS => Loc::getMessage('BPABS_REQUIRED_CONSTANTS'),
			self::ERROR_CODE_EMPTY_AUTOSTART_PARAMETERS => Loc::getMessage('BPABS_NO_AUTOSTART_PARAMETERS'),
			self::ERROR_CODE_TEMPLATE_NOT_FOUND => Loc::getMessage('BIZPROC_CMP_WORKFLOW_START_TEMPLATE_NOT_FOUND') ?? '',
			self::ERROR_CODE_CONSTANTS_NOT_FOUND => Loc::getMessage('BIZPROC_CMP_WORKFLOW_START_CONSTANTS_NOT_FOUND'),
			self::ERROR_CODE_EDIT_CONSTANTS_ACCESS_DENIED => Loc::getMessage('BIZPROC_CMP_WORKFLOW_START_CONSTANTS_ACCESS_DENIED'),
			default => '',
		};

		if ($code === self::ERROR_CODE_EMPTY_AUTOSTART_PARAMETERS)
		{
			$code = self::ERROR_CODE_ACCESS_DENIED; // compatibility
		}

		return $this->createError($code, $text);
	}

	private function createStartWorkflowError(array $error): array
	{
		$message = ($error['code'] > 0 ? '[' . $error['code'] . '] ' : '') . $error['message'];

		return $this->createError(self::ERROR_CODE_START_WORKFLOW, $message);
	}

	private function createCheckWorkflowParametersError(array $error): array
	{
		return $this->createError(self::ERROR_CODE_CHECK_WORKFLOW_PARAMETERS, $error['message']);
	}

	private function createError(string $code, string $message): array
	{
		return ['id' => $code, 'text' => $message];
	}

	private function prepareErrorsForJs(array $errors): array
	{
		$preparedErrors = [];
		foreach ($errors as $error)
		{
			$preparedErrors[] = [
				'message' => $error['text'],
			];
		}

		return $preparedErrors;
	}

	private function getCurrentUserId(): int
	{
		return (int)(Main\Engine\CurrentUser::get()->getId());
	}
}
