<?php

namespace Bitrix\Bizproc\Api\Service;

use Bitrix\Bizproc\Api\Data\WorkflowTemplateService\WorkflowTemplate;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Api\Enum\Template\TemplatePublicationType;
use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateHistoryService\RecordChangeRequest;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateHistoryService\RecordPublicationRequest;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateService as WorkflowTemplateRequest;
use Bitrix\Bizproc\Api\Response\Error;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateHistoryService\RecordPublicationResponse;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateService as WorkflowTemplateResponse;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Config\TemplateHistory;
use Bitrix\Bizproc\Internal\Entity\WorkflowTemplate\VersionChoice;
use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\WorkflowTemplateRepository;
use Bitrix\Bizproc\Workflow\Template\AgentOwnedMetadata;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable;
use Bitrix\Bizproc\Internal\Access\Permission\PermissionDictionary;
use Bitrix\Bizproc\Internal\Service\Pilot\CommonRevisionWriter;
use Bitrix\Bizproc\Internal\Service\Pilot\SettingsFreezeGate;
use Bitrix\Bizproc\Internal\Service\Pilot\StartFormParameters;
use Bitrix\Bizproc\Internal\Service\Trigger\Schedule\ScheduledTriggerSyncService;
use Bitrix\Bizproc\Internal\Service\WorkflowTemplate\PublishTemplateConstantsAccessPolicy;
use Bitrix\Bizproc\Internal\Service\WorkflowTemplate\TemplateConstantsAccessPolicyInterface;
use Bitrix\Bizproc\Internal\Service\WorkflowTemplate\TemplatePersistAccessService;
use Bitrix\Bizproc\Internal\Service\ProcessData\ProcessDataFeature;
use Bitrix\Bizproc\Public\ProcessData\Service\ProcessDataMechanicsResolver;
use Bitrix\Bizproc\Public\Provider\PilotVisibilityProvider;
use Bitrix\Bizproc\Public\Provider\TemplateAccessProvider;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Repository\Exception\PersistenceException;

class WorkflowTemplateService
{
	private const TRACK_ON_INTERVAL = 7 * 86400; // 7 days in seconds

	// A draft born of the editor counts from one: zero is reserved for the drafts written past it, where
	// there is nothing to compare a revision against.
	private const FIRST_DRAFT_REVISION = 1;
	private const JOURNALED_STATE_FIELDS = [
		'TEMPLATE',
		'NAME',
		'DESCRIPTION',
		'PARAMETERS',
		'VARIABLES',
		'CONSTANTS',
	];
	private WorkflowAccessService $accessService;
	private TemplateAccessService $templateAccessService;
	private TemplateAccessProvider $templateAccessProvider;
	private WorkflowTemplateHistoryService $historyService;
	private SettingsFreezeGate $settingsFreezeGate;
	private StartFormParameters $startFormParameters;
	private PilotVisibilityProvider $pilotVisibilityProvider;
	private CommonRevisionWriter $commonRevisionWriter;
	private TemplatePersistAccessService $templatePersistAccessService;
	private TemplateConstantsAccessPolicyInterface $templateConstantsAccessPolicy;

	public function __construct(
		?WorkflowAccessService $accessService = null,
		?TemplateAccessService $templateAccessService = null,
		?TemplateAccessProvider $templateAccessProvider = null,
		?WorkflowTemplateHistoryService $historyService = null,
		?SettingsFreezeGate $settingsFreezeGate = null,
		?StartFormParameters $startFormParameters = null,
		?PilotVisibilityProvider $pilotVisibilityProvider = null,
		?CommonRevisionWriter $commonRevisionWriter = null,
		?TemplatePersistAccessService $templatePersistAccessService = null,
		?TemplateConstantsAccessPolicyInterface $templateConstantsAccessPolicy = null,
	)
	{
		$this->accessService = $accessService ?? new WorkflowAccessService();
		$this->templateAccessService = $templateAccessService ?? new TemplateAccessService();
		$this->templateAccessProvider = $templateAccessProvider ?? new TemplateAccessProvider();
		$this->historyService = $historyService ?? new WorkflowTemplateHistoryService();
		$this->settingsFreezeGate = $settingsFreezeGate ?? new SettingsFreezeGate();
		$this->startFormParameters = $startFormParameters ?? new StartFormParameters();
		$this->pilotVisibilityProvider = $pilotVisibilityProvider ?? new PilotVisibilityProvider();
		$this->commonRevisionWriter = $commonRevisionWriter ?? new CommonRevisionWriter();
		$this->templatePersistAccessService = $templatePersistAccessService
			?? new TemplatePersistAccessService(
				$this->accessService,
				$this->templateAccessService,
				$this->historyService,
			);
		$this->templateConstantsAccessPolicy = $templateConstantsAccessPolicy
			?? new PublishTemplateConstantsAccessPolicy($this->templatePersistAccessService);
	}

	/**
	 * Persist gate for the live-save / draft-save / constants paths. An existing template is writable only
	 * through its own document type; on top of that Nodes templates are governed by the template ACL and
	 * split into publish (activation of the live row) and edit (draft), while every other type and a new
	 * template stay on the legacy document-type CreateWorkflow gate.
	 */
	private function canPersist(int $templateId, array $documentType, int $userId, bool $publish): bool
	{
		return $this->templatePersistAccessService->canPersist($templateId, $documentType, $userId, $publish);
	}

	/**
	 * Import / export gate: the legacy CreateWorkflow right, and only on a document type the template
	 * really belongs to. A request carrying another one reaches into a foreign entity.
	 */
	private function canTransfer(int $templateId, array $documentType, int $userId): bool
	{
		$template = $this->getTemplateAccessInfo($templateId);
		if ($template !== null && !\CBPHelper::isEqualDocument($template['documentType'], $documentType))
		{
			return false;
		}

		return $this->accessService->canCreateWorkflow($documentType, $userId);
	}

	/**
	 * The gates of one save ask about the same template row, so it is read once. The memory is dropped as
	 * soon as the row is overwritten: a save can change both the type of the template and its document type.
	 *
	 * @return array{documentType: string[], isNodes: bool, inHistory: bool}|null null when there is no such
	 * template row
	 */
	private function getTemplateAccessInfo(int $templateId): ?array
	{
		return $this->templatePersistAccessService->getTemplateAccessInfo($templateId);
	}

