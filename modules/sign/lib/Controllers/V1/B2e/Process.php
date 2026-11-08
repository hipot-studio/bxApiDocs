<?php

namespace Bitrix\Sign\Controllers\V1\B2e;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Service\Pilot\StartFormParameters;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Bizproc\Starter\Enum\ManualStartSurface;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Access\Service\SelectorSourceAccessService;
use Bitrix\Sign\Attribute\ActionAccess;
use Bitrix\Sign\Config\Feature;
use Bitrix\Sign\Engine\Controller;
use Bitrix\Sign\Integration\Bitrix24\B2eTariff;
use Bitrix\Sign\Item\Hr\EntitySelector\Entity;
use Bitrix\Sign\Item\Hr\EntitySelector\EntityCollection;
use Bitrix\Sign\Item\Process\LaunchGroup;
use Bitrix\Sign\Item\Process\ProcessDocument;
use Bitrix\Sign\Item\Process\ProcessOrigin;
use Bitrix\Sign\Item\Process\ProcessPage;
use Bitrix\Sign\Item\Process\ProcessParticipant;
use Bitrix\Sign\Item\Process\RunningProcess;
use Bitrix\Sign\Operation;
use Bitrix\Sign\Service\Process\LaunchService;
use Bitrix\Sign\Service\Process\RunningProcessService;
use Bitrix\Sign\Service\Sign\MemberService;
use Bitrix\Sign\Type\Hr\EntitySelector\EntityType;
use Bitrix\Sign\Type\Process\RunningProcessFilterState;

/**
 * Transport of the processes of the KEDO section: what an employee may start, what has to be filled in
 * before the start, the start itself, the retry of a launch that did not take place and the list of what is
 * already running. No action decides on availability of its own - the catalog of the section answers that
 * question, so a process offered in the list is the same process the fields are asked for and the same
 * process that starts.
 */
class Process extends Controller
{
	private const ERROR_PROCESS_UNAVAILABLE = 'SIGN_PROCESS_UNAVAILABLE';
	private const ERROR_START_DENIED = 'SIGN_PROCESS_START_DENIED';
	private const ERROR_DOCUMENT_NOT_FOUND = 'SIGN_PROCESS_DOCUMENT_NOT_FOUND';
	private const ERROR_MEMBER_LIMIT_EXCEEDED = 'SIGN_B2E_MEMBER_LIMIT_EXCEEDED';
	private const ERROR_MEMBER_SELECTION_EMPTY = 'SIGN_B2E_MEMBER_SELECTION_EMPTY';
	private const ERROR_MEMBER_SELECTION_INVALID = 'SIGN_B2E_MEMBER_SELECTION_INVALID';
	private const MAX_MEMBER_SELECTOR_ENTITIES = 1000;

	/**
	 * The largest page the transport gives out at once. The size of the page belongs to the transport and
	 * not to the client: the rows are counted with the rights asked per row, so a page of any size the
	 * client names would let one request walk the whole storage of the groups.
	 */
	private const PAGE_LIMIT = 50;

	/**
	 * The code of the tariff refusals of the whole module ({@see B2eTariff::getCommonAccessError()}): the
	 * client answers it with the upsell of the tariff instead of a toast, so a launch refused by the tariff
	 * has to speak the same code as the rest of the module.
	 */
	private const ERROR_TARIFF_RESTRICTED = 'B2E_RESTRICTED_ON_TARIFF';

	private ?TemplateAccessService $templateAccessService;
	private ?SelectorSourceAccessService $selectorSourceAccessService;
	private ?MemberService $memberService;

	public function __construct(
		?Main\Request $request = null,
		?TemplateAccessService $templateAccessService = null,
		?SelectorSourceAccessService $selectorSourceAccessService = null,
		?MemberService $memberService = null,
	)
	{
		parent::__construct($request);
		$this->templateAccessService = $templateAccessService;
		$this->selectorSourceAccessService = $selectorSourceAccessService;
		$this->memberService = $memberService;
	}

