<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Grid\Actions as GridActions;
use Bitrix\Main\Grid\Context as GridContext;
use Bitrix\Main\Grid\Options as GridOptions;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Filter\Options;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Config\Feature;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Integration\CRM\Entity;
use Bitrix\Sign\Item\Process\LaunchGroup;
use Bitrix\Sign\Item\Process\ProcessParticipant;
use Bitrix\Sign\Item\Process\RunningProcess;
use Bitrix\Sign\Item\UserCollection;
use Bitrix\Sign\Repository\UserRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\Crm\ProcessTypeService;
use Bitrix\Sign\Service\Process\RunningProcessService;
use Bitrix\Sign\Type\Process\RunningProcessFilterState;

Loc::loadMessages(__FILE__);

CBitrixComponent::includeComponentClass('bitrix:sign.base');

/**
 * Tab "Processes" of the KEDO section - what has already been started. The page keeps no states of its own:
 * every row is described by the service of the running processes, and the counter of a group as well as its
 * opened composition are counted there by the access of the person looking (AC-015b).
 *
 * The top level of the list is the launch groups; the composition of a group is asked for by the grid itself
 * when the row is opened, so a launch on thirty employees does not drag thirty rows into the first answer
 * (AC-015).
 */
final class SignB2eProcessListComponent extends SignBaseComponent
{
	private const GRID_ID = 'SIGN_B2E_PROCESS_LIST_GRID';
	private const FILTER_ID = 'SIGN_B2E_PROCESS_LIST_FILTER';

	/**
	 * The name of the page parameter of the "show more" button of the grid. The grid builds the address of
	 * the next page out of it by itself, and the component reads the page back from the same name.
	 */
	private const NAV_PARAM_NAME = 'sign_process_page';

	private const GROUP_PAGE_SIZE = 20;

	/**
	 * The composition of a group is given out in one answer: the grid opens a row once and has no second
	 * step for the rest of its children. The limit still holds - a launch larger than this says so with a
	 * row of its own instead of silently losing its tail.
	 */
	private const GROUP_CONTENT_LIMIT = 100;

	private const GROUP_ROW_PREFIX = 'group-';
	private const PROCESS_ROW_PREFIX = 'process-';

	private readonly RunningProcessService $runningProcessService;
	private readonly ProcessTypeService $processTypeService;
	private readonly UserRepository $userRepository;
	private ?array $filterData = null;

	public function __construct($component = null)
	{
		parent::__construct($component);
		$this->runningProcessService = Container::instance()->getRunningProcessService();
		$this->processTypeService = Container::instance()->getCrmProcessTypeService();
		$this->userRepository = Container::instance()->getUserRepository();
	}

	public function executeComponent(): void
	{
		if (!Storage::instance()->isB2eAvailable() || !Feature::instance()->isKedoRunProcessEnabled())
		{
			$this->includeNotAvailableTemplate();

			return;
		}

		if (!$this->getAccessController()->check(ActionDictionary::ACTION_B2E_DOCUMENT_READ))
		{
			$this->includeAccessDeniedTemplate();

			return;
		}

		parent::executeComponent();
	}

	public function exec(): void
	{
		$this->setParam('GRID_ID', self::GRID_ID);
		$this->setParam('FILTER_ID', self::FILTER_ID);
		$this->setParam('NAV_PARAM_NAME', self::NAV_PARAM_NAME);
		$this->setParam('COLUMNS', $this->getGridColumnList());
		$this->setParam('FILTER_FIELDS', $this->getFilterFieldList());
		$this->setParam('FILTER_PRESETS', $this->getFilterPresets());

		$this->setResult('CAN_CREATE_PROCESS', $this->canCreateProcess());
		$this->setResult('CREATE_PROCESS_URL', $this->getStringParam('CREATE_PROCESS_URL'));
		$this->setResult('PROCESS_CATALOG_URL', $this->getStringParam('PROCESS_CATALOG_URL'));
		// the smart process of the personnel processes may be gone from the portal, and then the tab has
		// nothing to list and nothing to offer (ERR-009)
		$this->setResult('IS_ENTITY_AVAILABLE', $this->processTypeService->isAvailable());

		$openedLaunchId = $this->getOpenedLaunchId();
		if ($openedLaunchId === null)
		{
			$this->fillGroupList();

			return;
		}

		$this->fillGroupContent($openedLaunchId);
	}