	private function isNewNodesTemplate(array $fields, array $documentType): bool
	{
		$fields['DOCUMENT_TYPE'] = $documentType;

		return \CBPWorkflowTemplateLoader::getLoader()->getTemplateType($fields)
			=== WorkflowTemplateType::Nodes->value;
	}

	/**
	 * The PROCESS_DATA marker belongs to the server: whatever a client sent under that name is dropped, and
	 * the marker is put back only for a nodes template being created while the feature is on. It is set once,
	 * at birth, and never removed - an existing template can neither gain it nor lose it here. The check does
	 * not depend on checkAccess: the designer authorizes the creation in the controller and creates the row
	 * with the check off.
	 */
	private function applyProcessDataMarker(int $templateId, WorkflowTemplate $template): array
	{
		$fields = $template->getFields();
		$settings = is_array($fields['TEMPLATE_SETTINGS'] ?? null) ? $fields['TEMPLATE_SETTINGS'] : [];
		unset($settings[ProcessDataMechanicsResolver::MARKER]);

		if (
			$templateId <= 0
			&& ProcessDataFeature::isEnabled()
			&& $this->isNewNodesTemplate($fields, $template->getDocumentType())
		)
		{
			$settings[ProcessDataMechanicsResolver::MARKER] = ProcessDataMechanicsResolver::MARKER_VALUE;
		}

		$fields['TEMPLATE_SETTINGS'] = $settings;

		ProcessDataMechanicsResolver::clearMarkerCache($templateId);

		return $fields;
	}

	/**
	 * The type of a row decides which ACL owns it: a Nodes template answers to the template ACL, everything
	 * else falls back onto the legacy CreateWorkflow gate. The type is derived from TEMPLATE and AUTO_EXECUTE,
	 * and this path may write both, so without the refusal the holder of a per-template right could publish
	 * once with another root and take its own row out of that ACL for good.
	 *
	 * The save is refused rather than half-applied: the product offers no transition out of the node editor, so
	 * keeping the type while storing a foreign graph would leave the row with a type its own content denies.
	 * Only requests that asked for the access check are measured - internal callers keep writing through, and
	 * conversion by import stays where it was, on the legacy right of the document type.
	 */
	private function takesTemplateOutOfNodes(int $templateId, array $fields): bool
	{
		if (!($this->getTemplateAccessInfo($templateId)['isNodes'] ?? false))
		{
			return false;
		}

		$computedType = \CBPWorkflowTemplateLoader::getLoader()->getTemplateType($fields, $templateId);

		return $computedType !== null && $computedType !== WorkflowTemplateType::Nodes->value;
	}

	public function getList(
		WorkflowTemplateRequest\GridTemplateRequest $request,
	): WorkflowTemplateResponse\GridTemplateResponse
	{
		$query = WorkflowTemplateTable::query()
			->setSelect([
				'ID',
				'NAME',
				'DESCRIPTION',
				'UPDATED_BY',
				'CREATED_BY',
				'MODIFIED',
				'AUTO_EXECUTE',
				'UPDATED_USER',
				'CREATED_USER',
				'USER',
			])
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->whereNull('SYSTEM_CODE')
			->setOrder($request->getOrder())
			->setLimit($request->getLimit())
			->countTotal(true)
		;

		if ($request->hasOffset())
		{
			$query->setOffset($request->getOffset());
		}

		$filters = $request->getOrmFilter();
		if ($filters)
		{
			$query->setFilter($filters);
		}

		// The line of search of the header narrows what the filter already asks for: where() is a separate
		// handler joined with AND, so the search stays conjunctive with the conditions of the filter.
		$searchQuery = (string)$request->getFilterSearchQuery();
		if ($searchQuery !== '')
		{
			$query->where(
				Query::filter()
					->logic('or')
					->whereLike('NAME', "%$searchQuery%")
					->whereLike('DESCRIPTION', "%$searchQuery%"),
			);
		}

		// Visibility scope by the caller's edit area (replaces the CREATED_BY=self filter). Applied after
		// setFilter() so it is not overwritten; fail-closed for users without an edit scope.
		$entityFilter = $this->templateAccessProvider->getEntityFilter(
			$request->getFilterUserId(),
			PermissionDictionary::BIZPROC_TEMPLATE_EDIT,
		);
		foreach ($entityFilter as $filterKey => $filterValue)
		{
			// The area may arrive as a nested ConditionTree, which the array filter cannot carry: setFilterChains()
			// only recurses into arrays and would hand the object over to CSQLWhere. where() is a separate
			// handler joined with AND, so the restrictions above stay conjunctive with the area.
			if ($filterKey === TemplateAccessProvider::SCOPE_CONDITION_KEY)
			{
				$query->where($filterValue);

				continue;
			}

			$query->addFilter($filterKey, $filterValue);
		}

		$queryResult = $query->exec();
		$response = new WorkflowTemplateResponse\GridTemplateResponse();
		$response->setTotalCount($queryResult->getCount());
		$response->setCollection($queryResult->fetchCollection());

		return $response;
	}

	public function prepareParameters(
		WorkflowTemplateRequest\PrepareParametersRequest $request,
	): WorkflowTemplateResponse\PrepareParametersResponse
	{
		try
		{
			\CBPHelper::parseDocumentId($request->complexDocumentType);
		}
		catch (\CBPArgumentNullException $e)
		{
			return WorkflowTemplateResponse\PrepareParametersResponse::createError(
				\Bitrix\Bizproc\Error::createFromThrowable($e),
			);
		}

		$parameters = [];
		foreach ($request->templateParameters as $key => $property)
		{
			$value = $request->requestParameters[$key] ?? null;

			if ($property['Type'] === FieldType::FILE)
			{
				if (!empty($value) && isset($value['name']))
				{
					$parameters[$key] = $value;
					if (is_array($value['name']))
					{
						$parameters[$key] = [];
						\CFile::ConvertFilesToPost($value, $parameters[$key]);
					}
				}
				elseif ($this->isCorrectSignedFileIds($value))
				{
					$parameters[$key] = $value;
				}

				continue;
			}

			$parameters[$key] = $value;
		}

		$errors = [];
		$response
			= (new WorkflowTemplateResponse\PrepareParametersResponse())
				->setRawParameters($parameters)
				->setParameters(
					\CBPWorkflowTemplateLoader::checkWorkflowParameters(
						$request->templateParameters, $parameters, $request->complexDocumentType, $errors,
					),
				)
		;

		if ($errors)
		{
			foreach ($errors as $error)
			{
				// the field key travels to the client: the start form moves the focus to the first
				// control with an error and binds the error message to it
				$customData = isset($error['parameter']) ? ['parameter' => $error['parameter']] : null;

				$response->addError(new \Bitrix\Bizproc\Error($error['message'], $error['code'], $customData));
			}
		}

		return $response;
	}

