<?php

namespace Bitrix\BizprocDesigner\Infrastructure\Controller;

use Bitrix\Bizproc\Api;
use Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService\TemplateChangeAttribution;
use Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService\TemplateChangeEntry;
use Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService\TemplateChangeEvent;
use Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService\TemplateVersion;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateLockService\LockOwnerDto;
use Bitrix\Bizproc\Api\Enum\Template\TemplateChangeRequestSource;
use Bitrix\Bizproc\Infrastructure\Stepper\TerminateWorkflowStepper;
use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Version\TemplateRevisionService;
use Bitrix\Bizproc\Public\Command\WorkflowTemplate\UpdateWorkflowTemplate\UpdateWorkflowTemplateCommand;
use Bitrix\Bizproc\Public\ProcessData\Service\ProcessDataMechanicsResolver;
use Bitrix\Bizproc\Public\Service\LastValues\LastValuesService;
use Bitrix\Bizproc\Public\Service\SetupTemplate\SetupTemplateService;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Bizproc\Public\Service\WorkflowTemplate\Dto\PilotEditorState;
use Bitrix\Bizproc\Public\Service\WorkflowTemplate\Dto\PilotPublicationRequest;
use Bitrix\Bizproc\Public\Service\WorkflowTemplate\PilotEditorStateService;
use Bitrix\Bizproc\Public\Service\WorkflowTemplate\PilotPublicationService;
use Bitrix\Bizproc\UI\UserView;
use Bitrix\Bizproc\Workflow\Template\AgentOwnedMetadata;
use Bitrix\Bizproc\Workflow\Template\AgentOwnedMetadataService;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\Bizproc\Workflow\Template\Converter\TemplateToNodes;
use Bitrix\Bizproc\Workflow\Template\Converter\SequentialToNodeWorkflow;
use Bitrix\BizprocDesigner\Infrastructure\Enum\StartTrigger;
use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\BizprocDesigner\Internal\Config\TemplateMetadataLimits;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentWebhookService;
use Bitrix\BizprocDesigner\Internal\Integration\Pull\Enum\BizprocDesignerPullEvent;
use Bitrix\BizprocDesigner\Internal\Service\TemplateEditAccess;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\OperationAuthorizationGuard;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\UserAdminResolver;
use Bitrix\BizprocDesigner\Internal\Integration\Pull\TemplateNoteWatch;
use Bitrix\BizprocDesigner\Internal\Service\Activity\ReadableExpressionContextFactory;
use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\BizprocDesigner\Internal\Service\NodeFilter\FoundItemsService;
use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Diag\ExceptionHandlerLog;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Request;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\UI\EntitySelector\Dialog as EntitySelectorDialog;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Diagram extends JsonController
{
	public const DESCRIPTION_MAX_LENGTH = TemplateMetadataLimits::DESCRIPTION_MAX_LENGTH;

	private const PUBLISH_MODE_MAIN = 'main';
	private const PUBLISH_MODE_USER = 'user';
	private const PUBLISH_MODE_FULL = 'full';

	/**
	 * How far past the moment of the write the boundary of the stopping is put, in seconds.
	 *
	 * The boundary errs on the side of stopping too much, and that is the decision of the owner of the
	 * feature (2026-09-04): this format exists for the case where the scheme is wrong and its processes have
	 * to be stopped now. A process of the new scheme started within these seconds is stopped along with the
	 * outdated ones, and its author can start it again; an outdated process nobody stopped runs to the end
	 * by a scheme that is no longer published, and nothing puts that right.
	 *
	 * What the margin is for. The new scheme becomes visible to other requests only when the transaction of
	 * the publication commits, while the boundary is the moment of the write inside it. A process that read
	 * the previous scheme before that commit can therefore write its row after the boundary - and without
	 * the margin no step of the job would ever select it. The margin covers the tail of such starts.
	 *
	 * It does not remove the case: a start held up longer than this still slips through. Removing it would
	 * take either a version stamp on every instance or a lock on the start of every process of the portal
	 * against publications - both were weighed and left out (see the ADR of the feature package).
	 */
	private const TERMINATION_BOUNDARY_MARGIN_SECONDS = 5;

	private const CONFIRMATION_SETTINGS_FREEZE = 'settingsFreeze';
	private const CONFIRMATION_EMPTY_CONSTANTS = 'emptyConstants';
	private const CONFIRMATION_DRAFT_OVERWRITE = 'draftOverwrite';
	private const CONFIRMATION_NO_COMMON_VERSION = 'noCommonVersion';
	private const CONFIRMATION_PILOT_STOP = 'pilotStop';
	private const CONFIRMATION_STOP_RUNNING_PROCESSES = 'stopRunningProcesses';

	// The holder of the editing lease is read through the entity selector, the same way the client
	// reads any other user of the editor.
	private const USER_ENTITY_ID = 'user';

	/** Where the reading of the lease holder reports a provider that answered nothing. */
	private const LOGGER_ID = 'bizprocdesigner.templatelock.owner_view';

	private const ERROR_CONFIRMATION_REQUIRED = 'CONFIRMATION_REQUIRED';
	private const ERROR_PILOT_CONSTANTS_EMPTY = 'PILOT_CONSTANTS_EMPTY';

	/** Fields of the template the pilot version is made of; the rest of the row is shared metadata. */
	private const EXECUTABLE_FIELDS = ['TEMPLATE', 'PARAMETERS', 'VARIABLES', 'CONSTANTS'];

	/** Fields the scheme on the canvas declares, so they follow the scheme the canvas was filled from. */
	private const SCHEME_OWNED_META_FIELDS = ['CONSTANTS', 'VARIABLES', 'PARAMETERS'];

	private ?TemplateAccessService $accessService = null;
	private ?TemplateEditAccess $editAccess = null;
	private ?Api\Service\WorkflowTemplateLockService $lockService = null;
	private ?LoggerInterface $logger = null;
	private ?ReadableExpressionContextFactory $readableExpressionContextFactory = null;
	private TemplateNoteWatch $noteWatch;

	public function __construct(?Request $request = null, ?TemplateNoteWatch $noteWatch = null)
	{
		parent::__construct($request);

		$this->noteWatch = $noteWatch ?? new TemplateNoteWatch();
	}

	/**
	 * @throws LoaderException
	 */
	protected function init(): void
	{
		parent::init();

		Loader::requireModule('bizproc');
	}

	private function accessService(): TemplateAccessService
	{
		return $this->accessService ??= new TemplateAccessService();
	}

	/**
	 * The single point of the fork "Nodes template against a legacy one" the controller shares with the agent
	 * service; the ACL instance is the memoized one, so the fork costs the controller no extra object.
	 */
	private function editAccess(): TemplateEditAccess
	{
		return $this->editAccess ??= new TemplateEditAccess($this->accessService());
	}

	/**
	 * The single point where the lease storage is reached, so that a test can put an unavailable one in its
	 * place: the two write paths answer such a storage differently and the difference has to be reproducible.
	 */
	protected function lockService(): Api\Service\WorkflowTemplateLockService
	{
		return $this->lockService ??= new Api\Service\WorkflowTemplateLockService();
	}

	/**
	 * A logger id that is switched off leaves a NullLogger in its place: the records of this controller
	 * are diagnostics of a degradation, and none of its paths may depend on them being written.
	 */
	private function logger(): LoggerInterface
	{
		return $this->logger ??= (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
	}

	/**
	 * One instance per request, shared by every scheme it converts: each names its own declarations and its
	 * own steps, while the descriptors they have in common are described once.
	 */
	private function readableExpressionContextFactory(): ReadableExpressionContextFactory
	{
		return $this->readableExpressionContextFactory ??= new ReadableExpressionContextFactory();
	}

	public function getAction(
		int $templateId = 0,
		?array $documentType = null,
		?string $startTrigger = null,
	): ?array
	{
		$validatedStartTrigger = $this->validateStartTrigger($startTrigger);

		$data = $this->getTemplateData($templateId, $documentType, $validatedStartTrigger);

		if ($data)
		{
			$companyName = \Bitrix\Main\Config\Option::get('bitrix24', 'site_title');
			$data['companyName'] = $companyName;

			// The scheme is open now, and its discussions change under the hands of the other editors. The
			// answer above is proof that the right to this template has been checked, so the subscription
			// to its live channel is handed out here and the tag is never taken from the client.
			$this->noteWatch->subscribe((int)$data['templateId'], (int)CurrentUser::get()->getId());
		}

		return $data;
	}

	/**
	 * Values of the last finished run of the template, keyed by the inspector expression. Missing data
	 * is an empty map, not an error: the data block must open even when there is nothing to show.
	 *
	 * @return array{values: array<string, array>|\stdClass}|null
	 */
	public function getLastRunValuesAction(int $templateId): ?array
	{
		$userId = $this->authorizeLastRunValuesReading($templateId);
		if ($userId === null)
		{
			return null;
		}

		$values = (new LastValuesService())->getValues($templateId, $userId);

		// identifiers of the documents found by a node filter never reach the client: the panel reads
		// those results through getNodeFilterFoundItems instead
		$values = (new FoundItemsService())->excludeFilterResults($templateId, $values);

		return [
			// the map must stay a JSON object for the client even when it is empty
			'values' => $values === [] ? new \stdClass() : $values,
		];
	}

	/**
	 * Available items of a node filter result: the documents of the last run the caller may read, with
	 * their title and address. An expression the filters of the template do not publish is answered with
	 * an empty list and not an error - the data block must open in any case.
	 *
	 * @return array{state: string, items: list<array{id: string, title: string, url: string}>, hasMore: bool}|null
	 */
	public function getNodeFilterFoundItemsAction(int $templateId, string $expression): ?array
	{
		$userId = $this->authorizeLastRunValuesReading($templateId);
		if ($userId === null)
		{
			return null;
		}

		return (new FoundItemsService())->getFoundItems($templateId, $expression, $userId);
	}

	/**
	 * Values of the requested fields of one found item, in the record shape of the snapshot. The item is
	 * addressed by its position in the list getNodeFilterFoundItems handed out; no such position, no right
	 * on the document and a document that is gone share one refusal, so the reasons stay indistinguishable.
	 *
	 * @param list<string> $fields
	 * @return array{values: array<string, array>|\stdClass}|null
	 */
	public function getNodeFilterItemValuesAction(
		int $templateId,
		string $expression,
		string $itemId,
		array $fields = [],
	): ?array
	{
		$userId = $this->authorizeLastRunValuesReading($templateId);
		if ($userId === null)
		{
			return null;
		}

		$values = (new FoundItemsService())->getItemValues(
			$templateId,
			$expression,
			$itemId,
			$fields,
			$userId,
		);

		if ($values === null)
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

			return null;
		}

		return [
			// the map must stay a JSON object for the client even when no field was asked for
			'values' => $values === [] ? new \stdClass() : $values,
		];
	}

	/**
	 * Published versions of the template, freshest first. Rights are checked by the history service on
	 * every call, so the action only carries the coded errors of the service to the client.
	 */
	public function getVersionsAction(int $templateId): ?array
	{
		$response = (new Api\Service\WorkflowTemplateHistoryService())->getVersions(
			new Api\Request\WorkflowTemplateHistoryService\GetVersionsRequest(
				$templateId,
				(int)CurrentUser::get()->getId(),
			),
		);

		if (!$response->isSuccess())
		{
			$this->addErrors($response->getErrors());

			return null;
		}

		return ['versions' => array_map(self::convertVersion(...), $response->getVersions())];
	}

	/**
	 * The snapshot of a version travels to the graph through the very converter the regular load uses,
	 * otherwise viewing a version would diverge from opening the template.
	 */
	public function getVersionAction(int $templateId, int $versionId): ?array
	{
		$response = (new Api\Service\WorkflowTemplateHistoryService())->getVersion(
			new Api\Request\WorkflowTemplateHistoryService\GetVersionRequest(
				$templateId,
				$versionId,
				(int)CurrentUser::get()->getId(),
			),
		);

		if (!$response->isSuccess())
		{
			$this->addErrors($response->getErrors());

			return null;
		}

		// The graph comes from the snapshot and the rest of the surface is read-only, so the body of the
		// published template has no reader here and never travels to the client.
		$tpl = $this->getTpl($templateId, ['ID', 'NAME', 'DESCRIPTION', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE']);
		if ($tpl === null)
		{
			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError(['#ID#' => $templateId]));

			return null;
		}

		$snapshot = $response->getTemplateData();
		[$blocks, $connections] = (new TemplateToNodes(
			$snapshot['TEMPLATE'] ?? [],
			Feature::instance()->areComplexNodeConnectionsAvailable(),
			// A version is named by its own snapshot: the live row may have moved on since it was published.
			baseContentBlockContext: $this->readableExpressionContextFactory()->createForAuthorizedSchema(
				$snapshot,
				$tpl->getDocumentComplexType(),
			),
		))->convert();

		// The name and the description belong to the version as much as its graph does, and a restore puts
		// them back from the snapshot, so the view shows them from there too and falls back to the live row.
		$snapshotMeta = array_intersect_key(
			$snapshot,
			array_flip(['CONSTANTS', 'VARIABLES', 'PARAMETERS', 'NAME', 'DESCRIPTION']),
		);

		return [
			'version' => self::convertVersion($response->getVersion()),
			'template' => array_merge($tpl->collectValues(), $snapshotMeta, [
				// The mechanics of the template belong to its row and not to the version, and the ORM answers
				// the settings as a relation the row does not carry, so the marker is read on its own here as
				// well: without it the view of a version would take the template for a legacy one (ALG-01).
				'TEMPLATE_SETTINGS' => $this->processDataSettings($templateId),
			]),
			'blocks' => $blocks,
			'connections' => $connections,
		];
	}

	/**
	 * The editor must switch to the returned draft: autosave writing into the previous one would
	 * overwrite the backup copy left behind by the restore.
	 */
	public function restoreVersionAction(int $templateId, int $versionId): ?array
	{
		if (!$this->authorizeHistoryWrite($templateId))
		{
			return null;
		}

		$response = (new Api\Service\WorkflowTemplateHistoryService())->restoreVersion(
			new Api\Request\WorkflowTemplateHistoryService\RestoreVersionRequest(
				$templateId,
				$versionId,
				(int)CurrentUser::get()->getId(),
			),
		);

		if (!$response->isSuccess())
		{
			$this->addErrors($response->getErrors());

			return null;
		}

		return [
			'draftId' => $response->getDraftId(),
			'draftRevision' => Feature::instance()->isTemplateEditLockAvailable()
				? $response->getDraftRevision()
				: 0,
		];
	}

	/**
	 * A null draftId means the template is left without drafts and the editor returns to the published
	 * configuration.
	 */
	public function undoRestoreAction(int $templateId): ?array
	{
		if (!$this->authorizeHistoryWrite($templateId))
		{
			return null;
		}

		$response = (new Api\Service\WorkflowTemplateHistoryService())
			->undoRestore($templateId, (int)CurrentUser::get()->getId())
		;

		if (!$response->isSuccess())
		{
			$this->addErrors($response->getErrors());

			return null;
		}

		return [
			'draftId' => $response->getDraftId(),
			'draftRevision' => Feature::instance()->isTemplateEditLockAvailable()
				? $response->getDraftRevision()
				: 0,
		];
	}

	public function discardRestoreBackupAction(int $templateId): ?array
	{
		if (!$this->authorizeHistoryWrite($templateId))
		{
			return null;
		}

		$response = (new Api\Service\WorkflowTemplateHistoryService())
			->discardRestoreBackup($templateId, (int)CurrentUser::get()->getId())
		;

		if (!$response->isSuccess())
		{
			$this->addErrors($response->getErrors());

			return null;
		}

		return [];
	}

	private static function convertVersion(TemplateVersion $version): array
	{
		return [
			'id' => $version->id,
			'versionNumber' => $version->versionNumber,
			'publicationType' => $version->publicationType,
			'createdTimestamp' => $version->createdTimestamp,
			'authorId' => $version->authorId,
			'isCurrent' => $version->isCurrent,
		];
	}

	/**
	 * The journal of the template, freshest first, page by page. Rights are checked by the history service
	 * on every call. Authors travel as ids: the editor resolves their names itself, the same way it does
	 * for the authors of the versions.
	 */
	public function getChangeEventsAction(int $templateId, ?int $cursor = null, ?int $limit = null): ?array
	{
		$response = (new Api\Service\WorkflowTemplateHistoryService())->getChangeEvents(
			new Api\Request\WorkflowTemplateHistoryService\GetChangeEventsRequest(
				$templateId,
				(int)CurrentUser::get()->getId(),
				$cursor,
				$limit,
			),
		);

		if (!$response->isSuccess())
		{
			$this->addErrors($response->getErrors());

			return null;
		}

		return [
			'events' => array_map(
				static fn(TemplateChangeEvent $event): array => $event->toArray(),
				$response->getEvents(),
			),
			'nextCursor' => $response->getNextCursor(),
		];
	}

	/**
	 * The difference carried by a single event. Rights are checked by the history service on every call, so
	 * the right to read the list is never inherited here. The changes travel structured: the wording of a
	 * line is built by the view, not by the server.
	 */
	public function getChangeEventAction(int $templateId, int $eventId): ?array
	{
		$response = (new Api\Service\WorkflowTemplateHistoryService())->getChangeEvent(
			new Api\Request\WorkflowTemplateHistoryService\GetChangeEventRequest(
				$templateId,
				$eventId,
				(int)CurrentUser::get()->getId(),
			),
		);

		if (!$response->isSuccess())
		{
			$this->addErrors($response->getErrors());

			return null;
		}

		return [
			'event' => $response->getEvent()->toArray(),
			'changes' => array_map(
				static fn(TemplateChangeEntry $entry): array => $entry->toArray(),
				$response->getChanges(),
			),
		];
	}

	public function publicateAction(): ?array
	{
		$diagramData = $this->prepareDiagramData();

		if ($diagramData === null)
		{
			return null;
		}

		$templateId = $diagramData['templateId'];
		$tpl = $diagramData['tpl'];
		$user = $diagramData['user'];

		if ($templateId > 0 && $tpl === null)
		{
			// Same anti-oracle guard as getTemplateData: a missing record must not reveal its
			// (non-)existence to a non-admin CreateWorkflow author, who otherwise passes the
			// document-type check in prepareDiagramData.
			if (!$user->isAdmin())
			{
				$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

				return null;
			}

			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError(['#ID#' => $templateId]));

			return null;
		}

		$isLegacyType = $tpl !== null
			&& $tpl->getType() !== Api\Enum\Template\WorkflowTemplateType::Nodes->value;

		// Publish = activation of the live Nodes template; a non-Nodes row is outside the template ACL and
		// keeps the legacy document-type gate. Both authorize against the document type of the template
		// itself, and both run before the type check, so a template the user may not touch always answers
		// with a uniform ACCESS_DENIED (no existence/type oracle, no cross-type overwrite).
		$canPublish = $isLegacyType
			? $this->canWriteTemplate($tpl, $user)
			: $this->accessService()->canPublish($templateId, $user->getId(), $diagramData['documentType']);

		// Publishing with no template id creates one, and a scope check answers "yes" for an id that does not
		// exist yet, so it cannot stand in for the CREATE toggler the regular create path requires.
		if ($templateId === 0)
		{
			$canPublish = $canPublish
				&& $this->accessService()->canCreate($user->getId(), $diagramData['documentType']);
		}

		if (!$canPublish)
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

			return null;
		}

		if ($isLegacyType)
		{
			$this->addError(
				new Error(Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_ERROR_TEMPLATE_TYPE')),
			);

			return null;
		}

		if ($templateId > 0 && !$this->acquireTemplateLease($templateId, (int)$user->getId()))
		{
			return null;
		}

		if (!$this->validateDescription($diagramData['fields']))
		{
			return null;
		}

		// A publication to an audience is a write path of its own and never saves the live row: the common
		// version stays as it is, and the pilot version is stored beside it.
		if ($diagramData['publishMode'] === self::PUBLISH_MODE_USER)
		{
			return $this->publishPilot($templateId, $diagramData, $user);
		}

		// The mode that stops the outdated processes publishes the canvas by one path, and its branch stands
		// above the promotion of the pilot snapshot on purpose: the promotion is entered by the pilotStop
		// confirmation alone, which this mode sends in its second round, so below that branch the request
		// would promote a snapshot even when the pilot is already gone.
		if ($diagramData['publishMode'] === self::PUBLISH_MODE_FULL)
		{
			return $this->publishAndStopRunningProcesses($templateId, $diagramData, $user);
		}

		$pilotState = $this->pilotState($templateId);
		if (
			$pilotState->hasPilot
			|| $diagramData['pilotId'] > 0
			|| $diagramData['revision'] !== ''
			|| self::isConfirmed($diagramData['confirmations'], self::CONFIRMATION_PILOT_STOP)
		)
		{
			return $this->publishPilotToEveryone($templateId, $diagramData, $user);
		}

		$published = $this->saveTemplate(
			$templateId,
			$this->normalizeSetupConstantsInFields($diagramData['fields']),
			$user,
			$diagramData['draftId'],
			$diagramData['draftRevision'],
		);

		if ($published === null)
		{
			return null;
		}

		$published['pilot'] = null;

		return $published;
	}

	/**
	 * Promotes the exact pilot version the publisher confirmed. The identity and revision come from the
	 * pilot state shown by the editor, so a replaced pilot cannot be removed by an old tab.
	 */
	private function publishPilotToEveryone(
		int $templateId,
		array $diagramData,
		\CBPWorkflowTemplateUser $user,
	): ?array
	{
		if (!self::isConfirmed($diagramData['confirmations'], self::CONFIRMATION_PILOT_STOP))
		{
			$this->addError(
				new Error(
					Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_PILOT_STOP_CONFIRMATION'),
					self::ERROR_CONFIRMATION_REQUIRED,
				),
			);

			return ['required' => [self::CONFIRMATION_PILOT_STOP]];
		}

		$published = (new PilotPublicationService())->publishToEveryone(
			$templateId,
			$user->getId(),
			$diagramData['pilotId'],
			$diagramData['revision'],
		);
		if (!$published->isSuccess())
		{
			return $this->refusePilotOperation($published);
		}

		return ['templateId' => $templateId, 'pilot' => null];
	}

	/**
	 * Publishes the canvas as the common version and hands the processes started before that moment over to
	 * the job that stops them. What the author sees is published whether a pilot is live or not: the mode
	 * promises the updated scheme and stops the running processes irreversibly, so publishing anything but
	 * the canvas is not an option here. A live pilot goes out by the cascade over the write of the common
	 * scheme, and needs no code of its own.
	 *
	 * The write goes through the ordinary publication of the common version: the freeze of the settings
	 * lets a write through only when it declares itself one, and a path of our own would be refused
	 * exactly in the case the mode exists for.
	 *
	 * @param array{fields: array, confirmations: string[]} $diagramData
	 * @return array{templateId: int, pilot: null}|array{required: string[]}|array{activityErrors: array}|null
	 */
	private function publishAndStopRunningProcesses(
		int $templateId,
		array $diagramData,
		\CBPWorkflowTemplateUser $user,
	): ?array
	{
		$fields = $this->normalizeSetupConstantsInFields($diagramData['fields']);

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$published = $this->askConfirmationsAndWriteScheme($templateId, $diagramData, $fields, $user);

			// A refused write leaves the previous scheme running, and then there is nothing to stop. The id
			// is the answer of the write: publishing without one creates the template, and the job is about
			// the row that was actually written.
			$publishedTemplateId = (int)($published['templateId'] ?? 0);

			// Read here and not after the transaction, although the job is scheduled after it: past the
			// commit the lock is gone, and a publication of the same template landing in between would hand
			// us the moment of ITS write. The boundary would then be later than our own scheme became the
			// common one, and every process started by our scheme in that window would be stopped for good.
			$terminationBoundary = $publishedTemplateId > 0 ? self::terminationBoundary($publishedTemplateId) : null;

			// Committed on every way out that has an answer, a refusal included: the transaction is here to
			// hold the lock over the question and the write, and not to undo the write - what a refused
			// write leaves behind is the business of the write.
			$connection->commitTransaction();
		}
		catch (\Throwable $failure)
		{
			self::rollback($connection);

			throw $failure;
		}

		if ($published === null)
		{
			return null;
		}

		if ($publishedTemplateId <= 0 || $terminationBoundary === null)
		{
			return $published;
		}


		// A template created by this very request never ran anything, so there is nothing of a previous scheme
		// to stop: scheduling the job would buy the portal an agent record and an empty first step. The
		// confirmation is still asked for above - it is asked without counting the processes on purpose.
		if ($templateId > 0)
		{
			// The answer of the scheduling reaches the author. The scheme is published either way, so this
			// is not a failure of the publication and must not be answered as one - the transport turns any
			// errors of a response into an exception, and a successful publication would read as a refusal.
			// It is not a success to report as one either: without the job the processes of the previous
			// scheme keep running, and nothing else says so - the answer is the only trace of that outcome.
			//
			// Scheduled outside the transaction above on purpose: the record of the job is not part of the
			// publication, and a publication that goes through has to stand even when the scheduling does not.
			$published['terminationScheduled'] = self::scheduleTermination(
				$publishedTemplateId,
				$terminationBoundary,
			);
		}

		// Last of all, and after the scheduling on purpose. The write left the clearing to us (it defers it
		// under a transaction of a caller), and it belongs after the real commit: until then the row holds
		// the previous scheme for everybody else, so a reader of that window would cache the scheme being
		// replaced with nothing left to clear it again. Why after the scheduling: past the commit the
		// publication is irreversible, the stop of the outdated processes is what this mode promises, and a
		// stale cache is a nuisance the next write clears. Housekeeping must not stand in front of a promise.
		//
		// Plain call, in one attempt and with nothing wrapped around it: the same as the publication of a
		// pilot version to everybody does it ({@see PilotPublicationService::publishToEveryone()} clears the
		// very same three things after its own commit). A failure of the clearing therefore reaches the
		// author as a failure of the request, although the scheme is published and the job is scheduled by
		// then - the decision of the owner of the feature (2026-09-04) to keep this path the way the module
		// already does it, rather than to invent a form of its own for it.
		\CBPWorkflowTemplateLoader::invalidateCachesAfterUpdate($publishedTemplateId, $fields);

		$published['pilot'] = null;

		return $published;
	}

	/**
	 * The question the publisher has to answer and the write of the canvas as the common version - one
	 * decision, made under the row lock every write of a pilot version takes.
	 *
	 * The lock is what keeps the two from being separated by a publication for a chosen audience: a pilot
	 * that lands between the question and the write is taken out by the cascade over the write of the common
	 * scheme, and its audience would lose the scheme without the publisher here having been asked about it.
	 *
	 * @param array{fields: array, confirmations: string[]} $diagramData
	 * @return array{templateId: int}|array{required: string[]}|array{activityErrors: array}|null
	 */
	private function askConfirmationsAndWriteScheme(
		int $templateId,
		array $diagramData,
		array $fields,
		\CBPWorkflowTemplateUser $user,
	): ?array
	{
		// A template that does not exist yet has no row to lock and can carry no pilot - the row appears
		// with the write below. The answer of the lock is not consulted for the same reason: a row that is
		// gone leaves the write to say so, in the words the write says it in.
		if ($templateId > 0)
		{
			(new PilotPublicationService())->lockTemplateForPublication($templateId);
		}

		$required = $this->findRequiredTerminationConfirmations($templateId, $diagramData['confirmations']);
		if ($required)
		{
			// The message names the consequence the answer actually asks about. The rounds of this mode do not
			// always ask about the same one: the stop of the running processes leads, but a publication for a
			// chosen audience that appeared between the rounds can be the only consequence left - and telling
			// the publisher about the stop they have already agreed to would name the wrong reason.
			$phrase = in_array(self::CONFIRMATION_STOP_RUNNING_PROCESSES, $required, true)
				? 'BIZPROCDESIGNER_CONTROLLER_DIAGRAM_STOP_RUNNING_PROCESSES_CONFIRMATION'
				: 'BIZPROCDESIGNER_CONTROLLER_DIAGRAM_PILOT_STOP_CONFIRMATION'
			;

			$this->addError(new Error(Loc::getMessage($phrase), self::ERROR_CONFIRMATION_REQUIRED));

			return ['required' => $required];
		}

		// The transaction of this mode is ours, so the cache must be cleared after it commits.
		return $this->saveTemplate(
			$templateId,
			$fields,
			$user,
			draftId: (int)($diagramData['draftId'] ?? 0),
			draftRevision: isset($diagramData['draftRevision']) ? (int)$diagramData['draftRevision'] : null,
			inOuterTransaction: true,
		);
	}

	/**
	 * Schedules the job that stops the outdated processes, and answers whether it is there afterwards.
	 *
	 * A failure of the scheduling is not a failure of the publication: the scheme is already the common one
	 * by now, and the transport would turn an exception into "the publication failed" - telling the author
	 * the opposite of what happened and inviting them to publish again. So a throwable is intercepted here
	 * and becomes the answer the contract already has for that outcome: the stop was not scheduled.
	 *
	 * The intercepted throwable goes to the log of the exception handler, by the same rule the termination
	 * command follows: what is caught and not passed on is written down where it was caught, and it is the
	 * throwable itself that is written, because only its trace says what went wrong in the platform.
	 */
	private static function scheduleTermination(int $templateId, DateTime $terminationBoundary): bool
	{
		try
		{
			return TerminateWorkflowStepper::bindTemplate($templateId, $terminationBoundary);
		}
		catch (\Throwable $failure)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog(
				$failure,
				ExceptionHandlerLog::CAUGHT_EXCEPTION,
			);

			return false;
		}
	}

	/**
	 * The platform reports a nested rollback as unsupported once it has rolled back to the savepoint, and
	 * that report must not replace the failure being carried out.
	 */
	private static function rollback(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException)
		{
		}
	}

	/**
	 * The boundary of the stopping: the moment the scheme became the common one, plus the margin of
	 * {@see TERMINATION_BOUNDARY_MARGIN_SECONDS} seconds.
	 *
	 * The moment is the MODIFIED of the written row, which the loader sets within the write itself. To be
	 * read under the same lock as the write, before the transaction of the publication ends: the row belongs
	 * to whoever holds the lock, and once it is released the moment on it can be somebody else's.
	 *
	 * Deliberately not a moment read off the clock around the write, and not one taken inside the job. Taken
	 * before the write, the boundary leaves every process of the window of the write running for good - until
	 * the write goes through, the previous scheme is still the one that starts processes. Taken inside the
	 * job, it catches everything started between the publication and the first step of the job, which is a
	 * window of unknown length.
	 */
	private static function terminationBoundary(int $templateId): DateTime
	{
		$written = WorkflowTemplateTable::getRow([
			'select' => ['MODIFIED'],
			'filter' => ['=ID' => $templateId],
		]);

		$modified = $written['MODIFIED'] ?? null;

		// The row has just been written, so its own moment is there to be read; the reserve is for a row that
		// cannot be read back at all, and it is the moment of the reading - the nearest thing left.
		$moment = $modified instanceof DateTime ? $modified : new DateTime();

		return (clone $moment)->add(self::TERMINATION_BOUNDARY_MARGIN_SECONDS . ' seconds');
	}

	public function publicateDraftAction(): ?array
	{
		$diagramData = $this->prepareDiagramData();

		if ($diagramData === null)
		{
			return null;
		}

		$templateId = $diagramData['templateId'];
		$tpl = $diagramData['tpl'];
		$user = $diagramData['user'];

		if ($templateId > 0 && $tpl === null)
		{
			// Same anti-oracle guard as getTemplateData: a missing record must not reveal its
			// (non-)existence to a non-admin CreateWorkflow author, who otherwise passes the
			// document-type check in prepareDiagramData.
			if (!$user->isAdmin())
			{
				$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

				return null;
			}

			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError(['#ID#' => $templateId]));

			return null;
		}

		$isLegacyType = $tpl !== null
			&& $tpl->getType() !== Api\Enum\Template\WorkflowTemplateType::Nodes->value;

		// Draft save = editing; strictly weaker than publish. Same layering as publicateAction: the ACL for
		// Nodes, the legacy document-type gate for the rest, both before the type check.
		$canEdit = $isLegacyType
			? $this->canWriteTemplate($tpl, $user)
			: $this->accessService()->canEdit($templateId, $user->getId(), $diagramData['documentType']);

		// Publishing with no template id creates one, and a scope check answers "yes" for an id that does not
		// exist yet, so it cannot stand in for the CREATE toggler the regular create path requires.
		if ($templateId === 0)
		{
			$canEdit = $canEdit
				&& $this->accessService()->canCreate($user->getId(), $diagramData['documentType']);
		}

		if (!$canEdit)
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

			return null;
		}

		if ($isLegacyType)
		{
			return ['templateDraftId' => 0];
		}

		if (
			$templateId > 0
			&& !$this->acquireTemplateLease(
				$templateId,
				(int)$user->getId(),
				$diagramData['draftId'],
				$diagramData['draftRevision'],
			)
		)
		{
			return null;
		}

		if (!$this->validateDescription($diagramData['fields']))
		{
			return null;
		}

		return $this->saveTemplateDraft(
			$templateId,
			$this->normalizeSetupConstantsInFields($diagramData['fields']),
			$user,
			$diagramData['draftId'],
			$diagramData['draftRevision'],
			TemplateChangeAttribution::forUser($user->getId(), TemplateChangeRequestSource::Editor),
		);
	}

	public function updateTemplateAction(int $templateId, array $data): ?array
	{
		$tpl = $templateId > 0 ? $this->getTpl($templateId) : null;
		// getTpl() only loads a row without a system code, so its type alone decides the participation.
		$isNodesTemplate = $tpl !== null && $tpl->getType() === Api\Enum\Template\WorkflowTemplateType::Nodes->value;
		if ($isNodesTemplate)
		{
			// Mutating the live Nodes row counts as publish for Nodes templates.
			if (!$this->accessService()->canPublish($templateId, null, $tpl->getDocumentComplexType()))
			{
				$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

				return null;
			}
		}
		else
		{
			// Non-Nodes templates keep the legacy admin gate.
			$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);
			if (!$user->isAdmin())
			{
				$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

				return null;
			}
		}

		// The editor only edits user-facing metadata. Service fields (TYPE, SYSTEM_CODE, DOCUMENT_TYPE) must
		// never be writable here: a PUBLISH holder could otherwise move the row out of the Nodes ACL.
		$data = array_intersect_key($data, array_flip(['NAME', 'DESCRIPTION']));

		$fieldsToRelease = array_keys($data);

		$currentValues = $tpl?->collectValues() ?? [];
		foreach ($data as $field => $value)
		{
			if (($currentValues[$field] ?? null) === $value)
			{
				unset($data[$field]);
			}
		}
		if ($templateId > 0 && $tpl === null)
		{
			// Only an admin reaches this far without a record, so the answer reveals nothing. The check stands
			// before the lease the way it does in publicateAction: a lease left on an id no row answers to is
			// deleted by no cascade, and a system template takes no part in the editor at all.
			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError(['#ID#' => $templateId]));

			return null;
		}

		if (!$data)
		{
			return $this->releaseAgentOwnedMetadata($templateId, $fieldsToRelease);
		}

		// After the early return above: a rename that changes nothing writes nothing and must neither read the
		// lease nor be refused as busy. A non-Nodes row takes no lease at all - it belongs to the legacy
		// editor, and a lease here would block it for the user it really belongs to.
		if ($isNodesTemplate && !$this->acquireTemplateLease($templateId, (int)CurrentUser::get()->getId()))
		{
			return null;
		}

		if (!$this->validateDescription($data))
		{
			return null;
		}

		$prevState = array_intersect_key($currentValues, $data);
		$nextState = $data;
		$modified = new DateTime();
		$currentModified = $currentValues['MODIFIED'] ?? null;
		if ($currentModified instanceof DateTime && $modified->getTimestamp() <= $currentModified->getTimestamp())
		{
			$modified = DateTime::createFromTimestamp($currentModified->getTimestamp() + 1);
		}
		$data['MODIFIED'] = $modified;

		$result = (new UpdateWorkflowTemplateCommand(
			$templateId,
			$data,
			$fieldsToRelease,
		))->run();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		(new Api\Service\WorkflowTemplateHistoryService())->recordChange(
			new Api\Request\WorkflowTemplateHistoryService\RecordChangeRequest(
				templateId: $templateId,
				attribution: TemplateChangeAttribution::forUser(
					(int)CurrentUser::get()->getId(),
					TemplateChangeRequestSource::Editor,
				),
				prevState: $prevState,
				nextState: $nextState,
				isTemplateJournaled: $isNodesTemplate,
			),
		);

		return $result->getData();
	}

	/**
	 * Takes the lease of the template for the caller or extends the one it already holds: which of the two is
	 * needed is decided by the service, so the editor asks for the lease with a single action. A living lease
	 * of somebody else refuses the action and names its owner.
	 */
	public function acquireTemplateLockAction(int $templateId): ?array
	{
		$authorized = $this->authorizeLeaseAction($templateId);
		if ($authorized === null)
		{
			return null;
		}

		if (!Feature::instance()->isTemplateEditLockAvailable())
		{
			// An empty lease would be taken for its own by the session that asked. The feature can only be
			// switched off between the load of the page and the first write, and the refusal keeps that write
			// going: the client closes nothing on a code that names no owner.
			$this->addError(ErrorMessage::FEATURE_DISABLED->getCodedError());

			return null;
		}

		$userId = (int)$authorized['user']->getId();

		if (!$this->acquireTemplateLease($templateId, $userId))
		{
			return null;
		}

		return ['lock' => $this->getLockOwner($templateId, $userId)];
	}

	/**
	 * Gives the lease of the template up. An ordinary caller gives up nothing but its own: the lease of
	 * another user stays where it is and the answer says that nothing was released.
	 */
	public function releaseTemplateLockAction(int $templateId): ?array
	{
		$authorized = $this->authorizeLeaseAction($templateId);
		if ($authorized === null)
		{
			return null;
		}

		if (!Feature::instance()->isTemplateEditLockAvailable())
		{
			return ['released' => false];
		}

		$user = $authorized['user'];
		$userId = (int)$user->getId();

		// The owner is read before the release: afterwards there is nobody left to name to the client.
		$formerOwner = $this->readLeaseOwner($templateId, $userId);

		$response = $this->lockService()->release(
			new Api\Request\WorkflowTemplateLockService\ReleaseLockRequest(
				$templateId,
				$userId,
				$this->isPortalAdmin($userId),
			),
		);

		if (!$response->isSuccess())
		{
			$this->addErrors($response->getErrors());

			return null;
		}

		if ($response->isReleased() && $formerOwner !== null)
		{
			$this->notifyLeaseLost($authorized['tpl'], $formerOwner->userId);
		}

		return ['released' => $response->isReleased()];
	}

	/**
	 * Who edits the template right now. Nobody is an empty owner and not a refusal: the client shows the bar
	 * of the busy template from the same answer it shows an idle one.
	 */
	public function getTemplateLockAction(int $templateId): ?array
	{
		$authorized = $this->authorizeLeaseAction($templateId);
		if ($authorized === null)
		{
			return null;
		}

		return ['lock' => $this->getLockOwner($templateId, (int)$authorized['user']->getId())];
	}

	/**
	 * The audience of the live pilot as the publisher has to see it: an element that has been deleted or has
	 * become unavailable is marked instead of being dropped, so it can be recognized and replaced.
	 *
	 * @return array{audience: list<array>, publishedBy: int|null, publishedByName: string|null,
	 *     publishedAt: string|null}|null
	 */
	public function getPilotAudienceAction(int $templateId): ?array
	{
		$user = $this->authorizePilotTemplate($templateId);
		if ($user === null)
		{
			return null;
		}

		$audience = (new PilotPublicationService())->getAudience($templateId, $user->getId());
		if (!$audience->isSuccess())
		{
			return $this->refusePilotOperation($audience);
		}

		$state = $this->pilotState($templateId);

		return [
			'audience' => array_map(
				static fn(array $item): array => self::describeAudienceItem($item),
				$audience->getData()['items'] ?? [],
			),
			'publishedBy' => $state->publishedBy,
			'publishedByName' => self::formatPilotAuthorById($state->publishedBy),
			'publishedAt' => $state->publishedAt?->format(\DateTimeInterface::ATOM),
		];
	}

	/**
	 * Replaces the audience of the live pilot. The scheme is not published again and the canvas does not
	 * change: who sees the version is not what the version is.
	 *
	 * @param string[] $audience
	 */
	public function changePilotAudienceAction(int $templateId, int $pilotId, array $audience): ?array
	{
		$user = $this->authorizePilotTemplate($templateId);
		if ($user === null)
		{
			return null;
		}

		$changed = (new PilotPublicationService())
			->changeAudience($templateId, self::normalizeAudience($audience), $user->getId(), $pilotId)
		;

		if (!$changed->isSuccess())
		{
			return $this->refusePilotOperation($changed);
		}

		return [
			'pilot' => $this->buildPilotState(
				$this->pilotState($templateId),
				canPublish: true,
				isCanvasPilot: !$this->hasTemplateDraft($templateId),
			),
		];
	}

	/**
	 * Stops the pilot. Every consequence the publisher has to know about is reported at once: the answer
	 * replaces the whole set of the confirmations on the client, so a consequence named a round trip later
	 * would never be confirmed.
	 *
	 * @param string[] $confirmations
	 * @return array{draftSaved: bool}|array{required: string[]}|null
	 */
	public function stopPilotAction(int $templateId, int $pilotId, array $confirmations = []): ?array
	{
		$user = $this->authorizePilotTemplate($templateId);
		if ($user === null)
		{
			return null;
		}

		if (!(new PilotEditorStateService())->hasPilot($templateId))
		{
			$this->addError(
				new Error(
					Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_PILOT_NOT_FOUND'),
					PilotPublicationService::ERROR_PILOT_NOT_FOUND,
				),
			);

			return null;
		}

		$required = $this->findRequiredStopConfirmations($templateId, $confirmations);
		if ($required)
		{
			$this->addError(
				new Error(
					Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_PILOT_STOP_CONFIRMATION'),
					self::ERROR_CONFIRMATION_REQUIRED,
				),
			);

			return ['required' => $required];
		}

		$stopped = (new PilotPublicationService())->stopPilot(
			$templateId,
			$user->getId(),
			$pilotId,
			self::isConfirmed($confirmations, self::CONFIRMATION_DRAFT_OVERWRITE),
		);

		if (!$stopped->isSuccess())
		{
			return $this->refusePilotOperation($stopped);
		}

		return ['draftSaved' => (bool)($stopped->getData()['schemeSavedAsDraft'] ?? false)];
	}

	/**
	 * The publication to an audience, reached once the ordinary publish gate has passed. The feature is
	 * checked before anything is asked of the publisher: a tab opened before the feature was switched off
	 * must be refused, not led through a confirmation into a publication.
	 *
	 * @param array{fields: array, audience: string[], confirmations: string[]} $diagramData
	 */
	private function publishPilot(int $templateId, array $diagramData, \CBPWorkflowTemplateUser $user): ?array
	{
		if (!Feature::instance()->isPilotPublicationAvailable())
		{
			$this->addError(ErrorMessage::FEATURE_DISABLED->getCodedError());

			return null;
		}

		if (!$diagramData['audience'])
		{
			$this->addError(
				new Error(
					Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_PILOT_AUDIENCE_EMPTY'),
					PilotPublicationService::ERROR_AUDIENCE_EMPTY,
				),
			);

			return null;
		}

		$confirmations = $diagramData['confirmations'];

		// while the template has a common version the publication freezes its settings until the pilot is
		// over, and the publisher is told that before it happens
		if (
			$this->hasCommonVersion($templateId)
			&& !self::isConfirmed($confirmations, self::CONFIRMATION_SETTINGS_FREEZE)
		)
		{
			$this->addError(
				new Error(
					Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_PILOT_SETTINGS_FREEZE_CONFIRMATION'),
					self::ERROR_CONFIRMATION_REQUIRED,
				),
			);

			return ['required' => [self::CONFIRMATION_SETTINGS_FREEZE]];
		}

		$published = (new PilotPublicationService())->publish(new PilotPublicationRequest(
			templateId: $templateId,
			executableFields: self::executableFieldsOf($diagramData['fields']),
			accessCodes: $diagramData['audience'],
			publisherId: $user->getId(),
			confirmedEmptySettings: self::isConfirmed($confirmations, self::CONFIRMATION_EMPTY_CONSTANTS),
		));

		if (!$published->isSuccess())
		{
			// the blocks of the scheme a settings refusal points at travel in the body, the way an ordinary
			// publication reports its own errors
			return $this->refusePilotOperation($published, $published->getData());
		}

		return [
			'templateId' => $templateId,
			'pilot' => $this->buildPilotState(
				$this->pilotState($templateId),
				canPublish: true,
				// the publication deletes the draft, so the pilot version is what the canvas shows next
				isCanvasPilot: true,
			),
		];
	}

	/**
	 * The boundary every operation over a live pilot passes: the feature is on, the template exists and the
	 * caller may publish it. A caller who may not edit the template is answered exactly as for a template
	 * that does not exist, so the operation cannot be used to probe a foreign one.
	 */
	private function authorizePilotTemplate(int $templateId): ?\CBPWorkflowTemplateUser
	{
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);

		// the service refuses a write of its own, but reading the audience it answers successfully, so the
		// gate of the feature stands here for all three operations alike
		if (!Feature::instance()->isPilotPublicationAvailable())
		{
			$this->addError(ErrorMessage::FEATURE_DISABLED->getCodedError());

			return null;
		}

		$tpl = $this->getTplForAccessCheck($templateId);
		if ($tpl === null)
		{
			// Same anti-oracle guard as getTemplateData: a missing record must not reveal its
			// (non-)existence to a caller who may not have seen it anyway.
			if (!$user->isAdmin())
			{
				$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

				return null;
			}

			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError(['#ID#' => $templateId]));

			return null;
		}

		if (!$this->canPublishTemplate($tpl, $user))
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

			return null;
		}

		return $user;
	}

	/**
	 * The same split publicateAction() makes: the template ACL for the templates of the new editor and the
	 * legacy document-type right for every other one. No right of its own is introduced for the pilot.
	 */
	private function canPublishTemplate(
		\Bitrix\Bizproc\Workflow\Template\Tpl $tpl,
		\CBPWorkflowTemplateUser $user,
	): bool
	{
		return $tpl->getType() === Api\Enum\Template\WorkflowTemplateType::Nodes->value
			? $this->accessService()->canPublish((int)$tpl->getId(), $user->getId(), $tpl->getDocumentComplexType())
			: $this->canWriteTemplate($tpl, $user)
		;
	}

	/**
	 * The state of the pilot of the template as the module of the processes reports it. While the feature
	 * is off every field of it is empty even when a pilot is stored: a stored pilot is then absent
	 * everywhere the editor looks - on the canvas, in the state it shows and in the operations it offers
	 */
	private function pilotState(int $templateId): PilotEditorState
	{
		return (new PilotEditorStateService())->getEditorState($templateId);
	}

	/**
	 * The state of the pilot as the editor shows it.
	 *
	 * @param bool $canPublish the author, the date and the size of the audience belong to the right of
	 * publication and to nothing weaker
	 * @return array{hasPilot: bool, pilotId: int|null, revision?: string, isCanvasPilot: bool,
	 *     publishedBy: int|null, publishedByName: string|null,
	 *     publishedAt: string|null, audienceCount: int|null, hasCommonVersion: bool, settingsFrozen: bool}
	 */
	private function buildPilotState(PilotEditorState $state, bool $canPublish, bool $isCanvasPilot): array
	{
		$isVisibleToPublisher = $state->hasPilot && $canPublish;

		$pilot = [
			'hasPilot' => $state->hasPilot,
			'pilotId' => $isVisibleToPublisher ? $state->pilotId : null,
			'isCanvasPilot' => $state->hasPilot && $isCanvasPilot,
			'publishedBy' => $isVisibleToPublisher ? $state->publishedBy : null,
			'publishedByName' => $isVisibleToPublisher ? self::formatPilotAuthorById($state->publishedBy) : null,
			'publishedAt' => $isVisibleToPublisher ? $state->publishedAt?->format(\DateTimeInterface::ATOM) : null,
			'audienceCount' => $isVisibleToPublisher ? $state->audienceCount : null,
			'hasCommonVersion' => $state->hasCommonVersion,
			'settingsFrozen' => $state->settingsFrozen,
		];

		if ($isVisibleToPublisher && $state->executableFields !== null)
		{
			$scheme = $state->executableFields['TEMPLATE'] ?? [];
			$pilot['revision'] = (new TemplateRevisionService())
				->calculateRevision(is_array($scheme) ? $scheme : [])
			;
		}

		return $pilot;
	}

	/**
	 * What the publisher has to confirm before the pilot is stopped. Both consequences are asked about in
	 * one answer, because the client replaces the whole set of the confirmations with the one named here.
	 *
	 * @param string[] $confirmations
	 * @return string[]
	 */
	private function findRequiredStopConfirmations(int $templateId, array $confirmations): array
	{
		$required = [];

		// with no common version the template stops being available for a manual start to everyone, the
		// former participants of the pilot included
		if (
			!$this->hasCommonVersion($templateId)
			&& !self::isConfirmed($confirmations, self::CONFIRMATION_NO_COMMON_VERSION)
		)
		{
			$required[] = self::CONFIRMATION_NO_COMMON_VERSION;
		}

		// a draft of the template is never overwritten: the pilot scheme is deleted instead, and that is
		// what the publisher confirms
		if (
			$this->hasTemplateDraft($templateId)
			&& !self::isConfirmed($confirmations, self::CONFIRMATION_DRAFT_OVERWRITE)
		)
		{
			$required[] = self::CONFIRMATION_DRAFT_OVERWRITE;
		}

		return $required;
	}

	/**
	 * What the publisher has to confirm before the canvas is published in the mode that stops the outdated
	 * processes. Every consequence is named in one answer, for the same reason as in findRequiredStopConfirmations: the
	 * client replaces the whole set of the confirmations with the one named here, so a code asked for a
	 * round later would arrive without the code confirmed before it.
	 *
	 * @param string[] $confirmations
	 * @return string[]
	 */
	private function findRequiredTerminationConfirmations(int $templateId, array $confirmations): array
	{
		$required = [];

		// the mode stops the running processes always, and their number is never counted: a count would cost
		// a query of its own while the decision of the publisher does not depend on the figure
		if (!self::isConfirmed($confirmations, self::CONFIRMATION_STOP_RUNNING_PROCESSES))
		{
			$required[] = self::CONFIRMATION_STOP_RUNNING_PROCESSES;
		}

		// the write of the common scheme ends the publication for a chosen audience, and the publisher is
		// told that before it happens
		// the confirmation is checked first on purpose: it costs nothing, while reading the state of the
		// publication for a chosen audience costs a query that decides nothing once the code is already in
		if (
			!self::isConfirmed($confirmations, self::CONFIRMATION_PILOT_STOP)
			&& $this->pilotState($templateId)->hasPilot
		)
		{
			$required[] = self::CONFIRMATION_PILOT_STOP;
		}

		return $required;
	}

	/**
	 * Turns a refusal of the pilot service into the refusal of the editor API: the codes of the service are
	 * mapped onto the codes of the contract, a refusal that may be confirmed becomes CONFIRMATION_REQUIRED
	 * carrying the codes to confirm, and everything the service attached to the error is kept as it is.
	 *
	 * @param array $data what travels in the body of the refusal beside the errors
	 */
	private function refusePilotOperation(Result $result, array $data = []): ?array
	{
		$required = [];

		foreach ($result->getErrors() as $error)
		{
			$code = (string)$error->getCode();
			$confirmation = self::confirmationOfRefusal($code);

			if ($confirmation !== null)
			{
				$required[] = $confirmation;
				$this->addError(
					new Error($error->getMessage(), self::ERROR_CONFIRMATION_REQUIRED, $error->getCustomData()),
				);

				continue;
			}

			$contractCode = self::contractErrorCode($code);
			$this->addError(
				$contractCode === $code
					? $error
					: new Error($error->getMessage(), $contractCode, $error->getCustomData()),
			);
		}

		if ($required)
		{
			$data['required'] = array_values(array_unique($required));
		}

		return $data ?: null;
	}

	/**
	 * The confirmation a refusal asks for, and null for a refusal that cannot be confirmed away.
	 */
	private static function confirmationOfRefusal(string $code): ?string
	{
		return match ($code)
		{
			PilotPublicationService::ERROR_SETTINGS_FILL_AFTER_PUBLICATION => self::CONFIRMATION_EMPTY_CONSTANTS,
			PilotPublicationService::ERROR_DRAFT_EXISTS => self::CONFIRMATION_DRAFT_OVERWRITE,
			default => null,
		};
	}

	/**
	 * Only the codes of the service that differ from the contract of the editor are listed. TEMPLATE_NOT_FOUND
	 * reveals nothing here: it is reachable only for a caller who has just passed the publication gate on
	 * that very template, and it is what tells the editor the template was deleted mid-session.
	 */
	private static function contractErrorCode(string $code): string
	{
		return match ($code)
		{
			PilotPublicationService::ERROR_TEMPLATE_NOT_FOUND => ErrorMessage::TEMPLATE_NOT_FOUND->value,
			PilotPublicationService::ERROR_ACCESS_DENIED => ErrorMessage::ACCESS_DENIED->value,
			PilotPublicationService::ERROR_FEATURE_DISABLED => ErrorMessage::FEATURE_DISABLED->value,
			PilotPublicationService::ERROR_SETTINGS_NOT_FILLED => self::ERROR_PILOT_CONSTANTS_EMPTY,
			default => $code,
		};
	}

	/**
	 * @param array{accessCode: string, name: string, isAvailable: bool} $item
	 * @return array{accessCode: string, entityType: string, entityId: int, title: string, available: bool}
	 */
	private static function describeAudienceItem(array $item): array
	{
		$accessCode = (string)$item['accessCode'];
		$parsed = new AccessCode($accessCode);

		return [
			'accessCode' => $accessCode,
			'entityType' => $parsed->getEntityType(),
			'entityId' => $parsed->getEntityId(),
			'title' => (string)($item['name'] ?? ''),
			'available' => (bool)($item['isAvailable'] ?? false),
		];
	}

	/**
	 * The name of the publisher as the portal writes names: the format is a setting of the site, so it is
	 * asked of the platform instead of being assembled from the fields. A user who is no longer on the
	 * portal has no name to show, and the answer keeps only the id.
	 */
	private static function formatPilotAuthorById(?int $userId): ?string
	{
		return $userId === null ? null : UserView::createFromId($userId)?->getFullName();
	}

	/**
	 * @param string[] $confirmations
	 */
	private static function isConfirmed(array $confirmations, string $confirmation): bool
	{
		return in_array($confirmation, $confirmations, true);
	}

	private function hasCommonVersion(int $templateId): bool
	{
		return (new PilotEditorStateService())->hasCommonVersion($templateId);
	}

	private function hasTemplateDraft(int $templateId): bool
	{
		$draft = WorkflowTemplateDraftTable::query()
			->setSelect(['ID'])
			->where('TEMPLATE_ID', $templateId)
			->setLimit(1)
			->fetch()
		;

		return $draft !== false;
	}

	/**
	 * @return array{TEMPLATE: array, PARAMETERS: array, VARIABLES: array, CONSTANTS: array}
	 */
	private static function executableFieldsOf(array $fields): array
	{
		$executableFields = [];
		foreach (self::EXECUTABLE_FIELDS as $field)
		{
			$executableFields[$field] = (array)($fields[$field] ?? []);
		}

		return $executableFields;
	}

	/**
	 * @param string[] $audience
	 * @return string[]
	 */
	private static function normalizeAudience(array $audience): array
	{
		$accessCodes = [];
		foreach ($audience as $accessCode)
		{
			$accessCode = trim((string)$accessCode);
			if ($accessCode !== '')
			{
				$accessCodes[$accessCode] = $accessCode;
			}
		}

		return array_values($accessCodes);
	}

	/**
	 * @param array $confirmations the set as it arrived, of any element type
	 * @return string[]
	 */
	private static function normalizeConfirmations(array $confirmations): array
	{
		// only the strings of the set survive: a code is matched strictly, so an element of another type
		// could never be a confirmation anyway, while casting a nested array to a string would add a PHP
		// warning and the word "Array" to the set
		return array_values(array_unique(array_filter($confirmations, 'is_string')));
	}

	/**
	 * Null for a mode the contract does not define. An unknown mode is not taken for the default one:
	 * publishing to the whole company what was meant for five people is exactly what the mode exists to
	 * prevent, so a request that cannot be understood publishes nothing at all.
	 */
	private static function validatePublishMode(mixed $publishMode): ?string
	{
		if ($publishMode === null || $publishMode === '')
		{
			return self::PUBLISH_MODE_MAIN;
		}

		$modes = [self::PUBLISH_MODE_MAIN, self::PUBLISH_MODE_USER, self::PUBLISH_MODE_FULL];

		return in_array($publishMode, $modes, true)
			? $publishMode
			: null
		;
	}

	/**
	 * @throws LoaderException
	 * @throws ArgumentException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function connectAgentAction(int $templateId): ?array
	{
		$userId = $this->authorizeAgentTemplate($templateId);
		if ($userId === null)
		{
			return null;
		}

		$webhookResult = (new AgentWebhookService())->ensureWebhookUrl($userId, $templateId);

		if (!$webhookResult->isSuccess())
		{
			$this->addErrors($webhookResult->getErrors());

			return null;
		}

		$data = $webhookResult->getData();

		return $this->buildConnectResponse(
			$data['url'],
			['connected' => $data['connected'], 'expiresAt' => $data['expiresAt']],
		);
	}

	/**
	 * @throws LoaderException
	 * @throws ArgumentException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function revokeAgentAction(int $templateId): ?array
	{
		$userId = $this->authorizeAgentTemplate($templateId);
		if ($userId === null)
		{
			return null;
		}

		$revokeResult = (new AgentWebhookService())->revoke($userId, $templateId);

		if (!$revokeResult->isSuccess())
		{
			$this->addErrors($revokeResult->getErrors());

			return null;
		}

		return [
			'connected' => false,
			'expiresAt' => null,
		];
	}

	/**
	 * @throws LoaderException
	 * @throws ArgumentException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function regenerateAgentAction(int $templateId): ?array
	{
		$userId = $this->authorizeAgentTemplate($templateId);
		if ($userId === null)
		{
			return null;
		}

		$regenerateResult = (new AgentWebhookService())->regenerate($userId, $templateId);

		if (!$regenerateResult->isSuccess())
		{
			$this->addErrors($regenerateResult->getErrors());

			return null;
		}

		$data = $regenerateResult->getData();

		return $this->buildConnectResponse(
			$data['url'],
			['connected' => $data['connected'], 'expiresAt' => $data['expiresAt']],
		);
	}

	/**
	 * @throws LoaderException
	 * @throws ArgumentException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function agentStatusAction(int $templateId): ?array
	{
		$userId = $this->authorizeAgentTemplate($templateId);
		if ($userId === null)
		{
			return null;
		}

		return (new AgentWebhookService())->getStatus($userId, $templateId);
	}

	/**
	 * Shared access boundary for every agent-token action: refuses when the external AI agent feature
	 * is not accessible (rollout flag off, or AI tariff / region unavailable), then resolves the
	 * template for the current user and checks manage rights through the type-aware document guard
	 * (a real document type requires CreateWorkflow; the WORKFLOW pseudo type requires admin, or the
	 * owner of a started launched copy) plus the right to edit that very template. The token must not be
	 * issued on a template its holder may not edit: everything the token opens is a write into it.
	 * Returns the userId on success, or null after registering the matching error.
	 *
	 * @throws ArgumentException
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function authorizeAgentTemplate(int $templateId): ?int
	{
		if (!Feature::instance()->isExternalAiAgentAccessible())
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return null;
		}

		$userId = (int)CurrentUser::get()->getId();
		$identifier = Container::getAgentWorkflowResolverService()->resolveByTemplateId($templateId, $userId);

		if ($identifier === null)
		{
			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getError(['#ID#' => $templateId]));

			return null;
		}

		$isUserAdmin = UserAdminResolver::isAdmin($userId);
		$verdict = (new OperationAuthorizationGuard())->authorize(
			$userId,
			$identifier->documentDescription,
			$templateId,
			$isUserAdmin,
		);
		if (!$verdict->allowed)
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getError());

			return null;
		}

		return $userId;
	}

	/**
	 * Builds the connect/regenerate response payload from the base webhook URL and the token status.
	 * The /rest/api/ prefix is added here: the controller is the single owner of it; the
	 * webhook URL from the service has /rest/<userId>/<token>.
	 *
	 * The url carries no template identifier: agent.connect answers for the template its token is bound
	 * to and takes no argument at all.
	 *
	 * @param array{connected: bool, expiresAt: int|null} $status
	 *
	 * @return array{connectUrl: string, commands: array{claude: string, codex: string, universal: string}, expiresAt: int|null, connected: bool}
	 */
	private function buildConnectResponse(string $baseUrl, array $status): array
	{
		$base = rtrim($baseUrl, '/');
		$v3Base = preg_replace('#/rest/#', '/rest/api/', $base, 1);
		$connectUrl = $v3Base . '/bizprocdesigner.agent.connect';

		return [
			'connectUrl' => $connectUrl,
			'commands' => [
				'claude' => Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_CONNECT_AGENT_CLAUDE', ['#URL#' => $connectUrl]),
				'codex' => Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_CONNECT_AGENT_CODEX', ['#URL#' => $connectUrl]),
				'universal' => Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_CONNECT_AGENT_UNIVERSAL', ['#URL#' => $connectUrl]),
			],
			'expiresAt' => $status['expiresAt'],
			'connected' => $status['connected'],
		];
	}

	private function getTemplateData(int $id, ?array $newDocumentType, ?string $startTrigger = null): ?array
	{
		$tpl = $id > 0 ? $this->getTpl($id) : null;
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);

		if ($id > 0 && $tpl === null)
		{
			// A missing record has no valid document type to authorize against, so it is gated by the
			// document-type-independent admin check. Existence must not leak to non-admins: they get a
			// uniform ACCESS_DENIED just like for a foreign template.
			if (!$user->isAdmin())
			{
				$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

				return null;
			}

			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError(['#ID#' => $id]));

			return null;
		}

		$documentType = $tpl ? $tpl->getDocumentComplexType() : $newDocumentType;

		// A freshly created template is always Nodes ({@see createEmptyTemplate}); only Nodes are governed
		// by the new ACL. Existing non-Nodes templates stay on the legacy document-type gate.
		$isNodes = $tpl
			? $tpl->getType() === Api\Enum\Template\WorkflowTemplateType::Nodes->value
			: true;

		if ($isNodes)
		{
			// Nodes templates belong to the template ACL end to end: the legacy document-type gate never
			// participates, neither for an existing row nor for one about to be created.
			$canWrite = $documentType && (
				$id > 0
					? $this->accessService()->canEdit($id, $user->getId(), $documentType)
					: $this->accessService()->canCreate($user->getId(), $documentType)
			);
		}
		else
		{
			$canWrite = $documentType && \CBPDocument::CanUserOperateDocumentType(
				\CBPCanUserOperateOperation::CreateWorkflow,
				$user->getId(),
				$documentType,
			);
		}

		if (!$canWrite)
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

			return null;
		}

		if ($id === 0 && $documentType)
		{
			$tpl = $this->createEmptyTemplate($documentType, $startTrigger);
		}

		if (!$tpl)
		{
			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError(['#ID#' => $id]));

			return null;
		}

		$draftId = 0;
		$draftRevision = 0;
		$draftSavedAt = null;

		if (
			$tpl->getType() !== Api\Enum\Template\WorkflowTemplateType::Nodes->value
			&& !defined('\Bitrix\Bizproc\Dev\ENV')
		)
		{
			$this->addError(
				new Error(Loc::getMessage('BIZPROCDESIGNER_CONTROLLER_DIAGRAM_ERROR_TEMPLATE_TYPE')),
			);

			return null;
		}

		$templateId = (int)$tpl->getId();
		$pilotState = $this->pilotState($templateId);

		$draftRow = WorkflowTemplateDraftTable::getLatestDraftWithDataByTemplateId($templateId);
		if ($draftRow)
		{
			$draftId = (int)$draftRow['ID'];
			if (Feature::instance()->isTemplateEditLockAvailable())
			{
				$draftRevision = (int)$draftRow['REVISION'];
			}
			// CREATED is rewritten on every draft update, so it is the last draft save time.
			$draftSavedAt = $draftRow['CREATED']?->getTimestamp();
			$schemeOverlay = $draftRow['TEMPLATE_DATA'];
		}
		else
		{
			// The acting scheme of a template running a pilot is its pilot version: the publisher edits what
			// the audience of the pilot really runs. The layer of comparison below stays the common
			// version, so the difference from what everyone else runs stays visible.
			$schemeOverlay = $pilotState->executableFields ?? [];
		}

		$tplData = $schemeOverlay['TEMPLATE'] ?? $tpl->getTemplate();
		$trackOn = (int)\Bitrix\Main\Config\Option::get('bizproc', 'tpl_track_on_' . $id, 0);

		// The declarations of the acting scheme, taken exactly as the meta of the answer below takes them: an
		// overlay of a draft or of a pilot version declares its own, and the row answers for the rest.
		$actingDeclarations = array_filter(
			array_intersect_key($schemeOverlay, array_flip(self::SCHEME_OWNED_META_FIELDS)),
			static fn($block): bool => $block !== null,
		);
		$templateRow = $tpl->collectValues();

		$complexNodeConnectionsAvailable = Feature::instance()->areComplexNodeConnectionsAvailable();
		[$blocks, $connections] = (new TemplateToNodes(
			$tplData,
			$complexNodeConnectionsAvailable,
			baseContentBlockContext: $this->readableExpressionContextFactory()->createForAuthorizedSchema(
				['TEMPLATE' => $tplData] + $actingDeclarations + $templateRow,
				$documentType,
			),
		))->convert();
		[$publishedBlocks, $publishedConnection] = (new TemplateToNodes(
			$tpl->getTemplate(),
			$complexNodeConnectionsAvailable,
			// The layer of comparison is named by the published scheme, declarations included.
			baseContentBlockContext: $this->readableExpressionContextFactory()
				->createForAuthorizedSchema($templateRow, $documentType)
			,
		))->convert();

		// The settings the scheme on the canvas declares follow that scheme: a pilot version may declare a
		// parameter the live row does not know about, and republishing it from the editor would drop it.
		$templateMeta = array_merge($templateRow, ['TRACK_ON' => $trackOn]);
		$schemeOwnedMetaFields = self::SCHEME_OWNED_META_FIELDS;
		if ($draftRow && WorkflowTemplateDraftTable::hasRestoredMetadata($draftRow))
		{
			// A restored version brings back the name and description of its own time: without them
			// the editor keeps showing the published ones and the next publication drops them.
			$schemeOwnedMetaFields[] = 'NAME';
			$schemeOwnedMetaFields[] = 'DESCRIPTION';
		}

		$schemeOwnedMetaFields = array_values(array_unique(array_merge(
			$schemeOwnedMetaFields,
			AgentOwnedMetadata::readFrom($schemeOverlay),
		)));

		foreach ($schemeOwnedMetaFields as $field)
		{
			if (isset($schemeOverlay[$field]))
			{
				$templateMeta[$field] = $schemeOverlay[$field];
			}
		}

		// The ORM answers the settings as a relation the row does not carry, so the marker of the process
		// data is read on its own: without it the selectors of the editor would take every saved template
		// for one of the previous mechanics (ALG-01).
		$templateMeta['TEMPLATE_SETTINGS'] = $this->processDataSettings($templateId);

		$canPublish = $this->accessService()->canPublish($tpl->getId(), $user->getId(), $documentType);

		return [
			'template' => $templateMeta,
			'templateId' => $tpl->getId(),
			'canPublish' => $canPublish,
			'draftId' => $draftId,
			'draftRevision' => $draftRevision,
			'lock' => $this->getLockOwner($templateId, (int)$user->getId(), withOwnerView: true),
			'canUndoRestore' => WorkflowTemplateDraftTable::isRestoredDraft($draftRow),
			'draftSavedAt' => $draftSavedAt,
			'documentType' => $documentType,
			'documentTypeSigned' => \CBPDocument::signDocumentType($documentType),
			'blocks' => $blocks,
			'connections' => $connections,
			'publishedBlocks' => $publishedBlocks,
			'publishedConnection' => $publishedConnection,
			'pilot' => $this->buildPilotState(
				$pilotState,
				$canPublish,
				isCanvasPilot: $draftRow === null,
			),
		];
	}

	/**
	 * The row of a template the canvas has just been opened for. It is created with the stub of the
	 * converter and not with an empty scheme, so "this template has never been published for everyone" is
	 * recorded explicitly: nothing here is a publication, and the scheme alone cannot tell the two apart.
	 *
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
			checkAccess: false,
		);
		$templateService = new Api\Service\WorkflowTemplateService();
		$result = $templateService->saveTemplate($request, withoutCommonVersion: true);

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
			// The column is nullable and rows written before the editor still hold NULL there, so the
			// editor is handed a null it sends back with the whole template on every draft save. That is
			// the absence of a description, not a value the gate below should refuse: refusing it would
			// stop the autosave of those templates outright. Anything else keeps its type and is judged.
			'DESCRIPTION' => $template['DESCRIPTION'] ?? '',
			'PARAMETERS' => $template['PARAMETERS'],
			'VARIABLES' => $template['VARIABLES'],
			'CONSTANTS' => $template['CONSTANTS'],
			'TEMPLATE_SETTINGS' => $template['TEMPLATE_SETTINGS'],
			'AUTO_EXECUTE' => $template['AUTO_EXECUTE'],
		];
	}

	private function normalizeSetupConstantsInFields(array $fields): array
	{
		$fields['CONSTANTS'] = (new SetupTemplateService())->normalizeConstantsByTemplate(
			is_array($fields['TEMPLATE'] ?? null) ? $fields['TEMPLATE'] : [],
			is_array($fields['CONSTANTS'] ?? null) ? $fields['CONSTANTS'] : [],
		);

		return $fields;
	}

	/**
	 * Parses the request into diagram data. Only a not-yet-created template is authorized here, by the
	 * legacy document-type gate; for an existing one publicate/publicateDraft apply the per-operation gate
	 * (publish vs edit) to the loaded record.
	 *
	 * @return array{templateId: int, fields: array, user: \CBPWorkflowTemplateUser, draftId: int,
	 *     draftRevision: ?int, documentType: array, tpl: ?\Bitrix\Bizproc\Workflow\Template\Tpl, publishMode: string,
	 *     audience: string[], confirmations: string[], pilotId: int, revision: string}|null
	 */
	private function prepareDiagramData(): ?array
	{
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);

		$json = Application::getInstance()->getContext()->getRequest()->getJsonList();
		$templateId = (int)$json->get('templateId');
		$documentType = \CBPDocument::unSignDocumentType($json->get('documentTypeSigned'));

		$tpl = $templateId > 0 ? $this->getTpl($templateId) : null;

		if ($tpl)
		{
			// The document type of a live template belongs to its row, not to the request. A valid signature
			// only proves the type exists, not that this template may be moved onto it: otherwise a PUBLISH
			// holder could rebind the template to a foreign entity and autostart it there. Who may write the
			// row is decided per operation by the caller.
			$documentType = $tpl->getDocumentComplexType();
			$canWrite = !empty($documentType);
		}
		else
		{
			// This controller only ever creates Nodes templates, and those are governed by the template ACL
			// alone - never by the legacy document-type gate. The caller applies it (CREATE for a new row).
			$canWrite = !empty($documentType);
		}

		if (!$canWrite)
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

			return null;
		}

		$blocks = (array)$json->get('blocks');
		$connections = (array)$json->get('connections');
		$converter = new NodesToTemplate($blocks, $connections);

		$template = $json->get('template');
		$template['TEMPLATE'] = $converter->convert();
		$fields = $this->prepareFields($template, $documentType);

		$draftId = (int)$json->get('draftId');
		// A client that carries no revision at all keeps null: the absence of the field is a skip of the
		// check, while a zero coming from a client is a value to compare.
		$draftRevision = $json->get('draftRevision');

		// The mode of the publication and its audience are read here but never enter the fields of the
		// template: the fields carry a strict allowlist, and the audience is an entity of its own, not a
		// column of the row.
		$publishMode = self::validatePublishMode($json->get('publishMode'));
		if ($publishMode === null)
		{
			$this->addError(ErrorMessage::INVALID_PARAM_ARG->getCodedError([
				'#PARAM#' => 'publishMode',
				'#VALUE#' => (string)$json->get('publishMode'),
			]));

			return null;
		}

		return [
			'templateId' => $templateId,
			'fields' => $fields,
			'user' => $user,
			'draftId' => $draftId,
			'draftRevision' => $draftRevision === null ? null : (int)$draftRevision,
			'documentType' => $documentType,
			'tpl' => $tpl,
			'publishMode' => $publishMode,
			'audience' => self::normalizeAudience((array)$json->get('audience')),
			'confirmations' => self::normalizeConfirmations((array)$json->get('confirmations')),
			'pilotId' => (int)$json->get('pilotId'),
			'revision' => (string)$json->get('revision'),
		];
	}


	/**
	 * Who edits the template right now. The load of the editor only reads the lease and never takes one:
	 * the lease is taken by the first write. An unavailable lease storage leaves the client without an
	 * owner instead of closing the load.
	 *
	 * The name and the photo of the holder are asked for by the load of the schema alone: there they save
	 * the client a request of its own and the screen a frame of a skeleton. Every later answer about the
	 * same lease carries the id only - it is by the id that the client notices a change of the holder, and
	 * the name of the new one it reads once, instead of being told the unchanged one every minute.
	 *
	 * @return array<string, int|bool|string|null>|null null when nobody edits the template
	 */
	private function getLockOwner(int $templateId, int $userId, bool $withOwnerView = false): ?array
	{
		$owner = $this->readLeaseOwner($templateId, $userId);
		if ($owner === null)
		{
			return null;
		}

		// The caller holds the template themselves: the client never shows the name of its own lease, and
		// the answer carries isMine for that. The read would be paid for by every session that writes -
		// on the first write, on every renewal of the lease and twice over a takeover - and shown to
		// nobody.
		if (!$withOwnerView || $owner->userId === $userId)
		{
			return $owner->toArray();
		}

		return $owner->toArray() + $this->readLockOwnerView($owner->userId);
	}

	/**
	 * How the holder of the lease is shown to the caller: the name and the photo, or nothing at all.
	 *
	 * The lease itself carries only the id - see LockOwnerDto, where that is a decision and not an
	 * omission - and the client used to read the name by that id on its own, one request after the
	 * schema. The name travels with the lease instead, so the person opening a busy template reads who
	 * holds it at once; the request the client made is left as the way of every later change of the
	 * holder, which arrives by id alone.
	 *
	 * The reading goes through the same entity selector provider the client asked, and for the same
	 * reason: the visibility rules of the portal - a hidden employee, a colleague from the extranet -
	 * are answered in one place and not repeated here. An answer that never came leaves the payload
	 * without the name, and the client reads it by id the old way: this is decoration over a lease that
	 * must open the editor regardless.
	 *
	 * @return array{name?: string, avatarUrl?: string|null}
	 */
	private function readLockOwnerView(int $ownerUserId): array
	{
		if ($ownerUserId <= 0 || !Loader::includeModule('ui'))
		{
			return [];
		}

		try
		{
			$dialog = new EntitySelectorDialog([
				'entities' => [
					[
						'id' => self::USER_ENTITY_ID,
						'dynamicLoad' => true,
						// Only the holder is asked about: without these the provider also preloads the
						// recent items and the first users of the portal.
						'fillRecentItems' => false,
						'options' => ['fillDialog' => false],
					],
				],
				'preselectedItems' => [[self::USER_ENTITY_ID, $ownerUserId]],
			]);
			$dialog->load();

			$item = $dialog->getItemCollection()->get(self::USER_ENTITY_ID, $ownerUserId);
			// An employee the provider answered nothing about does not leave the collection empty: the
			// dialog fills the gap with a placeholder whose title is a word about the absence. Passed on
			// as a name it would show that word on the screen and link to a profile of a person the
			// caller may not see at all.
			if ($item === null || $item->isHidden())
			{
				return [];
			}

			return [
				'name' => $item->getTitle(),
				'avatarUrl' => $item->getAvatar(),
			];
		}
		catch (\Throwable $failure)
		{
			// The lease opens the editor with or without the decoration, so the failure is not carried to
			// the caller - but a provider refusing every call costs a dialog on the server and a request
			// of the client each time, and nothing else on this path would name that. The record waits for
			// the logger id to be switched on: a channel to open over the portal where the empty names are
			// seen, and not a line in the log of every other one. The holder and the failure are named by
			// the message itself, because the formatter of that channel renders the placeholders it finds
			// there and drops the rest of the context.
			$this->logger()->warning(
				'Template lease: the holder {ownerUserId} could not be read. {exception}',
				[
					'ownerUserId' => $ownerUserId,
					'exception' => $failure,
				],
			);

			return [];
		}
	}

	/**
	 * The owner of the lease as the service knows it. A switched off feature has no owner to tell about, and
	 * neither has an unavailable storage: the reading side of the lease never closes what it is part of.
	 */
	private function readLeaseOwner(int $templateId, int $userId): ?LockOwnerDto
	{
		if (!Feature::instance()->isTemplateEditLockAvailable())
		{
			return null;
		}

		return $this->lockService()
			->getLock(new Api\Request\WorkflowTemplateLockService\GetLockRequest($templateId, $userId))
			->getLock()
		;
	}

	/**
	 * The owner for a payload that travels to somebody the isMine flag is not about: a refusal names the
	 * owner to another user, and a pull event knows no current user at all.
	 *
	 * @return array<string, int>|null
	 */
	private static function toOwnerPayload(?LockOwnerDto $owner): ?array
	{
		return $owner === null
			? null
			: (new LockOwnerDto($owner->userId, $owner->acquiredAt, $owner->expiresAt))->toArray()
		;
	}

	/**
	 * The guard of every write path: the lease of the template is taken for the user, or the write is refused
	 * to the one holding it. Taking over is transparent - a lease that is absent, expired or already the
	 * user's own is granted silently, so TEMPLATE_LOCKED only ever means a living lease of somebody else.
	 *
	 * Only the draft save is covered by the revision, and only while the revision really refuses a stale write
	 * of this very request: then an unavailable lease storage is logged and the write goes on. With nothing
	 * left to refuse it the write is closed, the way it is on every other path.
	 *
	 * @param int $draftId draft the write is addressed to; zero on the paths that write no draft
	 * @param int|null $draftRevision revision the client came back with, null when it carries none
	 *
	 * @return bool false when the write must not go on; the matching error is registered
	 */
	private function acquireTemplateLease(
		int $templateId,
		int $userId,
		int $draftId = 0,
		?int $draftRevision = null,
	): bool
	{
		if (!Feature::instance()->isTemplateEditLockAvailable())
		{
			return true;
		}

		$response = $this->lockService()->acquireOrRenew(
			new Api\Request\WorkflowTemplateLockService\AcquireLockRequest($templateId, $userId),
		);

		if (!$response->isSuccess())
		{
			if (!$this->isDraftWriteRevisionGuarded($templateId, $draftId, $draftRevision))
			{
				$this->addErrors($response->getErrors());

				return false;
			}

			Container::getDefaultLogger()->error(
				'Template edit lease is unavailable for template {templateId}: {errors}',
				[
					'templateId' => $templateId,
					'errors' => implode('; ', $response->getErrorMessages()),
				],
			);

			return true;
		}

		if ($response->isHeld())
		{
			return true;
		}

		// The owner travels without isMine: on this path the lease is never the caller's own.
		$this->addError(
			ErrorMessage::TEMPLATE_LOCKED->getCodedError(
				customData: self::toOwnerPayload($response->getLock()),
			),
		);

		return false;
	}

	/**
	 * Whether the revision of the request really stands between a stale write and the draft. The comparison of
	 * the write path happens on two conditions at once: the client came back with a revision, and the draft it
	 * names is still the freshest of the template with a revision stored. A restored draft starts with a zero
	 * and an old client sends nothing at all - the guard behind such a write is the lease alone, and losing it
	 * closes the write instead of letting it through unprotected.
	 *
	 * The query lives in this branch only: on the way of a lease that was granted nothing asks about it.
	 */
	private function isDraftWriteRevisionGuarded(int $templateId, int $draftId, ?int $draftRevision): bool
	{
		return $draftRevision !== null
			&& WorkflowTemplateDraftTable::getRevisionIfLatest($templateId, $draftId) > 0
		;
	}

	/**
	 * The write boundary of the three version-history actions: the right to edit first, the lease after it.
	 * The journal checks the rights itself, but only after the guard would have taken the lease - a user who
	 * may not edit the template would occupy it and learn of its existence at the same time. The check
	 * repeats the boundary of the journal: a missing template and a foreign one stay indistinguishable, and a
	 * system or non-Nodes row takes no part in the history at all.
	 *
	 * @return bool false when the action must stop; the matching error is registered
	 */
	private function authorizeHistoryWrite(int $templateId): bool
	{
		$userId = (int)CurrentUser::get()->getId();
		$tpl = $this->getTplForAccessCheck($templateId);

		$canEdit = $tpl !== null
			&& $tpl->getType() === Api\Enum\Template\WorkflowTemplateType::Nodes->value
			&& $this->editAccess()->canEdit($tpl, $userId)
		;

		if (!$canEdit)
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

			return false;
		}

		return $this->acquireTemplateLease($templateId, $userId);
	}

	/**
	 * The access boundary the three lease actions share. The right to edit proves nothing about the template
	 * on its own: the rule is a flat matrix and answers yes for an id that does not exist, so a lease would be
	 * left on a row no cascade ever deletes. The record is resolved the same way every other action of the
	 * controller resolves it - system templates cut off - and only then the existing fork by template type
	 * decides the right.
	 *
	 * @return array{tpl: \Bitrix\Bizproc\Workflow\Template\Tpl, user: \CBPWorkflowTemplateUser}|null null when
	 * the action must stop; the matching error is registered
	 */
	private function authorizeLeaseAction(int $templateId): ?array
	{
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);
		$tpl = $templateId > 0 ? $this->getTplForAccessCheck($templateId) : null;

		if ($tpl === null)
		{
			// Same anti-oracle guard as getTemplateData: a missing record must not reveal its
			// (non-)existence to a non-admin caller.
			if (!$user->isAdmin())
			{
				$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

				return null;
			}

			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError(['#ID#' => $templateId]));

			return null;
		}

		// Taking the lease of a legacy template past this fork would block it for the user it really
		// belongs to.
		if (!$this->editAccess()->canEdit($tpl, (int)$user->getId()))
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

			return null;
		}

		return ['tpl' => $tpl, 'user' => $user];
	}

	/**
	 * Tells the user it stopped being the editor of the template. Only an explicit release gets here: when an
	 * expired lease is taken over, its previous owner is gone from the row in the same statement, and the tab
	 * that comes back learns of the loss from the refusal of its first write.
	 *
	 * The right to edit is checked on the recipient again: a lease lives ten minutes and outlives a
	 * withdrawal of the rights.
	 */
	private function notifyLeaseLost(\Bitrix\Bizproc\Workflow\Template\Tpl $tpl, int $formerOwnerId): void
	{
		if (!$this->editAccess()->canEdit($tpl, $formerOwnerId))
		{
			return;
		}

		$templateId = (int)$tpl->getId();

		Container::getPullManager()->sendEvent(
			$formerOwnerId,
			BizprocDesignerPullEvent::TemplateLockLost,
			[
				// The delivery addresses the user and not the tab, so the event reaches every tab it has
				// open, including the ones on other templates.
				'templateId' => $templateId,
				// Who holds the template now: nobody after a release, somebody who took it over in between.
				'owner' => self::toOwnerPayload($this->readLeaseOwner($templateId, $formerOwnerId)),
			],
		);
	}


	/**
	 * @param list<string> $fieldsToRelease
	 * @return array|null
	 */
	private function releaseAgentOwnedMetadata(int $templateId, array $fieldsToRelease): ?array
	{
		$result = (new AgentOwnedMetadataService())->release($templateId, $fieldsToRelease);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}

	/**
	 * The single description gate of the editor: every write surface that carries the field
	 * (updateTemplate, publicate, publicateDraft) passes the value through here, so the limit cannot be
	 * bypassed by publishing instead of saving the settings. A value of another type is refused before
	 * the length is measured: the storage would silently cast it and lose the data.
	 *
	 * The source array is taken whole rather than the value alone: only the array tells an absent key,
	 * which writes nothing, from a key holding null, which is a write of a value the column cannot
	 * hold. Read through the value alone the two are the same, and an explicit null passes for a field
	 * that was never sent.
	 */
	private function validateDescription(array $source, string $key = 'DESCRIPTION'): bool
	{
		if (!array_key_exists($key, $source))
		{
			return true;
		}

		$description = $source[$key];

		if (!is_string($description))
		{
			$this->addError(ErrorMessage::INVALID_PARAM_TYPE->getCodedError([
				'#PARAM#' => 'DESCRIPTION',
				'#VALUE#' => 'string',
			]));

			return false;
		}

		if (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH)
		{
			$this->addError(new Error(
				Loc::getMessage(
					'BIZPROCDESIGNER_CONTROLLER_DIAGRAM_ERROR_DESCRIPTION_TOO_LONG',
					['#LIMIT#' => number_format(self::DESCRIPTION_MAX_LENGTH, 0, '', ' ')],
				),
				ErrorMessage::INVALID_PARAM_ARG->value,
			));

			return false;
		}

		return true;
	}

	/**
	 * The barrier the three last-run-values actions share: the feature gate of the mechanism plus the
	 * right to edit the template the values belong to. Registers the coded error itself, so a caller only
	 * decides whether to go on.
	 *
	 * @return int|null Id of the authorized reader, null once the request is refused. The reading and the
	 * gate answer about one and the same user because the id comes out of the very check that allowed it.
	 */
	private function authorizeLastRunValuesReading(int $templateId): ?int
	{
		// explicit here, unlike the reading service where it used to be implicit: the actions must refuse
		// while the feature is off, not merely answer with nothing
		if (!Feature::instance()->isLastRunValuesAvailable())
		{
			$this->addError(ErrorMessage::FEATURE_DISABLED->getCodedError());

			return null;
		}

		$tpl = $this->getTplForAccessCheck($templateId);
		$user = new \CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);

		if ($tpl === null)
		{
			// Same anti-oracle guard as getTemplateData: a missing record must not reveal its
			// (non-)existence to a non-admin caller.
			if (!$user->isAdmin())
			{
				$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

				return null;
			}

			$this->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError(['#ID#' => $templateId]));

			return null;
		}

		if (!$this->editAccess()->canEdit($tpl, (int)$user->getId()))
		{
			$this->addError(ErrorMessage::ACCESS_DENIED->getCodedError());

			return null;
		}

		return (int)$user->getId();
	}

	private function canWriteTemplate(
		\Bitrix\Bizproc\Workflow\Template\Tpl $tpl,
		\CBPWorkflowTemplateUser $user,
	): bool
	{
		return $this->editAccess()->canWriteLegacy($tpl, (int)$user->getId());
	}

	private function saveTemplate(
		int $templateId,
		array $fields,
		\CBPWorkflowTemplateUser $user,
		int $draftId = 0,
		?int $draftRevision = null,
		bool $inOuterTransaction = false,
	): ?array
	{
		$templateService = new Api\Service\WorkflowTemplateService();
		$request = new Api\Request\WorkflowTemplateService\SaveTemplateRequest(
			templateId: $templateId,
			parameters: [],
			fields: $fields,
			user: $user,
			checkAccess: false,
			draftId: $draftId,
			draftRevision: $draftRevision,
			useEditConflictGuard: Feature::instance()->isTemplateEditLockAvailable(),
		);
		$result = $inOuterTransaction
			? $templateService->saveTemplateInOuterTransaction($request)
			: $templateService->saveTemplate($request)
		;

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}

		return $result->getData();
	}

	protected function isPortalAdmin(int $userId): bool
	{
		return CurrentUser::get()->isAdmin()
			|| (Loader::includeModule('bitrix24') && \CBitrix24::IsPortalAdmin($userId));
	}

	private function saveTemplateDraft(
		int $templateId,
		array $fields,
		\CBPWorkflowTemplateUser $user,
		int $draftId,
		?int $draftRevision,
		TemplateChangeAttribution $attribution,
	): array
	{
		$request = new Api\Request\WorkflowTemplateService\SaveTemplateDraftRequest(
			templateId: $templateId,
			parameters: [],
			fields: $fields,
			user: $user,
			checkAccess: false,
			draftId: $draftId,
			revision: $draftRevision,
			attribution: $attribution,
			useEditConflictGuard: Feature::instance()->isTemplateEditLockAvailable(),
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
	 * The marker of the process data as the editor reads it: the settings of a template are a relation of the
	 * ORM, and `collectValues()` answers it with a collection the client cannot read.
	 *
	 * @return array<string, string>
	 */
	private function processDataSettings(int $templateId): array
	{
		return (new ProcessDataMechanicsResolver())->isMarked([], $templateId)
			? [ProcessDataMechanicsResolver::MARKER => ProcessDataMechanicsResolver::MARKER_VALUE]
			: []
		;
	}

	/**
	 * @param int $id
	 * @param array $select fields to read; a caller that does not show the graph of the published template
	 * asks for its own narrow set instead of the whole record
	 * @return \Bitrix\Bizproc\Workflow\Template\Tpl
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	private function getTpl(int $id, array $select = ['*', 'TEMPLATE_SETTINGS']): ?\Bitrix\Bizproc\Workflow\Template\Tpl
	{
		$tpl = WorkflowTemplateTable::query()
			->where('ID', $id)
			->whereNull('SYSTEM_CODE')
			->setSelect($select)
			->setLimit(1)
			->exec()
			->fetchObject()
		;

		return $tpl;
	}

	/**
	 * The record of the access gate: it is resolved by the gate itself, so the fields it asks about are
	 * named in a single place. Opening a read-only surface must not pull the serialized template body and
	 * its drafts.
	 *
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	private function getTplForAccessCheck(int $id): ?\Bitrix\Bizproc\Workflow\Template\Tpl
	{
		return $this->editAccess()->resolve($id);
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