	/**
	 * @return array{
	 *     processes: array<array{id: int, name: string, description: string, hasParameters: bool,
	 *         isConstantsTuned: bool, modifiedTs: int, authorId: int}>,
	 *     hasAny: bool,
	 *     canCreateProcess: bool,
	 *     isEntityAvailable: bool,
	 * }
	 */
	#[ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_READ)]
	public function listAvailableAction(?int $limit = null, ?string $searchQuery = null): array
	{
		$response = [
			'processes' => [],
			'hasAny' => false,
			'canCreateProcess' => false,
			'isEntityAvailable' => false,
		];

		$userId = $this->getUserId();
		if ($userId < 1 || !Feature::instance()->isKedoRunProcessEnabled() || !Loader::includeModule('bizproc'))
		{
			return $response;
		}

		// The right to create a process is not the right to start one, and neither follows from the other,
		// so the client is told about it by a field of its own rather than by the contents of the list. The
		// document type is left out on purpose: the right is a toggler without an area, and naming the
		// personnel processes here would suggest it is counted on them.
		$response['canCreateProcess'] = $this->getTemplateAccessService()->canCreate($userId);

		// An absent smart process is an ordinary state of the portal and not a failure of the request: the
		// interface goes dark on the flag instead of showing the employee an error it cannot act on.
		if (!$this->container->getCrmProcessTypeService()->isAvailable())
		{
			return $response;
		}
		$response['isEntityAvailable'] = true;

		$catalog = $this->container->getBizprocProcessCatalogService();
		$processes = $catalog->getAvailableProcesses($userId, $searchQuery, $limit);
		$response['processes'] = $processes->jsonSerialize();

		// The flag serves the visibility of the menu item, so it answers about the whole section and is
		// never narrowed by the search or by the limit of the page. A non-empty page already answers it.
		$response['hasAny'] = !$processes->isEmpty() || $catalog->hasAny($userId);

		return $response;
	}

	/**
	 * @return array{documentType: array, fields: array}|array{}
	 */
	#[ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_READ)]
	public function startFieldsAction(int $processId): array
	{
		$userId = $this->getUserId();
		if ($userId < 1 || !Feature::instance()->isKedoRunProcessEnabled() || !Loader::includeModule('bizproc'))
		{
			$this->addProcessUnavailableError();

			return [];
		}

		$documentType = $this->getProcessDocumentType();
		if ($documentType === null)
		{
			$this->addProcessUnavailableError();

			return [];
		}

		// The right to start and the belonging to the section are checked by the catalog of the section, so
		// a process that left it - or that this employee was never allowed to start - is unavailable here
		// exactly as it is in the list.
		$process = $this->container->getBizprocProcessCatalogService()
			->getByIdForStart($userId, $processId)
		;
		if ($process === null)
		{
			$this->addProcessUnavailableError();

			return [];
		}

		return [
			'documentType' => $documentType,
			'fields' => $this->getStartFields($processId, $userId),
		];
	}