	public function setConstants(
		WorkflowTemplateRequest\SetConstantsRequest $request,
	): WorkflowTemplateResponse\SetConstantsResponse
	{
		if ($request->templateId <= 0)
		{
			return WorkflowTemplateResponse\SetConstantsResponse::createError(new Error('negative template id'));
		}

		if ($request->userId <= 0)
		{
			return WorkflowTemplateResponse\SetConstantsResponse::createError(new Error('negative user id'));
		}

		try
		{
			\CBPHelper::parseDocumentId($request->complexDocumentType);
		}
		catch (\CBPArgumentNullException $e)
		{
			return WorkflowTemplateResponse\SetConstantsResponse::createError(Error::createFromThrowable($e));
		}

		if (
			!$this->templateConstantsAccessPolicy->canWrite(
				$request->templateId,
				$request->complexDocumentType,
				$request->userId,
			)
		)
		{
			return WorkflowTemplateResponse\SetConstantsResponse::createError(new Error('access denied'));
		}

		// Check before parsing uploads, while the loader repeats the authoritative check under the row lock.
		$freezeRefusal = $this->settingsFreezeGate->findRefusal($request->templateId);
		if ($freezeRefusal !== null)
		{
			return WorkflowTemplateResponse\SetConstantsResponse::createError($freezeRefusal);
		}

		$constants = \CBPWorkflowTemplateLoader::getTemplateConstants($request->templateId);
		if (!is_array($constants) || !$constants)
		{
			return WorkflowTemplateResponse\SetConstantsResponse::createOk();
		}

		$preparedResult = $this->prepareParameters(
			new WorkflowTemplateRequest\PrepareParametersRequest(
				templateParameters: $constants,
				requestParameters: $request->requestConstants,
				complexDocumentType: $request->complexDocumentType,
			),
		);

		if (!$preparedResult->isSuccess())
		{
			return (new WorkflowTemplateResponse\SetConstantsResponse())->addErrors($preparedResult->getErrors());
		}

		$preparedConstants = $preparedResult->getParameters();
		foreach ($constants as $key => $constant)
		{
			$constants[$key]['Default'] = $preparedConstants[$key] ?? null;
		}

		try
		{
			$user = new \CBPWorkflowTemplateUser($request->userId);
			\CBPWorkflowTemplateLoader::update(
				$request->templateId,
				[
					'CONSTANTS' => $constants,
					'USER_ID' => $user->getId(),
					'MODIFIER_USER' => $user,
				],
				actorId: $user->getId(),
			);
		}
		catch (\CBPWorkflowTemplateValidationException $exception)
		{
			$response = new WorkflowTemplateResponse\SetConstantsResponse();
			foreach ($exception->getErrors() as $error)
			{
				$response->addError(new Error($error['message'], $error['code'] ?? 0));
			}

			return $response;
		}
		catch (\Exception $e)
		{
			return WorkflowTemplateResponse\SetConstantsResponse::createError(
				new Error('something go wrong, try again'),
			);
		}

		ServiceLocator::getInstance()->get(ScheduledTriggerSyncService::class)?->syncByTemplate($request->templateId);

		return WorkflowTemplateResponse\SetConstantsResponse::createOk();
	}

	public function prepareStartParameters(
		WorkflowTemplateRequest\PrepareStartParametersRequest $request,
	): WorkflowTemplateResponse\PrepareStartParametersResponse
	{
		if ($request->templateId <= 0)
		{
			return WorkflowTemplateResponse\PrepareStartParametersResponse::createError(
				ErrorMessage::INVALID_PARAM_ARG->getError(
					[
						'#PARAM#' => 'templateId',
						'#VALUE#' => $request->templateId,
					],
					ErrorMessage::INVALID_PARAM_ARG->value,
				),
			);
		}

		if ($request->targetUserId <= 0)
		{
			return WorkflowTemplateResponse\PrepareStartParametersResponse::createError(
				ErrorMessage::INVALID_PARAM_ARG->getError(
					[
						'#PARAM#' => 'targetUserId',
						'#VALUE#' => $request->targetUserId,
					],
					ErrorMessage::INVALID_PARAM_ARG->value,
				),
			);
		}

		try
		{
			\CBPHelper::parseDocumentId($request->complexDocumentType);
		}
		catch (\CBPArgumentNullException $e)
		{
			return WorkflowTemplateResponse\PrepareStartParametersResponse::createError(Error::createFromThrowable($e));
		}

		// the identifier comes from the request and no list is built on the way here, so the templates a
		// pilot acts on are asked about one by one: the narrowing of the lists alone would be bypassed by
		// naming the identifier directly. The question is asked before the row is read, so a template this
		// employee may not see leaves by the branch of a template that does not exist - same code, same
		// message, same delay
		$template = null;
		if ($this->pilotVisibilityProvider->isVisible($request->targetUserId, $request->templateId))
		{
			$template
				= \CBPWorkflowTemplateLoader::getList(
					[],
					[
						'ID' => $request->templateId,
						'DOCUMENT_TYPE' => $request->complexDocumentType,
						'ACTIVE' => 'Y',
						'<AUTO_EXECUTE' => \CBPDocumentEventType::Automation,
					],
					false,
					false,
					['ID', 'PARAMETERS'],
				)->fetch()
			;
		}

		if (!$template)
		{
			return WorkflowTemplateResponse\PrepareStartParametersResponse::createError(
				ErrorMessage::TEMPLATE_NOT_FOUND->getError(
					['#ID#' => $request->templateId],
					ErrorMessage::TEMPLATE_NOT_FOUND->value,
				),
			);
		}

		// the fields belong to the version that will be started for this employee, and not to the live row
		// the template is read by
		$templateParameters = $this->startFormParameters->forInitiator(
			$request->templateId,
			$request->targetUserId,
			$request->manualStartSurface,
			is_array($template['PARAMETERS']) ? $template['PARAMETERS'] : [],
		);

		$workflowParameters = [];
		if ($templateParameters)
		{
			$preparedParameters = $this->prepareParameters(
				new WorkflowTemplateRequest\PrepareParametersRequest(
					$templateParameters,
					$request->requestParameters,
					$request->complexDocumentType,
				),
			);

			if (!$preparedParameters->isSuccess())
			{
				return (new WorkflowTemplateResponse\PrepareStartParametersResponse())->addErrors(
					$preparedParameters->getErrors(),
				);
			}

			$workflowParameters = $preparedParameters->getParameters();
		}

		$workflowParameters[\CBPDocument::PARAM_TAGRET_USER] = 'user_' . $request->targetUserId;
		$workflowParameters[\CBPDocument::PARAM_DOCUMENT_EVENT_TYPE] = $request->eventType;
		$workflowParameters[VersionChoice::PARAMETER_SCHEMA_TOKEN] = VersionChoice::parameterSchemaToken(
			$templateParameters,
		);

		return (new WorkflowTemplateResponse\PrepareStartParametersResponse())->setParameters($workflowParameters);
	}

