<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\TestRun;

use Bitrix\Bizproc\Activity\BaseTrigger;
use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Container;
use Bitrix\Bizproc\Internal\Service\Container as ServiceContainer;
use Bitrix\Bizproc\Internal\Entity\Debugger\DebugSession;
use Bitrix\Bizproc\Internal\Entity\Debugger\DocumentId;
use Bitrix\Bizproc\Internal\Entity\Debugger\Timestamp;
use Bitrix\Bizproc\Internal\Entity\Debugger\TraceType;
use Bitrix\Bizproc\Internal\Entity\Workflow\TestRunContext;
use Bitrix\Bizproc\Internal\Entity\WorkflowTemplate\VersionChoice;
use Bitrix\Bizproc\Internal\Exception\Debugger\PersistenceException;
use Bitrix\Bizproc\Internal\Repository\Debugger\DebugSessionRepository;
use Bitrix\Bizproc\Public\Entity\Document\Workflow;
use Bitrix\Bizproc\Public\Provider\DebugSessionProvider;
use Bitrix\Bizproc\Public\Provider\Params\DebugSession\DebugSessionFilter;
use Bitrix\Bizproc\Public\Service\TestRun\Dto\ActiveRun;
use Bitrix\Bizproc\Public\Service\TestRun\Dto\FieldDescription;
use Bitrix\Bizproc\Public\Service\TestRun\Dto\PrepareRequest;
use Bitrix\Bizproc\Public\Service\TestRun\Dto\RunForm;
use Bitrix\Bizproc\Public\Service\TestRun\Dto\StartRequest;
use Bitrix\Bizproc\Workflow\Entity\WorkflowInstanceTable;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

/**
 * A test run of a template from one of its trigger nodes: the outputs of the node are filled by hand and
 * the saved draft is executed, so the chain after the trigger is seen before the template is published.
 *
 * The run lives as a debug session marked `testRun` in its metadata, one per user and template. The right
 * to publish the template is the right to run it, and it is checked by the caller before the service.
 *
 * Only a template of the new designer (`TYPE = nodes`) without a document is served: the run has no
 * document to start with but the `Workflow` pseudo one.
 */
final class TestRunService
{
	public const MAX_ACTIVE_HOURS = 24;

	private const DOCUMENT_SOURCE_NONE = 'none';
	private const RUN_LOCK_TIMEOUT = 3;

	/** Everything the run is read and closed by; LOGS and METRICS are text blobs the session entity drops anyway. */
	private const ACTIVE_SESSION_FIELDS = [
		'ID',
		'USER_ID',
		'DEBUG_ID',
		'MODULE_ID',
		'ENTITY',
		'DOCUMENT_ID',
		'WORKFLOW_ID',
		'TEMPLATE_ID',
		'START_TIME',
		'END_TIME',
		'METADATA',
	];

	/**
	 * Data: `activeRun`: {@see ActiveRun} or null. A run that outlived the limit is stopped and not returned;
	 * one whose instance refused to stop is returned for a finish to stop it.
	 */
	public function status(int $userId, int $templateId): Result
	{
		$runs = $this->listOpenRuns($userId, $templateId);
		$session = $this->findActiveRun($runs, $this->closeExpiredRuns($runs));

		return (new Result())->setData([
			'activeRun' => $session === null ? null : $this->summaryOf($session),
		]);
	}

	/** Data: `form`: {@see RunForm}. */
	public function prepare(PrepareRequest $request): Result
	{
		$result = new Result();
		$resolved = $this->resolveNode($request->userId, $request->templateId, $request->blockId);

		if ($resolved['error'] !== null)
		{
			return $result->addError($resolved['error']->createError($resolved['errorData']));
		}

		$validationErrors = $this->validateDraft($request->userId, $resolved['draft']);
		if ($validationErrors !== [])
		{
			return $result->addErrors($validationErrors);
		}

		return $result->setData([
			'form' => new RunForm(
				draftId: (int)$resolved['draft']['ID'],
				draftRevision: (int)$resolved['draft']['REVISION'],
				blockId: $request->blockId,
				triggerTitle: (string)($resolved['node']['Properties']['Title'] ?? ''),
				returnFields: $this->describeReturnFields($resolved['node']),
				documentSource: self::DOCUMENT_SOURCE_NONE,
				canCreateDocument: false,
				canSelectDocument: false,
			),
		]);
	}