	/**
	 * One press of the start button. Nothing is started while the request is being answered: the selection
	 * turns into a launch group whose rows are served by the queue, so the answer comes back before the
	 * processes do and the client goes to the tab of the processes with the group in "starting".
	 *
	 * @param list<array{entityType: string, entityId: int|string}> $members selector entities to expand
	 * @param array $parameters values of the start fields as they were filled in the form
	 *
	 * @return array{launchId: int, memberCount: int, totalCount: int, excludedCount: int}|array{}
	 */
	#[ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_ADD)]
	public function startAction(
		int $processId,
		array $members,
		array $parameters = [],
		bool $excludeRefused = true,
	): array
	{
		$userId = $this->getUserId();
		if ($userId < 1)
		{
			$this->addAccessDeniedError();

			return [];
		}

		if (!Feature::instance()->isKedoRunProcessEnabled() || !Loader::includeModule('bizproc'))
		{
			$this->addProcessUnavailableError();

			return [];
		}

		// A tariff without signing with employees stops the launch before anything is queued. Left to the
		// step of the signing, the restriction would meet a process that has already gone the whole route -
		// the work of the people on it spent for nothing. The tariff is asked after the flag of the feature:
		// a portal the section is not rolled out to has no business hearing about the tariff of it.
		if (B2eTariff::instance()->isB2eRestrictedInCurrentTariff())
		{
			$this->addTariffRestrictedError();

			return [];
		}

		$documentType = $this->getProcessDocumentType();
		if ($documentType === null)
		{
			$this->addProcessUnavailableError();

			return [];
		}

		// The element the process will run on does not exist yet - it is created while the queue is served -
		// so the right is asked about the type of the document. The check by the element itself belongs to
		// the queue, where the element is already there.
		if (!$this->canStartProcessType($userId, $documentType))
		{
			$this->addStartDeniedError();

			return [];
		}

		// Belonging to the section and the right to start this very process are answered by the catalog of
		// the section, exactly as for the fields of the form: a process that left the section while the
		// slider was open must be refused and not silently started.
		$process = $this->container->getBizprocProcessCatalogService()
			->getByIdForStart($userId, $processId)
		;
		if ($process === null)
		{
			$this->addProcessUnavailableError();

			return [];
		}

		$entityCollectionResult = $this->createMemberEntityCollection($members);
		if (!$entityCollectionResult->isSuccess())
		{
			$this->addMemberSelectionErrors($entityCollectionResult);

			return [];
		}

		/** @var EntityCollection $entityCollection */
		$entityCollection = $entityCollectionResult->getData()['entities'];
		$sourceAccessResult = $this->getSelectorSourceAccessService()->checkEntityCollection($entityCollection);
		if (!$sourceAccessResult->isSuccess())
		{
			$this->addSourceAccessDeniedError();

			return [];
		}

		$memberResult = $this->getMemberService()->resolveUniqueSignerIds(
			$entityCollection,
			$excludeRefused,
			self::MAX_MEMBER_SELECTOR_ENTITIES,
		);
		if (!$memberResult->isSuccess())
		{
			$this->addMemberSelectionErrors($memberResult);

			return [];
		}
		$memberData = $memberResult->getData();

		$result = (new Operation\Process\Launch(
			createdById: $userId,
			templateId: $processId,
			employeeIds: $memberData['userIds'],
			parameters: $parameters,
		))->launch();
		if (!$result->isSuccess())
		{
			$this->addLaunchErrors($result);

			return [];
		}

		$data = $result->getData() + [
			'totalCount' => (int)$memberData['totalCount'],
			'excludedCount' => (int)$memberData['excludedCount'],
		];
		$this->sendLaunchAnalytics($processId, (int)$data['memberCount']);

		return $data;
	}

	/**
	 * Rows of a launch that did not start in the term of their group go back into the queue. Without itemId
	 * the whole group is retried, with it - the named row alone. A row left in "starting" forever offers the
	 * initiator nothing, so the failed one is offered a retry.
	 *
	 * The retry is not gated by the flag of the feature: the flag stops new launches, while the groups that
	 * were queued while it was open play out - the queue serving them is not gated either.
	 *
	 * @return array{retriedCount: int}|array{}
	 */
	#[ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_ADD)]
	public function retryAction(int $launchId, ?int $itemId = null): array
	{
		$userId = $this->getUserId();
		if ($userId < 1)
		{
			$this->addAccessDeniedError();

			return [];
		}

		$result = $this->container->getProcessLaunchService()->retry($launchId, $userId, $itemId);
		if (!$result->isSuccess())
		{
			$this->addRetryErrors($result);

			return [];
		}

		return $result->getData();
	}

	/**
	 * The processes that have already been started, as the tab of the section shows them. Without launchId
	 * the answer is the top level of the list - the launch groups; the composition of a group is asked for
	 * by naming it, so the row of a launch on thirty employees does not drag thirty rows into the first
	 * answer.
	 *
	 * The list is narrowed by the state and by the search alone: "from the company / from the employees"
	 * belongs to the documents and says nothing about a process.
	 *
	 * @param array{state?: string, searchQuery?: string} $filter
	 *
	 * @return array{groups: list<array>, hasNextPage: bool, hasAnyRunning: bool}
	 *     |array{processes: list<array>, hasNextPage: bool}
	 */
	#[ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_READ)]
	public function listAction(
		?int $launchId = null,
		array $filter = [],
		int $limit = RunningProcessService::PAGE_SIZE,
		int $offset = 0,
	): array
	{
		$limit = min(max(1, $limit), self::PAGE_LIMIT);
		$offset = max(0, $offset);
		$userId = $this->getUserId();
		$service = $this->container->getRunningProcessService();

		// A portal the section is not rolled out to has no tab to fill. That is not a failure of the
		// request: the answer keeps its shape and the interface goes dark on it, the way the catalog of the
		// section answers the same portal.
		$isAvailable = $userId > 0 && Feature::instance()->isKedoRunProcessEnabled();

		if ($launchId !== null)
		{
			$page = $isAvailable
				? $service->listGroupProcesses($userId, $launchId, $limit, $offset)
				: ProcessPage::emptyPage()
			;

			return [
				'processes' => array_map($this->serializeProcess(...), $page->items),
				'hasNextPage' => $page->hasNextPage,
			];
		}

		$state = RunningProcessFilterState::tryFromOrDefault($this->getFilterValue($filter, 'state'));
		$page = $isAvailable
			? $service->listGroups($userId, $state, $this->getFilterValue($filter, 'searchQuery'), $limit, $offset)
			: ProcessPage::emptyPage()
		;

		return [
			'groups' => array_map($this->serializeGroup(...), $page->items),
			'hasNextPage' => $page->hasNextPage,
			// The mark on the pill answers about the whole section, so a page narrowed by the search never
			// puts it out. A non-empty page of the default filter answers it by itself - everything the
			// filter kept is a running process - while a page of the finished ones says nothing about it.
			'hasAnyRunning' => ($state === RunningProcessFilterState::InWork && $page->items !== [])
				|| ($isAvailable && $service->hasAnyRunning($userId)),
		];
	}