	public function saveTemplate(
		WorkflowTemplateRequest\SaveTemplateRequest $request,
		bool $withoutCommonVersion = false,
	): WorkflowTemplateResponse\SaveTemplateResponse
	{
		return $this->saveTemplateInternal($request, $withoutCommonVersion, invalidateCache: true);
	}

	/**
	 * Saves a template as part of a transaction opened by the caller.
	 *
	 * The caller invalidates the cache after its transaction commits.
	 */
	public function saveTemplateInOuterTransaction(
		WorkflowTemplateRequest\SaveTemplateRequest $request,
		bool $withoutCommonVersion = false,
	): WorkflowTemplateResponse\SaveTemplateResponse
	{
		return $this->saveTemplateInternal($request, $withoutCommonVersion, invalidateCache: false);
	}

	private function saveTemplateInternal(
		WorkflowTemplateRequest\SaveTemplateRequest $request,
		bool $withoutCommonVersion,
		bool $invalidateCache,
	): WorkflowTemplateResponse\SaveTemplateResponse
	{
		$response = new WorkflowTemplateResponse\SaveTemplateResponse();
		$isNewNodesTemplate = false;
		$canPublishNewNodesTemplate = false;

		if ($request->checkAccess)
		{
			$documentType = $this->getDocumentType($request->parameters);
			if (is_null($documentType))
			{
				$errorMsg = ErrorMessage::INVALID_PARAM_ARG->getError([
					'#PARAM#' => 'DOCUMENT_TYPE',
					'#VALUE#' => $documentType,
				]);
				$response->addError($errorMsg);

				return $response;
			}

			$isNewNodesTemplate = $request->templateId <= 0
				&& $this->isNewNodesTemplate($request->fields, $documentType);
			$canPublishNewNodesTemplate = $isNewNodesTemplate
				&& $this->templateAccessService->canPublish(0, $request->user->getId(), $documentType);
			$hasAccess = $isNewNodesTemplate
				? $this->templateAccessService->canCreate($request->user->getId(), $documentType)
				: $this->canPersist($request->templateId, $documentType, $request->user->getId(), publish: true);

			if (!$hasAccess)
			{
				$response->addError(
					ErrorMessage::ACCESS_DENIED->getError(),
				);

				return $response;
			}
		}

		try {
			$template = WorkflowTemplate::createFromRequest($request);
			$templateId = $template->getTemplateId();
			$fields = $this->applyProcessDataMarker($templateId, $template);
			if ($isNewNodesTemplate && !$canPublishNewNodesTemplate)
			{
				$fields['TEMPLATE'] = [];
				$fields['AUTO_EXECUTE'] = \CBPDocumentEventType::None;
			}
			// A write to a missing template must be refused by fact, not silently reported as success:
			// the loader path swallows the ORM UpdateResult, so existence is verified here.
			if ($templateId > 0 && !$this->templateExists($templateId))
			{
				$response->addError($this->createTemplateNotFoundError($templateId));

				return $response;
			}

			if ($request->checkAccess && $this->takesTemplateOutOfNodes($templateId, $fields))
			{
				$response->addError(ErrorMessage::WRONG_TEMPLATE->getError());

				return $response;
			}

			$writeResponse = $this->writeTemplate(
				$templateId,
				$fields,
				$request,
				$withoutCommonVersion,
				$invalidateCache,
			);
			if (!$writeResponse->isSuccess())
			{
				$response->addErrors($writeResponse->getErrors());

				return $response;
			}

			$response->setTemplateId($writeResponse->getTemplateId());

			$this->handleTrackOnOption($response->getTemplateId(), $template->getFields());
		}
		catch (\Throwable $exception)
		{
			if (method_exists($exception, 'getErrors'))
			{
				$errors = $exception->getErrors();
				$response->setActivityErrors($errors);
				foreach ($errors as $error)
				{
					$response->addError(new Error($error['message'], $error['code'] ?? 0));
				}
			}
			else
			{
				$response->addError(new Error($exception->getMessage(), $exception->getCode()));
			}
		}

		return $response;
	}