	/**
	 * The rows of the whole set, always the groups alone: the composition of a group is a request of its
	 * own, so the answer never carries children. The grid keeps the opened rows in the session and would
	 * mark a group opened over an answer that has no children in it - the opening is a move of the person
	 * and not a state of the tab, so the set of the rows starts closed every time it is built anew.
	 */
	private function fillGroupList(): void
	{
		(new GridOptions(self::GRID_ID))->resetExpandedRows();

		$page = $this->getCurrentPage();
		$processPage = $this->runningProcessService->listGroups(
			$this->getCurrentUserId(),
			RunningProcessFilterState::tryFromOrDefault($this->getFilterValue('STATE')),
			$this->getFilterValue('FIND'),
			self::GROUP_PAGE_SIZE,
			($page - 1) * self::GROUP_PAGE_SIZE,
		);

		/** @var list<LaunchGroup> $groups */
		$groups = $processPage->items;
		$processes = [];
		foreach ($groups as $group)
		{
			if ($group->process !== null)
			{
				$processes[] = $group->process;
			}
		}

		$this->setResult('GROUPS', $groups);
		$this->setResult('PROCESSES', []);
		$this->setResult('PARENT_ROW_ID', null);
		$this->setResult('HAS_TRIMMED_CONTENT', false);
		$this->setResult('CURRENT_PAGE', $page);
		$this->setResult('HAS_NEXT_PAGE', $processPage->hasNextPage);
		$this->fillProcessDetails($processes);
	}

	private function fillGroupContent(int $launchId): void
	{
		$processPage = $this->runningProcessService->listGroupProcesses(
			$this->getCurrentUserId(),
			$launchId,
			self::GROUP_CONTENT_LIMIT,
		);

		/** @var list<RunningProcess> $processes */
		$processes = $processPage->items;

		$this->setResult('GROUPS', []);
		$this->setResult('PROCESSES', $processes);
		$this->setResult('PARENT_ROW_ID', self::GROUP_ROW_PREFIX . $launchId);
		$this->setResult('HAS_TRIMMED_CONTENT', $processPage->hasNextPage);
		$this->setResult('GROUP_CONTENT_LIMIT', self::GROUP_CONTENT_LIMIT);
		$this->setResult('CURRENT_PAGE', 1);
		$this->setResult('HAS_NEXT_PAGE', false);
		$this->fillProcessDetails($processes);
	}

	/**
	 * Everything the rows of the processes are drawn with but do not carry themselves: the people behind the
	 * identifiers and the address of the card of the process.
	 *
	 * @param list<RunningProcess> $processes
	 */
	private function fillProcessDetails(array $processes): void
	{
		$this->setResult('USERS', $this->loadUsers($processes));
		$this->setResult('PROCESS_URLS', $this->buildProcessUrls($processes));
		$this->setResult('GROUP_ROW_PREFIX', self::GROUP_ROW_PREFIX);
		$this->setResult('PROCESS_ROW_PREFIX', self::PROCESS_ROW_PREFIX);
	}

	/**
	 * The group whose composition the grid is asking for. The row of a group names it in its own identifier,
	 * so a request about anything else is answered by the ordinary list.
	 */
	private function getOpenedLaunchId(): ?int
	{
		if (!GridContext::isInternalRequest() || $this->getRequest('action') !== GridActions::GRID_GET_CHILD_ROWS)
		{
			return null;
		}

		$parentId = $this->getRequest('parent_id');
		if (!is_string($parentId) || !str_starts_with($parentId, self::GROUP_ROW_PREFIX))
		{
			return null;
		}

		$launchId = (int)substr($parentId, strlen(self::GROUP_ROW_PREFIX));

		return $launchId > 0 ? $launchId : null;
	}

	private function getCurrentPage(): int
	{
		$page = (int)$this->getRequest(self::NAV_PARAM_NAME);

		return max(1, $page);
	}

	private function getCurrentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	/**
	 * The right to create a process is not the right to start one (AC-013), so the button of the toolbar is
	 * answered by the role matrix of bizproc and never by the contents of the grid.
	 */
	private function canCreateProcess(): bool
	{
		$userId = $this->getCurrentUserId();
		if ($userId < 1 || !Loader::includeModule('bizproc'))
		{
			return false;
		}

		return (new TemplateAccessService())->canCreate($userId);
	}

	/**
	 * A value of the filter as the person set it. The presets go into the options and not only into the
	 * toolbar: the fields of a preset are resolved out of them, so a filter built without them would answer
	 * the chosen "finished" with the rows of "in work".
	 */
	private function getFilterValue(string $key): ?string
	{
		$this->filterData ??= (new Options(self::FILTER_ID, $this->getFilterPresets()))
			->getFilter($this->getFilterFieldList())
		;
		$value = $this->filterData[$key] ?? null;
		$value = is_string($value) ? trim($value) : '';

		return $value === '' ? null : $value;
	}