	/**
	 * The process a document was created by, for the line "Created by the process ..." of its card.
	 *
	 * An empty answer is the ordinary case and not a failure: a document created by no process of the
	 * section, and a process the person may not see, both leave the line out - the card must not tell
	 * those two apart, and a portal the section is not rolled out to shows the line no more than it shows
	 * the rest of the interface of the feature.
	 *
	 * A document that is not the business of the person asking is refused instead, and refused the same
	 * way whether it is somebody else's or not there at all: the identifier comes from the client, so an
	 * answer that told the two apart would say whose documents exist.
	 *
	 * @return array{process: array{workflowId: string, entityId: int, name: string, url: string}|null}
	 */
	#[ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_READ)]
	public function getByDocumentAction(int $documentId): array
	{
		$response = ['process' => null];

		$userId = $this->getUserId();
		if ($userId < 1 || !Feature::instance()->isKedoRunProcessEnabled())
		{
			return $response;
		}

		$result = $this->container->getRunningProcessService()->getProcessByDocument($userId, $documentId);
		if (!$result->isSuccess())
		{
			$this->addDocumentNotFoundError();

			return $response;
		}

		$process = $result->getData()['process'] ?? null;
		$response['process'] = $process instanceof ProcessOrigin ? $this->serializeOrigin($process) : null;

		return $response;
	}

	/**
	 * A launch group as the top level of the list shows it. A group of one employee carries its single row
	 * itself: it is shown as an ordinary row of a process and does not open.
	 */
	private function serializeGroup(LaunchGroup $group): array
	{
		return [
			'launchId' => $group->launchId,
			'processName' => $group->processName,
			'memberCount' => $group->memberCount,
			'hasChildren' => $group->hasChildren(),
			'status' => $group->status->value,
			'startedTs' => $group->startedTs,
			'process' => $group->process === null ? null : $this->serializeProcess($group->process),
		];
	}

	/**
	 * One row of a launch group. launchItemId names the row for the retry of a single launch
	 * ({@see self::retryAction()}): canRetry offers an action, and without the identifier of the row the
	 * client could only retry the whole group.
	 */
	private function serializeProcess(RunningProcess $process): array
	{
		return [
			'workflowId' => $process->workflowId,
			'launchItemId' => $process->launchItemId,
			'entityId' => $process->entityId,
			'employeeId' => $process->employeeId,
			'title' => $process->title,
			'status' => $process->status->value,
			'stageName' => $process->stageName,
			'lastActivityTs' => $process->lastActivityTs,
			'participants' => array_map($this->serializeParticipant(...), $process->participants),
			'documents' => array_map($this->serializeDocument(...), $process->documents),
			'documentsState' => $process->documentsState->value,
			'noDocumentReason' => $process->noDocumentReason,
			'canRetry' => $process->canRetry,
			'protocolUrl' => $process->protocolUrl,
		];
	}

	private function serializeParticipant(ProcessParticipant $participant): array
	{
		return [
			'userId' => $participant->userId,
			'holdingSinceTs' => $participant->holdingSinceTs,
		];
	}

	/**
	 * The process of the card of a document. It carries both of its identifiers: the element is what the
	 * link leads to, and the process itself is what the client names the row of the list by.
	 */
	private function serializeOrigin(ProcessOrigin $origin): array
	{
		return [
			'workflowId' => $origin->workflowId,
			'entityId' => $origin->entityId,
			'name' => $origin->name,
			'url' => $origin->url,
		];
	}