	/**
	 * The multi-table write (template + settings) and the version record run in one transaction so a
	 * mid-write failure cannot leave a partial commit or a published version without its snapshot.
	 */
	private function writeTemplate(
		int $templateId,
		array $fields,
		WorkflowTemplateRequest\SaveTemplateRequest $request,
		bool $withoutCommonVersion,
		bool $invalidateCache,
	): WorkflowTemplateResponse\SaveTemplateResponse
	{
		$response = new WorkflowTemplateResponse\SaveTemplateResponse();
		$isUpdate = $templateId > 0;

		$connection = \Bitrix\Main\Application::getConnection();
		$connection->startTransaction();
		try
		{
			if ($templateId > 0)
			{
				$draftError = $this->getPublicationDraftError($request);
				if ($draftError !== null)
				{
					self::rollback($connection);
					$response->addError($draftError);

					return $response;
				}

				$publicationResponse = $this->writeCommonVersion($templateId, $fields, $request->user->getId());
			}
			else
			{
				// Creating a template publishes nothing: the journal only remembers the birth, so the
				// history stays empty until the user publishes for the first time.
				// Authorship comes from the user this save was authorized for, not from the global one: it
				// decides the "own templates" scope, so the two must be the same person by construction.
				// A creation without a common version is not a publication either: the first one for
				// everyone dates the template later.
				$templateId = (int)\CBPWorkflowTemplateLoader::add(
					$fields,
					actorId: $request->user->getId(),
					markFirstPublication: !$withoutCommonVersion,
				);
				$publicationResponse = new RecordPublicationResponse();
				if (
					$withoutCommonVersion
					&& !$this->commonRevisionWriter->writeCommonRevisionForUpdate($templateId, [])
				)
				{
					throw new PersistenceException(
						"Unable to mark workflow template {$templateId} as having no common version",
					);
				}

				if (!$this->recordCreation($templateId, $request))
				{
					self::rollback($connection);
					$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

					return $response;
				}
			}
		}
		catch (\Throwable $exception)
		{
			self::rollback($connection);

			throw $exception;
		}

		if (!$publicationResponse->isSuccess())
		{
			self::rollback($connection);
			$response->addErrors($publicationResponse->getErrors());

			return $response;
		}

		$connection->commitTransaction();
		if ($isUpdate && $invalidateCache)
		{
			\CBPWorkflowTemplateLoader::invalidateCachesAfterUpdate($templateId, $fields);
		}

		return $response->setTemplateId($templateId);
	}

	private function getPublicationDraftError(
		WorkflowTemplateRequest\SaveTemplateRequest $request,
	): ?\Bitrix\Bizproc\Error
	{
		if (!$request->useEditConflictGuard || $request->draftRevision === null)
		{
			return null;
		}

		$latestDraft = WorkflowTemplateDraftTable::getLatestDraftByTemplateId($request->templateId);
		if ($latestDraft === null || (int)$latestDraft['ID'] !== $request->draftId)
		{
			return $this->createDraftNotFoundError($latestDraft === null ? 0 : (int)$latestDraft['ID']);
		}

		$storedRevision = (int)$latestDraft['REVISION'];
		if ($storedRevision !== $request->draftRevision)
		{
			return $this->createRevisionConflictError($storedRevision, (int)$latestDraft['USER_ID']);
		}

		$affected = WorkflowTemplateDraftTable::updateByFilter(
			[
				'=ID' => $request->draftId,
				'=TEMPLATE_ID' => $request->templateId,
				'=REVISION' => $storedRevision,
			],
			['REVISION' => $storedRevision + 1],
		);
		if ($affected === 0)
		{
			$latestDraft = WorkflowTemplateDraftTable::getLatestDraftByTemplateId($request->templateId);
			if ($latestDraft === null || (int)$latestDraft['ID'] !== $request->draftId)
			{
				return $this->createDraftNotFoundError($latestDraft === null ? 0 : (int)$latestDraft['ID']);
			}

			return $this->createRevisionConflictError(
				(int)$latestDraft['REVISION'],
				(int)$latestDraft['USER_ID'],
			);
		}

		return null;
	}

	/**
	 * Rolls the write back, whether the transaction of the write is the outer one or a savepoint inside the
	 * transaction of a caller. In the nested case the platform rolls back to the savepoint and only then
	 * reports the nested rollback as unsupported: that report must not replace the refusal being returned,
	 * which is what the caller is waiting for. Same idiom as
	 * {@see \CBPWorkflowTemplateLoader::rollbackTransaction()}.
	 */
	private static function rollback(\Bitrix\Main\DB\Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (\Bitrix\Main\DB\TransactionException)
		{
		}
	}

	/**
	 * Writes a pilot version as the common one without repeating the request-level access checks.
	 *
	 * The caller owns the transaction, including the row lock that protects the pilot identity. The common
	 * template write and its history record therefore commit or roll back together with the pilot cascade.
	 */
	public function publishCommonVersion(int $templateId, array $fields, int $userId): RecordPublicationResponse
	{
		return $this->writeCommonVersion($templateId, $fields, $userId);
	}

	/**
	 * The common-template write and its history record are inseparable. The caller is responsible for the
	 * transaction boundary and for invalidating caches after its whole operation commits.
	 */
	private function writeCommonVersion(
		int $templateId,
		array $fields,
		int $userId,
	): RecordPublicationResponse
	{
		// Both the decision and the initial version are taken before the overwrite: afterwards the row
		// already carries the publication being made, and the configuration it replaces is gone.
		$isVersionedSave = $this->isVersionedSave($templateId, $fields);

		if ($isVersionedSave && !$this->historyService->ensureHistoryInitialized($templateId))
		{
			$response = new RecordPublicationResponse();
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}

		// Authorship comes from the user this write was authorized for, not from the global one: it decides
		// the "own templates" scope, so the two must be the same person by construction.
		\CBPWorkflowTemplateLoader::update(
			$templateId,
			$fields,
			deferCacheInvalidation: true,
			publishesCommonVersion: true,
			actorId: $userId,
		);
		$this->templatePersistAccessService->resetTemplateAccessInfo($templateId);

		return $isVersionedSave
			? $this->recordPublication($templateId, $fields, $userId)
			: new RecordPublicationResponse()
		;
	}

	private function recordCreation(
		int $templateId,
		WorkflowTemplateRequest\SaveTemplateRequest $request,
	): bool
	{
		if (!TemplateHistory::isEnabled())
		{
			return true;
		}

		return $this->historyService->recordCreation($templateId, $request->user->getId());
	}

	/**
	 * Only a save that actually wrote a graph into a template taking part in the history is published into
	 * the journal: an empty shell carries nothing to restore later.
	 */
	private function isVersionedSave(int $templateId, array $fields): bool
	{
		return $this->isJournaledTemplate($templateId)
			&& array_key_exists('TEMPLATE', $fields)
			&& !\CBPHelper::isEmptyValue($fields['TEMPLATE'])
		;
	}