	/** Data: `sessionId`, `workflowId`, `documentId`. */
	public function start(StartRequest $request): Result
	{
		$lockName = $this->lockNameOf($request->userId, $request->templateId);
		if (!Application::getConnection()->lock($lockName, self::RUN_LOCK_TIMEOUT))
		{
			return (new Result())->addError(ErrorCode::ActiveRunExists->createError());
		}

		try
		{
			return $this->startLocked($request);
		}
		finally
		{
			Application::getConnection()->unlock($lockName);
		}
	}

	/** Under the lock of the user and the template, so neither a second start nor a finish sees the run half made. */
	private function startLocked(StartRequest $request): Result
	{
		$result = new Result();
		$resolved = $this->resolveNode($request->userId, $request->templateId, $request->blockId);

		if ($resolved['error'] !== null)
		{
			return $result->addError($resolved['error']->createError($resolved['errorData']));
		}

		if (
			(int)$resolved['draft']['ID'] !== $request->draftId
			|| (int)$resolved['draft']['REVISION'] !== $request->draftRevision
		)
		{
			return $result->addError(ErrorCode::DraftChanged->createError());
		}

		$validationErrors = $this->validateDraft($request->userId, $resolved['draft']);
		if ($validationErrors !== [])
		{
			return $result->addErrors($validationErrors);
		}

		$properties = \CBPRuntime::getRuntime()->getActivityReturnProperties($resolved['node']);
		$normalized = $this->normalizeValues(Workflow::getComplexType(), $properties, $request->values);
		if (!$normalized->isSuccess())
		{
			return $result->addErrors($normalized->getErrors());
		}

		$workflowId = \CBPRuntime::generateWorkflowId();
		$documentId = Workflow::getComplexId($workflowId);

		$session = DebugSession::create(
			DocumentId::createFromArray($documentId),
			debugId: 0,
			userId: $request->userId,
			templateId: $request->templateId,
		)
			->setWorkflowId($workflowId)
			->setMetadata([
				'testRun' => true,
				'blockId' => $request->blockId,
				'draftId' => $request->draftId,
				'draftRevision' => $request->draftRevision,
				'documentSource' => self::DOCUMENT_SOURCE_NONE,
			])
		;

		if (!$this->saveNewRun($session))
		{
			return $result->addError(ErrorCode::StartFailed->createError());
		}

		$debugger = \CBPRuntime::getRuntime()->makeDebugger();
		$outerSession = $debugger->findDebugSession();
		$debugger->restoreSession($session);

		$errors = [];
		try
		{
			\CBPDocument::startWorkflow(
				$request->templateId,
				$documentId,
				[
					\CBPDocument::PARAM_TRIGGER_EVENT => $request->blockId,
					\CBPDocument::PARAM_DOCUMENT_EVENT_TYPE => \CBPDocumentEventType::Debug,
					\CBPDocument::PARAM_PRE_GENERATED_WORKFLOW_ID => $workflowId,
					\CBPDocument::PARAM_TAGRET_USER => "user_{$request->userId}",
				],
				$errors,
				null,
				VersionChoice::draft(
					$request->templateId,
					is_array($resolved['draft']['TEMPLATE_DATA'] ?? null) ? $resolved['draft']['TEMPLATE_DATA'] : [],
					(string)$request->draftRevision,
				),
				new TestRunContext(
					startNodeName: $request->blockId,
					values: $normalized->getData()['values'],
					types: $properties,
				),
			);
		}
		catch (Throwable $exception)
		{
			$errors[] = ['code' => $exception->getCode(), 'message' => $exception->getMessage()];
		}
		finally
		{
			$debugger->restoreSession($outerSession);
		}

		if (!empty($errors))
		{
			$session->addTrace(
				TraceType::Error,
				'TestRunService::start',
				ErrorCode::StartFailed->getMessage(),
				['errors' => $errors],
			);
			$this->closeSession($session);

			return $result->addError(ErrorCode::StartFailed->createError([
				'sessionId' => (int)$session->getId(),
				'documentId' => $documentId,
			]));
		}

		Container::getDebugSessionService()?->saveSession($session);

		return $result->setData([
			'sessionId' => (int)$session->getId(),
			'workflowId' => $workflowId,
			'documentId' => $documentId,
		]);
	}