	private function serializeDocument(ProcessDocument $document): array
	{
		return [
			'id' => $document->id,
			'title' => $document->title,
			'statusName' => $document->statusName,
			'url' => $document->url,
		];
	}

	/**
	 * A value of the filter as the transport may trust it. The body of the request belongs to the client, so
	 * a key that came as anything but a string counts as a key that did not come at all.
	 */
	private function getFilterValue(array $filter, string $key): ?string
	{
		$value = $filter[$key] ?? null;

		return is_string($value) ? $value : null;
	}

	/**
	 * Descriptions of the start fields in the format of the controls of bizproc. The set belongs to the
	 * version that will be executed for this employee: a form built from the live row would ask a
	 * participant of a pilot for the fields of somebody else's version.
	 */
	private function getStartFields(int $processId, int $userId): array
	{
		$parameters = (new StartFormParameters())->forInitiator(
			$processId,
			$userId,
			ManualStartSurface::StartForm,
			$this->getTemplateParameters($processId),
		);

		return FieldType::normalizePropertyList($parameters);
	}

	private function getTemplateParameters(int $processId): array
	{
		$row = WorkflowTemplateTable::query()
			->setSelect(['PARAMETERS'])
			->where('ID', $processId)
			->fetch()
		;
		$parameters = $row['PARAMETERS'] ?? null;

		return is_array($parameters) ? $parameters : [];
	}

	/**
	 * Complex document type of the personnel processes; the client needs it to render the controls of
	 * bizproc. Null while the smart process of the feature is absent.
	 */
	private function getProcessDocumentType(): ?array
	{
		$entityTypeId = $this->container->getCrmProcessTypeService()->getEntityTypeId();
		if ($entityTypeId === null || !Loader::includeModule('crm'))
		{
			return null;
		}

		return \CCrmBizProcHelper::ResolveDocumentType($entityTypeId);
	}

	private function getTemplateAccessService(): TemplateAccessService
	{
		return $this->templateAccessService ??= new TemplateAccessService();
	}

	private function getSelectorSourceAccessService(): SelectorSourceAccessService
	{
		if ($this->selectorSourceAccessService === null)
		{
			/** @var SelectorSourceAccessService $service */
			$service = Main\DI\ServiceLocator::getInstance()->get(SelectorSourceAccessService::class);
			$this->selectorSourceAccessService = $service;
		}

		return $this->selectorSourceAccessService;
	}

	private function getMemberService(): MemberService
	{
		return $this->memberService ??= $this->container->getMemberService();
	}

	private function createMemberEntityCollection(array $members): Main\Result
	{
		if ($members === [])
		{
			return $this->createMemberSelectionErrorResult(self::ERROR_MEMBER_SELECTION_EMPTY);
		}

		if (count($members) > self::MAX_MEMBER_SELECTOR_ENTITIES)
		{
			return $this->createMemberSelectionErrorResult(
				self::ERROR_MEMBER_LIMIT_EXCEEDED,
				['limit' => self::MAX_MEMBER_SELECTOR_ENTITIES],
			);
		}

		if (!array_is_list($members))
		{
			return $this->createMemberSelectionErrorResult(self::ERROR_MEMBER_SELECTION_INVALID);
		}

		$entities = new EntityCollection();
		foreach ($members as $member)
		{
			if (
				!is_array($member)
				|| !array_key_exists('entityType', $member)
				|| !array_key_exists('entityId', $member)
				|| !is_string($member['entityType'])
			)
			{
				return $this->createMemberSelectionErrorResult(self::ERROR_MEMBER_SELECTION_INVALID);
			}

			$entityId = $this->normalizeSelectorEntityId($member['entityType'], $member['entityId']);
			if ($entityId === null)
			{
				return $this->createMemberSelectionErrorResult(self::ERROR_MEMBER_SELECTION_INVALID);
			}

			$entity = Entity::createFromStrings($entityId, $member['entityType']);
			if ($entity->entityType === EntityType::Unknown || $entity->entityId < 1)
			{
				return $this->createMemberSelectionErrorResult(self::ERROR_MEMBER_SELECTION_INVALID);
			}

			$entities->add($entity);
		}

		return (new Main\Result())->setData(['entities' => $entities]);
	}