	private function isJournaledTemplate(int $templateId): bool
	{
		return TemplateHistory::isEnabled()
			&& ($this->getTemplateAccessInfo($templateId)['inHistory'] ?? false)
		;
	}

	private function recordPublication(
		int $templateId,
		array $fields,
		int $userId,
	): RecordPublicationResponse
	{
		return $this->historyService->recordPublication(
			new RecordPublicationRequest(
				templateId: $templateId,
				userId: $userId,
				publicationType: TemplatePublicationType::Common,
				templateFields: $fields,
			),
		);
	}

	private function handleTrackOnOption(int $templateId, array $fields): void
	{
		if (isset($fields['TRACK_ON']))
		{
			$optionName = 'tpl_track_on_' . $templateId;
			if ($fields['TRACK_ON'] === 'Y')
			{
				$trackOn = (int)Option::get('bizproc', $optionName, 0);
				if ((time() - self::TRACK_ON_INTERVAL) > $trackOn)
				{
					Option::set('bizproc', $optionName, time());
				}
			}
			else
			{
				Option::delete('bizproc', ['name' => $optionName]);
			}
		}
	}

	public function importTemplate(
		WorkflowTemplateRequest\ImportTemplateRequest $request,
	): WorkflowTemplateResponse\ImportTemplateResponse
	{
		$response = new WorkflowTemplateResponse\ImportTemplateResponse();

		$documentType = $this->getDocumentType($request->parameters);
		if (is_null($documentType))
		{
			$errorMsg = ErrorMessage::INVALID_PARAM_ARG->getError([
				'#PARAM#' => 'DOCUMENT_TYPE',
				'#VALUE#' => $documentType,
			]);
			$response->addError($errorMsg);

			return $response;
		}

		if ($request->checkAccess && !$this->canTransfer($request->id, $documentType, $request->user->getId()))
		{
			$response->addError(
				ErrorMessage::IMPORT_ACCESS_DENIED->getError(),
			);

			return $response;
		}

		$newTemplateId = 0;
		$file = $request->file;
		if (is_uploaded_file($file['tmp_name']))
		{
			$data = (new \Bitrix\Main\IO\File($file['tmp_name']))->getContents();

			try
			{
				$newTemplateId = \CBPWorkflowTemplateLoader::ImportTemplate(
					$request->id,
					$documentType,
					$request->autostart,
					$request->name,
					$request->description,
					$data,
				);
			}
			catch (\Throwable $exception)
			{
				$response->addError(new Error(preg_replace("#[\r\n]+#", " ", $exception->getMessage())));
			}
		}

		if ($newTemplateId <= 0)
		{
			$response->addError(new Error(Loc::getMessage('BIZPROC_LIB_API_WORKFLOW_TEMPLATE_SERVICE_IMPORT_ERROR')));
		}

		$response->setTemplateId($newTemplateId);

		return $response;
	}

	private function getDocumentType(array $parameters): ?array
	{
		if (!isset($parameters['MODULE_ID'], $parameters['ENTITY'], $parameters['DOCUMENT_TYPE']))
		{
			return null;
		}

		return [
			$parameters['MODULE_ID'],
			$parameters['ENTITY'],
			$parameters['DOCUMENT_TYPE'],
		];
	}

	public function exportTemplate(
		WorkflowTemplateRequest\ExportTemplateRequest $request)
	: WorkflowTemplateResponse\ExportTemplateResponse
	{
		$response = new WorkflowTemplateResponse\ExportTemplateResponse();

		$documentType = $this->getDocumentType($request->parameters);
		if (is_null($documentType))
		{
			$errorMsg = ErrorMessage::INVALID_PARAM_ARG->getError([
				'#PARAM#' => 'DOCUMENT_TYPE',
				'#VALUE#' => $documentType,
			]);
			$response->addError($errorMsg);

			return $response;
		}

		if ($request->checkAccess && !$this->canTransfer($request->id, $documentType, $request->user->getId()))
		{
			$response->addError(
				ErrorMessage::EXPORT_ACCESS_DENIED->getError(),
			);

			return $response;
		}

		// a template without a common version exports as a file with an empty scheme and confirms that the
		// template is there, so it is refused by the branch of a template that does not exist - with a
		// pilot of its own and without one alike
		$bp = $this->hasCommonVersion($request->id)
			? \CBPWorkflowTemplateLoader::ExportTemplate($request->id)
			: false
		;

		if (!$bp)
		{
			$response->addError(new Error('Not found', 404));

			return $response;
		}

		$response->setTemplateData((string)$bp);

		return $response;
	}