	public function finish(int $userId, int $sessionId): Result
	{
		$result = new Result();
		$session = $this->findOwnRun($userId, $sessionId);
		if ($session === null)
		{
			return $result->addError(ErrorCode::SessionNotFound->createError());
		}

		$lockName = $this->lockNameOf($userId, (int)$session->getTemplateId());
		if (!Application::getConnection()->lock($lockName, self::RUN_LOCK_TIMEOUT))
		{
			return $result->addError(ErrorCode::FinishFailed->createError());
		}

		try
		{
			// read again: the start the lock waited for has saved the run with its instance since
			$session = $this->findOwnRun($userId, $sessionId);
			$error = $session === null || $session->isEnded() ? null : $this->closeSession($session);

			return $error === null ? $result : $result->addError($error);
		}
		finally
		{
			Application::getConnection()->unlock($lockName);
		}
	}

	/**
	 * The debug journal of the user is cleared around the runs not closed yet, since a run is found, stopped
	 * and finished by its session. A start saves the session of a run under the same lock, so a run started
	 * meanwhile is either listed here or saved after the clearing.
	 *
	 * @return int the number of the sessions deleted
	 * @throws PersistenceException when the lock is not taken
	 */
	public function clearJournal(int $userId): int
	{
		$lockName = $this->journalLockNameOf($userId);
		if (!Application::getConnection()->lock($lockName, self::RUN_LOCK_TIMEOUT))
		{
			throw PersistenceException::deleteError();
		}

		try
		{
			$openRunIds = array_map(static fn(DebugSession $run): int => (int)$run->getId(), $this->listOpenRuns($userId));

			return (new DebugSessionRepository())->deleteByUserId($userId, $openRunIds);
		}
		finally
		{
			Application::getConnection()->unlock($lockName);
		}
	}

	/** {@see self::clearJournal()} */
	private function saveNewRun(DebugSession $session): bool
	{
		$lockName = $this->journalLockNameOf((int)$session->getUserId());
		if (!Application::getConnection()->lock($lockName, self::RUN_LOCK_TIMEOUT))
		{
			return false;
		}

		try
		{
			Container::getDebugSessionService()?->saveSession($session);

			return true;
		}
		finally
		{
			Application::getConnection()->unlock($lockName);
		}
	}

	private function findOwnRun(int $userId, int $sessionId): ?DebugSession
	{
		$session = (new DebugSessionProvider())->getById($sessionId, withTraces: false);

		return $session !== null && $session->getUserId() === $userId && $session->isTestRun() ? $session : null;
	}

	private function lockNameOf(int $userId, int $templateId): string
	{
		return "bizproc_test_run_{$userId}_{$templateId}";
	}

	private function journalLockNameOf(int $userId): string
	{
		return "bizproc_debug_journal_{$userId}";
	}

	/**
	 * @param DebugSession[] $runs
	 * @param array<int, Error> $stopFailures {@see self::closeExpiredRuns()}
	 */
	private function findActiveRun(array $runs, array $stopFailures): ?DebugSession
	{
		foreach ($runs as $run)
		{
			if (!$this->isExpired($run) || isset($stopFailures[(int)$run->getId()]))
			{
				return $run;
			}
		}

		return null;
	}

	/**
	 * A run past the limit has to be stopped, and it is over only once its instance is: the one that refused
	 * stays open, shown by the status and in the way of the next start, until a finish stops it.
	 *
	 * @param DebugSession[] $runs
	 * @return array<int, Error> FINISH_FAILED by the id of the session of a run that did not stop
	 */
	private function closeExpiredRuns(array $runs): array
	{
		$failures = [];

		foreach ($runs as $run)
		{
			$error = $this->isExpired($run) ? $this->closeSession($run) : null;
			if ($error !== null)
			{
				$failures[(int)$run->getId()] = $error;
			}
		}

		return $failures;
	}