	/**
	 * @param list<RunningProcess> $processes
	 */
	private function loadUsers(array $processes): UserCollection
	{
		$userIds = [];
		foreach ($processes as $process)
		{
			if ($process->employeeId > 0)
			{
				$userIds[$process->employeeId] = $process->employeeId;
			}

			foreach ($process->participants as $participant)
			{
				/** @var ProcessParticipant $participant */
				if ($participant->userId > 0)
				{
					$userIds[$participant->userId] = $participant->userId;
				}
			}
		}

		return $this->userRepository->getByIds(array_values($userIds));
	}

	/**
	 * Addresses of the cards of the processes - the elements of the smart process of the personnel processes
	 * (Q-004 of the brief). A row whose process has not started yet has no element and no address.
	 *
	 * @param list<RunningProcess> $processes
	 *
	 * @return array<int, string>
	 */
	private function buildProcessUrls(array $processes): array
	{
		$entityTypeId = $this->processTypeService->getEntityTypeId();
		if ($entityTypeId === null)
		{
			return [];
		}

		$urls = [];
		foreach ($processes as $process)
		{
			$entityId = $process->entityId ?? 0;
			if ($entityId < 1 || isset($urls[$entityId]))
			{
				continue;
			}

			$url = Entity::getDetailPageUri($entityTypeId, $entityId)?->getUri();
			if ($url !== null)
			{
				$urls[$entityId] = $url;
			}
		}

		return $urls;
	}

	/**
	 * The columns of the grid of the business processes section plus the one of our own - the documents the
	 * process has created (section 4.3 of the brief). The name carries the opening of a group, so it is the
	 * column the shift of the depth is counted on.
	 */
	private function getGridColumnList(): array
	{
		return [
			[
				'id' => 'NAME',
				'name' => (string)Loc::getMessage('SIGN_B2E_PROCESS_LIST_COLUMN_NAME'),
				'default' => true,
				'shift' => true,
			],
			[
				'id' => 'ACTIVITY',
				'name' => (string)Loc::getMessage('SIGN_B2E_PROCESS_LIST_COLUMN_ACTIVITY'),
				'default' => true,
			],
			[
				'id' => 'PARTICIPANTS',
				'name' => (string)Loc::getMessage('SIGN_B2E_PROCESS_LIST_COLUMN_PARTICIPANTS'),
				'default' => true,
			],
			[
				'id' => 'STATUS',
				'name' => (string)Loc::getMessage('SIGN_B2E_PROCESS_LIST_COLUMN_STATUS'),
				'default' => true,
			],
			[
				'id' => 'DOCUMENTS',
				'name' => (string)Loc::getMessage('SIGN_B2E_PROCESS_LIST_COLUMN_DOCUMENTS'),
				'default' => true,
			],
			[
				'id' => 'TIME',
				'name' => (string)Loc::getMessage('SIGN_B2E_PROCESS_LIST_COLUMN_TIME'),
				'default' => true,
			],
		];
	}

	/**
	 * "From the company / from the employees" is a division of documents and says nothing about a process, so
	 * this view does not offer it (AC-010): the state of the process and the search over its name are the
	 * whole filter.
	 */
	private function getFilterFieldList(): array
	{
		return [
			[
				'id' => 'STATE',
				'name' => (string)Loc::getMessage('SIGN_B2E_PROCESS_LIST_FILTER_FIELD_STATE'),
				'type' => 'list',
				'items' => [
					RunningProcessFilterState::InWork->value =>
						(string)Loc::getMessage('SIGN_B2E_PROCESS_LIST_FILTER_STATE_IN_WORK'),
					RunningProcessFilterState::Completed->value =>
						(string)Loc::getMessage('SIGN_B2E_PROCESS_LIST_FILTER_STATE_COMPLETED'),
				],
				'default' => true,
			],
		];
	}

	/**
	 * The tab opens on the launches that are still running, the same way the kanban does (AC-009); the
	 * finished ones are a preset away.
	 */
	private function getFilterPresets(): array
	{
		return [
			'inWork' => [
				'name' => (string)Loc::getMessage('SIGN_B2E_PROCESS_LIST_FILTER_STATE_IN_WORK'),
				'default' => true,
				'fields' => [
					'STATE' => RunningProcessFilterState::InWork->value,
				],
			],
			'completed' => [
				'name' => (string)Loc::getMessage('SIGN_B2E_PROCESS_LIST_FILTER_STATE_COMPLETED'),
				'fields' => [
					'STATE' => RunningProcessFilterState::Completed->value,
				],
			],
		];
	}
}