	public function saveTemplateDraft(
		WorkflowTemplateRequest\SaveTemplateDraftRequest $request,
	): WorkflowTemplateResponse\SaveTemplateDraftResponse
	{
		$response = new WorkflowTemplateResponse\SaveTemplateDraftResponse();
		$saveStartedAt = microtime(true);

		if ($request->checkAccess)
		{
			$documentType = $this->getDocumentType($request->parameters);
			if (is_null($documentType))
			{
				$errorMsg = ErrorMessage::INVALID_PARAM_ARG->getError([
					'#PARAM#' => 'DOCUMENT_TYPE',
					'#VALUE#' => $documentType,
				]);
				$response->addError($errorMsg);

				return $response;
			}

			if (!$this->canPersist($request->templateId, $documentType, $request->user->getId(), publish: false))
			{
				$response->addError(
					ErrorMessage::ACCESS_DENIED->getError(),
				);

				return $response;
			}
		}

		try
		{
			// The draft table only stores TEMPLATE_ID and never checks the parent template, so a draft
			// for a deleted template would be created/updated silently. Refuse it by existence.
			if ($request->templateId > 0 && !$this->templateExists($request->templateId))
			{
				$response->addError($this->createTemplateNotFoundError($request->templateId));

				return $response;
			}

			if ($request->useEditConflictGuard)
			{
				$latestDraft = $request->templateId > 0
					? WorkflowTemplateDraftTable::getLatestDraftByTemplateId($request->templateId)
					: null
				;

				$draftError = $this->getDraftWriteError($request, $latestDraft);
				if ($draftError !== null)
				{
					$response->addError($draftError);

					return $response;
				}
			}

			$saveRequest = new \Bitrix\Bizproc\Api\Request\WorkflowTemplateService\SaveTemplateRequest(
				$request->templateId,
				$request->parameters,
				$request->fields,
				$request->user,
				$request->checkAccess,
			);
			$template = WorkflowTemplate::createFromRequest($saveRequest);
			$templateId = $template->getTemplateId() > 0 ? $template->getTemplateId() : null;
			// A draft of a template that does not exist yet has nowhere to store the marker, so it travels
			// in the fields of the draft alone: the row is written by the publication that follows.
			$fields = $this->applyProcessDataMarker($request->templateId, $template);

			$tpl = \Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable::createObject();
			$availableFields = [
				'NAME',
				'DESCRIPTION',
				'TYPE',
				'SORT',
				'AUTO_EXECUTE',
				'IS_SYSTEM',
				'TEMPLATE',
				'CONSTANTS',
				'VARIABLES',
				'PARAMETERS',
			];

			foreach ($availableFields as $field)
			{
				if (isset($fields[$field]))
				{
					$tpl->set($field, $fields[$field]);
				}
			}

			[$moduleId, $entity, $documentType] = $template->getDocumentType();
			$tpl->setModuleId($moduleId);
			$tpl->setEntity($entity);
			$tpl->setDocumentType($documentType);

			$isJournaled = $this->isJournaledTemplate($request->templateId);
			$prevState = [];

			$latestDraft = $templateId
				? WorkflowTemplateDraftTable::getLatestDraftByTemplateId($templateId)
				: null;

			if ($request->draftId)
			{
				$draft = WorkflowTemplateDraftTable::getByPrimary($request->draftId)->fetchObject();
				if (!$draft || $draft->getTemplateId() !== $templateId)
				{
					$response->addError(
						$request->useEditConflictGuard
							? $this->createDraftNotFoundError($latestDraft === null ? 0 : (int)$latestDraft['ID'])
							: ErrorMessage::ACCESS_DENIED->getCodedError(),
					);

					return $response;
				}

				if (
					$this->isDraftNewerThanSave($draft->getCreated(), $saveStartedAt)
					|| $this->isDraftNewerThanSave($latestDraft['CREATED'] ?? null, $saveStartedAt)
				)
				{
					$response->addError(ErrorMessage::TEMPLATE_DRAFT_CONFLICT->getCodedError());

					return $response;
				}

				// Not the data of the draft being updated: a save may land on a draft that is no longer the
				// freshest one of the template, and the journal line has to tell what changed for the person.
				if ($isJournaled)
				{
					$prevState = $this->getDraftBaseState($request->templateId);
				}

				$boundDraftData = is_array($draft->getTemplateData()) ? $draft->getTemplateData() : [];

				$draftFields = [
					'MODULE_ID' => $tpl->getModuleId(),
					'ENTITY' => $tpl->getEntity(),
					'DOCUMENT_TYPE' => $tpl->getDocumentType(),
					'TEMPLATE_ID' => $templateId,
					'TEMPLATE_DATA' => self::toDraftTemplateData(
						$tpl->collectValues(),
						$boundDraftData,
						$request->fieldsToClaim,
					),
					'USER_ID' => $request->user->getId(),
					'CREATED' => new \Bitrix\Main\Type\DateTime(),
				];

				if ($request->useEditConflictGuard)
				{
					$storedRevision = (int)$latestDraft['REVISION'];
					$draftFields['TEMPLATE_DATA'] = WorkflowTemplateTable::toSerializedForm(
						$draftFields['TEMPLATE_DATA'],
					);
					$draftFields['REVISION'] = $storedRevision + 1;

					$affected = WorkflowTemplateDraftTable::updateByFilter(
						[
							'=ID' => $request->draftId,
							'=TEMPLATE_ID' => $request->templateId,
							'=REVISION' => $storedRevision,
						],
						$draftFields,
					);

					if ($affected === 0)
					{
						$response->addError($this->createRevisionConflictErrorFromStorage($request->templateId));

						return $response;
					}

					$response
						->setTemplateDraftId($request->draftId)
						->setDraftRevision($storedRevision + 1)
					;
				}
				else
				{
					$result = WorkflowTemplateDraftTable::update($request->draftId, $draftFields);
					if (!$result->isSuccess())
					{
						$response->addErrors($result->getErrors());

						return $response;
					}

					$response->setTemplateDraftId((int)$result->getId());
				}
			}
			else
			{
				if ($this->isDraftNewerThanSave($latestDraft['CREATED'] ?? null, $saveStartedAt))
				{
					$response->addError(ErrorMessage::TEMPLATE_DRAFT_CONFLICT->getCodedError());

					return $response;
				}

				// Read before the write: the draft being added becomes the freshest one of the template.
				if ($isJournaled)
				{
					$prevState = $this->getDraftBaseState($request->templateId);
				}

				$draftFields = [
					'MODULE_ID' => $tpl->getModuleId(),
					'ENTITY' => $tpl->getEntity(),
					'DOCUMENT_TYPE' => $tpl->getDocumentType(),
					'TEMPLATE_ID' => $templateId,
					'TEMPLATE_DATA' => self::toDraftTemplateData(
						$tpl->collectValues(),
						[],
						$request->fieldsToClaim,
					),
					'USER_ID' => $request->user->getId(),
					'CREATED' => new \Bitrix\Main\Type\DateTime(),
				];
				if ($request->useEditConflictGuard)
				{
					$draftFields['REVISION'] = self::FIRST_DRAFT_REVISION;
				}

				$result = WorkflowTemplateDraftTable::add($draftFields);

				if (!$result->isSuccess())
				{
					$response->addErrors($result->getErrors());

					return $response;
				}

				$response->setTemplateDraftId((int)$result->getId());
				if ($request->useEditConflictGuard)
				{
					$response->setDraftRevision(self::FIRST_DRAFT_REVISION);
				}
			}

			if ($isJournaled)
			{
				$this->historyService->recordChange(
					new RecordChangeRequest(
						templateId: $request->templateId,
						attribution: $request->attribution,
						prevState: $prevState,
						nextState: $tpl->collectValues(),
						isTemplateJournaled: true,
					),
				);
			}
		}
		catch (\Throwable $exception)
		{
			$response->addError(new Error($exception->getMessage(), $exception->getCode()));
		}

		return $response;
	}