	/**
	 * Unlimited: the journal is cleared around every run found here. The mark of a run lives in the JSON
	 * metadata, so it is told after the query; the open sessions of the old debugger end with their start.
	 *
	 * @return DebugSession[]
	 */
	private function listOpenRuns(int $userId, ?int $templateId = null): array
	{
		$sessions = (new DebugSessionRepository())->getList(
			filter: new DebugSessionFilter([
				'USER_ID' => $userId,
				'TEMPLATE_ID' => $templateId,
				'IS_ENDED' => false,
			]),
			select: self::ACTIVE_SESSION_FIELDS,
		);

		$runs = [];
		foreach ($sessions as $session)
		{
			if (
				$session->isTestRun()
				&& $session->getStartTime() !== null
				&& $session->getDocumentId() !== null
			)
			{
				$runs[] = $session;
			}
		}

		return $runs;
	}

	private function isExpired(DebugSession $session): bool
	{
		return $session->getStartTime()->isBefore(Timestamp::now()->subSeconds(self::MAX_ACTIVE_HOURS * 3600));
	}

	/**
	 * Saved without becoming the current session, so the rest of the hit does not trace into a closed run.
	 * An instance that refused to stop keeps the run open: the run is still going.
	 */
	private function closeSession(DebugSession $session): ?Error
	{
		$workflowId = (string)$session->getWorkflowId();
		if ($workflowId !== '' && WorkflowInstanceTable::exists($workflowId))
		{
			$errors = [];
			\CBPDocument::terminateWorkflow($workflowId, $session->getDocumentId()?->toArray(), $errors);

			if (!empty($errors))
			{
				return ErrorCode::FinishFailed->createError();
			}
		}

		$session->end();
		Container::getDebugSessionService()?->saveSession($session);

		return null;
	}

	private function summaryOf(DebugSession $session): ActiveRun
	{
		$metadata = $session->getMetadata();

		return new ActiveRun(
			sessionId: (int)$session->getId(),
			workflowId: $session->getWorkflowId(),
			documentId: $session->getDocumentId()->toArray(),
			blockId: (string)($metadata['blockId'] ?? ''),
			draftId: (int)($metadata['draftId'] ?? 0),
			startTime: $session->getStartTime()->getValue(),
		);
	}

	/** @return array{error: ?ErrorCode, errorData: mixed, draft: ?array, node: ?array} */
	private function resolveNode(int $userId, int $templateId, string $blockId): array
	{
		$resolved = ['error' => null, 'errorData' => null, 'draft' => null, 'node' => null];

		$templateError = $this->checkTemplate($templateId);
		if ($templateError !== null)
		{
			$resolved['error'] = $templateError;

			return $resolved;
		}

		$runs = $this->listOpenRuns($userId, $templateId);
		$active = $this->findActiveRun($runs, $this->closeExpiredRuns($runs));
		if ($active !== null)
		{
			$resolved['error'] = ErrorCode::ActiveRunExists;
			$resolved['errorData'] = $this->summaryOf($active)->toArray();

			return $resolved;
		}

		$draft = WorkflowTemplateDraftTable::getLatestDraftWithDataByTemplateId($templateId);
		if ($draft === null)
		{
			$resolved['error'] = ErrorCode::DraftNotFound;

			return $resolved;
		}

		$node = $this->findTriggerNode($draft, $blockId);
		if ($node === null)
		{
			$resolved['error'] = ErrorCode::TriggerNotSupported;

			return $resolved;
		}

		if ($this->needsDocument($node))
		{
			$resolved['error'] = ErrorCode::DocumentSourceUnavailable;

			return $resolved;
		}

		$resolved['draft'] = $draft;
		$resolved['node'] = $node;

		return $resolved;
	}