	private function normalizeSelectorEntityId(string $entityType, mixed $entityId): ?string
	{
		if (is_int($entityId))
		{
			return $entityId > 0 ? (string)$entityId : null;
		}

		if (!is_string($entityId))
		{
			return null;
		}

		if (preg_match('/^[1-9][0-9]*$/D', $entityId) === 1)
		{
			return $this->isSafePositiveIntegerString($entityId) ? $entityId : null;
		}

		if (
			$entityType !== 'structure-node'
			|| preg_match('/^([1-9][0-9]*):F$/D', $entityId, $matches) !== 1
		)
		{
			return null;
		}

		return $this->isSafePositiveIntegerString($matches[1]) ? $entityId : null;
	}

	private function isSafePositiveIntegerString(string $value): bool
	{
		$integer = (int)$value;

		return $integer > 0 && (string)$integer === $value;
	}

	private function createMemberSelectionErrorResult(string $code, array $customData = []): Main\Result
	{
		return (new Main\Result())->addError($this->createMemberSelectionError($code, $customData));
	}

	private function createMemberSelectionError(string $code, array $customData = []): Main\Error
	{
		$messageKey = match ($code)
		{
			self::ERROR_MEMBER_LIMIT_EXCEEDED => 'SIGN_CONTROLLERS_V1_B2E_PROCESS_ERROR_MEMBER_LIMIT_EXCEEDED',
			self::ERROR_MEMBER_SELECTION_INVALID => 'SIGN_CONTROLLERS_V1_B2E_PROCESS_ERROR_MEMBER_SELECTION_INVALID',
			default => 'SIGN_CONTROLLERS_V1_B2E_PROCESS_ERROR_NO_EMPLOYEES',
		};
		$replacements = null;
		if ($code === self::ERROR_MEMBER_LIMIT_EXCEEDED)
		{
			$replacements = ['#LIMIT#' => (int)($customData['limit'] ?? self::MAX_MEMBER_SELECTOR_ENTITIES)];
		}

		return new Main\Error((string)Loc::getMessage($messageKey, $replacements), $code, $customData);
	}

	private function addMemberSelectionErrors(Main\Result $result): void
	{
		Main\Context::getCurrent()->getResponse()->setStatus(422);
		foreach (
			[
				self::ERROR_MEMBER_LIMIT_EXCEEDED,
				self::ERROR_MEMBER_SELECTION_INVALID,
				self::ERROR_MEMBER_SELECTION_EMPTY,
			] as $code
		)
		{
			$error = $result->getErrorCollection()->getErrorByCode($code);
			if ($error === null)
			{
				continue;
			}

			$customData = $error->getCustomData();
			$this->addError($this->createMemberSelectionError(
				$code,
				is_array($customData) ? $customData : [],
			));

			return;
		}

		$this->addErrors($result->getErrors());
	}

	private function getUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	private function addProcessUnavailableError(): void
	{
		Main\Context::getCurrent()->getResponse()->setStatus(404);
		$this->addError(new Main\Error(
			(string)Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_PROCESS_ERROR_UNAVAILABLE'),
			self::ERROR_PROCESS_UNAVAILABLE,
		));
	}

	/**
	 * One answer for a document that is not there and for a document of somebody else. The right to the
	 * document is decided over the loaded document itself and not by the attribute of the action: an
	 * attribute would refuse a foreign document with a status of its own, and the difference between that
	 * status and this one is exactly what says that the document exists.
	 */
	private function addDocumentNotFoundError(): void
	{
		Main\Context::getCurrent()->getResponse()->setStatus(404);
		$this->addError(new Main\Error(
			(string)Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_PROCESS_ERROR_DOCUMENT_NOT_FOUND'),
			self::ERROR_DOCUMENT_NOT_FOUND,
		));
	}

	/**
	 * Right to start a process of this type on behalf of the employee. The legacy static call lives in a
	 * method of its own so that it can be replaced, the way the queue keeps the same check.
	 */
	protected function canStartProcessType(int $userId, array $documentType): bool
	{
		return \CBPDocument::canUserOperateDocumentType(
			\CBPCanUserOperateOperation::StartWorkflow,
			$userId,
			$documentType,
		);
	}