	private function getDraftBaseState(int $templateId): array
	{
		$draft = WorkflowTemplateDraftTable::getLatestDraftWithDataByTemplateId($templateId);
		if ($draft !== null)
		{
			return is_array($draft['TEMPLATE_DATA'])
				? AgentOwnedMetadata::stripFrom($draft['TEMPLATE_DATA'])
				: []
			;
		}

		$live = WorkflowTemplateTable::query()
			->setSelect(self::JOURNALED_STATE_FIELDS)
			->where('ID', $templateId)
			->setLimit(1)
			->fetch()
		;

		return $live ?: [];
	}

	/**
	 * @param array<string, mixed> $snapshot
	 * @param array<string, mixed> $boundDraftData
	 * @param list<string> $fieldsToClaim
	 * @return array<string, mixed>
	 */
	private static function toDraftTemplateData(
		array $snapshot,
		array $boundDraftData,
		array $fieldsToClaim,
	): array
	{
		return AgentOwnedMetadata::applyTo(
			$snapshot,
			AgentOwnedMetadata::nextOwnedFields($boundDraftData, $snapshot, $fieldsToClaim),
		);
	}

	/**
	 * A draft written after this save started carries a graph this request never saw: writing over it
	 * would roll back whoever wrote it - a second tab, a version restore, a pilot publication.
	 */
	private function isDraftNewerThanSave(?\Bitrix\Main\Type\DateTime $draftCreated, float $saveStartedAt): bool
	{
		return $draftCreated !== null && $draftCreated->getTimestamp() > $saveStartedAt;
	}

	public function loadTemplateDraft(
		WorkflowTemplateRequest\LoadTemplateDraftRequest $request,
	): WorkflowTemplateResponse\LoadTemplateResponse
	{
		$response = new WorkflowTemplateResponse\LoadTemplateResponse();

		if ($request->id <= 0)
		{
			$response->addError(new Error('incorrect draft id'));

			return $response;
		}

		try
		{
			$draft = WorkflowTemplateDraftTable::getByPrimary($request->id)->fetchObject();
			if (!$draft)
			{
				$response->addError(ErrorMessage::GET_DATA_ERROR->getError());

				return $response;
			}

			$response->setData($draft->collectValues());
		}
		catch (\Throwable $exception)
		{
			$response->addError(new Error($exception->getMessage(), $exception->getCode()));
		}

		return $response;
	}

	/**
	 * The two checks that guard a draft write (ALG-02): the draft of the request must still be the draft of
	 * the template, and its stored content must be the one the writer saw.
	 *
	 * @param array<string, mixed>|null $latestDraft freshest draft of the template, null when it has none
	 */
	private function getDraftWriteError(
		WorkflowTemplateRequest\SaveTemplateDraftRequest $request,
		?array $latestDraft,
	): ?\Bitrix\Bizproc\Error
	{
		if (!$request->draftId)
		{
			// A writer with no draft of its own would create a second one beside the living draft of the
			// template, and the editor only ever reads the freshest.
			return $latestDraft === null ? null : $this->createDraftNotFoundError((int)$latestDraft['ID']);
		}

		if ($latestDraft === null || (int)$latestDraft['ID'] !== $request->draftId)
		{
			return $this->createDraftNotFoundError($latestDraft === null ? 0 : (int)$latestDraft['ID']);
		}

		$storedRevision = (int)$latestDraft['REVISION'];

		// Whether the revision applies is decided by the stored value: a zero there means there is nothing to
		// compare against. A request with no revision at all is an old client, and the revision is protection
		// from a lost write, not a boundary of access - the boundary is held by the lease.
		if ($storedRevision > 0 && $request->revision !== null && $request->revision !== $storedRevision)
		{
			return $this->createRevisionConflictError($storedRevision, (int)$latestDraft['USER_ID']);
		}

		return null;
	}

	private function createDraftNotFoundError(int $draftId): \Bitrix\Bizproc\Error
	{
		return ErrorMessage::DRAFT_NOT_FOUND->getCodedError(customData: ['draftId' => $draftId]);
	}

	private function createRevisionConflictError(int $revision, int $userId): \Bitrix\Bizproc\Error
	{
		return ErrorMessage::DRAFT_REVISION_CONFLICT->getCodedError(customData: [
			'revision' => $revision,
			'userId' => $userId,
		]);
	}

	/**
	 * The state the racing writer left behind: the row is read again exactly because the conditional write
	 * proved the values of the read before it are gone.
	 */
	private function createRevisionConflictErrorFromStorage(int $templateId): \Bitrix\Bizproc\Error
	{
		$latestDraft = WorkflowTemplateDraftTable::getLatestDraftByTemplateId($templateId);

		return $this->createRevisionConflictError(
			$latestDraft === null ? 0 : (int)$latestDraft['REVISION'],
			$latestDraft === null ? 0 : (int)$latestDraft['USER_ID'],
		);
	}

	// Existence-only delete guard: a row is "present" by primary key, independent of SYSTEM_CODE,
	// so the answer matches WorkflowTemplateRepository::exists(). Access and SYSTEM_CODE/type gating
	// for the node editor live upstream (Diagram::getTpl, canWriteTemplate, CreateWorkflow checks).
	private function templateExists(int $templateId): bool
	{
		return (new WorkflowTemplateRepository())->exists($templateId);
	}

	private function hasCommonVersion(int $templateId): bool
	{
		return $this->commonRevisionWriter->hasCommonVersion($templateId);
	}

	private function createTemplateNotFoundError(int $templateId): \Bitrix\Bizproc\Error
	{
		return ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError(['#ID#' => $templateId]);
	}

	private function isCorrectSignedFileIds(mixed $value): bool
	{
		if (empty($value))
		{
			return false;
		}

		$ids = is_array($value) ? $value : [$value];
		foreach ($ids as $id)
		{
			if (!is_string($id))
			{
				return false;
			}

			$unsigned = \CBPDocument::unSignParameters($id);
			$fileId = $unsigned[0] ?? null;
			if (!is_numeric($fileId) || $fileId <= 0)
			{
				return false;
			}
		}

		return true;
	}
}