	/**
	 * A draft is saved unchecked, so before any of it runs it passes the check the publication makes, as the
	 * user of the run: a user who may not publish an action may not run it either.
	 *
	 * @return Error[]
	 */
	private function validateDraft(int $userId, array $draft): array
	{
		$loader = \CBPWorkflowTemplateLoader::getLoader();
		$user = new \CBPWorkflowTemplateUser($userId);
		$activityNames = [];
		$errors = [];

		foreach ($draft['TEMPLATE_DATA']['TEMPLATE'] as $rootActivity)
		{
			$validationErrors = $loader->validateTemplate(
				$rootActivity,
				$user,
				$activityNames,
				true,
				Workflow::getComplexType(),
			);

			foreach ($validationErrors as $error)
			{
				$errors[] = new Error(trim((string)$error['message']), $error['code'] ?? 0);
			}
		}

		return $errors;
	}

	private function findTriggerNode(array $draft, string $blockId): ?array
	{
		$template = $draft['TEMPLATE_DATA']['TEMPLATE'] ?? [];
		if (!is_array($template))
		{
			return null;
		}

		$node = \CBPWorkflowTemplateLoader::findActivityByName($template, $blockId);

		return is_array($node) && $this->isTrigger((string)($node['Type'] ?? '')) ? $node : null;
	}

	/** The substitution of outputs is written in {@see BaseTrigger}, so a wider kind of trigger is refused. */
	private function isTrigger(string $type): bool
	{
		if ($type === '' || !\CBPRuntime::getRuntime()->includeActivityFile($type))
		{
			return false;
		}

		return is_subclass_of('CBP' . $type, BaseTrigger::class);
	}

	/** @return FieldDescription[] */
	private function describeReturnFields(array $node): array
	{
		$fields = [];

		foreach (\CBPRuntime::getRuntime()->getActivityReturnProperties($node) as $name => $property)
		{
			$fields[] = new FieldDescription(
				name: (string)$name,
				title: (string)($property['Name'] ?? $name),
				property: $property,
			);
		}

		return $fields;
	}

	/** The button lives in the new designer alone, and the same holds for a call made past it. */
	private function checkTemplate(int $templateId): ?ErrorCode
	{
		$template = WorkflowTemplateTable::getByPrimary(
			$templateId,
			['select' => ['TYPE', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE']],
		)->fetchObject();

		if ($template === null)
		{
			return ErrorCode::DraftNotFound;
		}

		if ($template->getType() !== WorkflowTemplateType::Nodes->value)
		{
			return ErrorCode::TriggerNotSupported;
		}

		if ($template->getDocumentComplexType() !== Workflow::getComplexType())
		{
			return ErrorCode::DocumentSourceUnavailable;
		}

		return null;
	}

	/** A trigger on a document of its own or handing a document out needs a document the run cannot give. */
	private function needsDocument(array $node): bool
	{
		$eventDocumentType = ServiceContainer::instance()->getComplexActivityService()->getPublishedDocumentTypeForNode(
			(string)$node['Type'],
			is_array($node['Properties'] ?? null) ? $node['Properties'] : [],
		);
		if ($eventDocumentType !== null)
		{
			return true;
		}

		foreach (\CBPRuntime::getRuntime()->getActivityReturnProperties($node) as $property)
		{
			if ($this->isDocumentProperty($property))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Data: `values` by the name of the output. A value that does not parse refuses the start with the names
	 * of such outputs in the order of the form.
	 */
	private function normalizeValues(array $documentType, array $properties, array $values): Result
	{
		$documentService = \CBPRuntime::getRuntime()->getDocumentService();
		$normalized = [];
		$invalidFields = [];

		foreach ($properties as $name => $property)
		{
			if (!array_key_exists($name, $values) || \CBPHelper::isEmptyValue($values[$name]))
			{
				continue;
			}

			$fieldErrors = [];
			$normalized[$name] = $documentService->getFieldInputValue(
				$documentType,
				$property,
				$name,
				[$name => $values[$name]],
				$fieldErrors,
			);

			if (!empty($fieldErrors))
			{
				$invalidFields[] = (string)$name;
			}
		}

		$result = new Result();
		if (!empty($invalidFields))
		{
			return $result->addError(ErrorCode::InvalidValues->createError(['fields' => $invalidFields]));
		}

		return $result->setData(['values' => $normalized]);
	}

	private function isDocumentProperty(array $property): bool
	{
		return ($property['Type'] ?? '') === FieldType::DOCUMENT;
	}
}