	/**
	 * No status of its own: a tariff refusal of the module is answered by the code, and the client meets it
	 * with the window of the tariff. The phrase is ours because the shared error of the module carries none -
	 * its key is defined in no language file - and a client that shows the text of the error instead of that
	 * window must not be left with an empty message.
	 */
	private function addTariffRestrictedError(): void
	{
		$this->addError(new Main\Error(
			(string)Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_PROCESS_ERROR_TARIFF_RESTRICTED'),
			self::ERROR_TARIFF_RESTRICTED,
		));
	}

	private function addStartDeniedError(): void
	{
		Main\Context::getCurrent()->getResponse()->setStatus(403);
		$this->addError(new Main\Error(
			(string)Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_PROCESS_ERROR_START_DENIED'),
			self::ERROR_START_DENIED,
		));
	}

	private function addSourceAccessDeniedError(): void
	{
		$this->addAccessDeniedError();
		Main\Context::getCurrent()->getResponse()->setStatus(403);
	}

	/**
	 * Failures of the launch as the client meets them. An unavailable process is answered the way the rest
	 * of the transport answers it; invalid and oversized employee sets keep the codes of the selector; the
	 * errors of the engine travel as they are - they hold the field of the form the client highlights.
	 */
	private function addLaunchErrors(Main\Result $result): void
	{
		$errorCollection = $result->getErrorCollection();

		if ($errorCollection->getErrorByCode(Operation\Process\Launch::ERROR_PROCESS_UNAVAILABLE) !== null)
		{
			$this->addProcessUnavailableError();

			return;
		}

		if (
			$errorCollection->getErrorByCode(Operation\Process\Launch::ERROR_EMPLOYEE_LIMIT_EXCEEDED) !== null
			|| $errorCollection->getErrorByCode(Operation\Process\Launch::ERROR_INVALID_EMPLOYEES) !== null
		)
		{
			$this->addMemberSelectionErrors($result);

			return;
		}

		Main\Context::getCurrent()->getResponse()->setStatus(422);
		$noEmployees = $errorCollection->getErrorByCode(Operation\Process\Launch::ERROR_NO_EMPLOYEES);
		if ($noEmployees === null)
		{
			$this->addErrors($result->getErrors());

			return;
		}

		$this->addError(new Main\Error(
			(string)Loc::getMessage('SIGN_CONTROLLERS_V1_B2E_PROCESS_ERROR_NO_EMPLOYEES'),
			$noEmployees->getCode(),
			$noEmployees->getCustomData(),
		));
	}

	/**
	 * Failures of the retry. Every refusal of the queue has a status and a phrase of its own: a group of
	 * somebody else is not the same answer as a group with nothing left to retry.
	 */
	private function addRetryErrors(Main\Result $result): void
	{
		$answers = [
			LaunchService::ERROR_LAUNCH_NOT_FOUND => [404, 'SIGN_CONTROLLERS_V1_B2E_PROCESS_ERROR_LAUNCH_NOT_FOUND'],
			LaunchService::ERROR_NOT_AUTHOR => [403, 'SIGN_CONTROLLERS_V1_B2E_PROCESS_ERROR_RETRY_DENIED'],
			LaunchService::ERROR_NOTHING_TO_RETRY => [422, 'SIGN_CONTROLLERS_V1_B2E_PROCESS_ERROR_NOTHING_TO_RETRY'],
		];

		$errorCollection = $result->getErrorCollection();
		foreach ($answers as $code => [$status, $phrase])
		{
			if ($errorCollection->getErrorByCode($code) === null)
			{
				continue;
			}

			Main\Context::getCurrent()->getResponse()->setStatus($status);
			$this->addError(new Main\Error((string)Loc::getMessage($phrase), $code));

			return;
		}

		$this->addErrors($result->getErrors());
	}

	/**
	 * The launch as the product analytics sees it: which process was started and how large the selection
	 * was. The event goes into the analytics of the module, so a launch from KEDO is counted the same way
	 * the module counts the rest of its events.
	 */
	private function sendLaunchAnalytics(int $processId, int $memberCount): void
	{
		$event = (new Main\Analytics\AnalyticsEvent(
			'process_start',
			'sign',
			'processes',
		))
			->setStatus(Main\Analytics\AnalyticsEvent::STATUS_SUCCESS)
			->setP1("processId_{$processId}")
			->setP2("memberCount_{$memberCount}")
		;

		$this->container->getAnalyticService()->sendEventWithSigningContext($event);
	}
}
