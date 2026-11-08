<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Filter\Options;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Config\Feature;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Item\Process\AvailableProcess;
use Bitrix\Sign\Item\Process\AvailableProcessCollection;
use Bitrix\Sign\Item\UserCollection;
use Bitrix\Sign\Repository\UserRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\Bizproc\ProcessCatalogService;

Loc::loadMessages(__FILE__);

CBitrixComponent::includeComponentClass('bitrix:sign.base');

/**
 * Catalog of the processes of the KEDO section - the page behind the "all processes" link of the create
 * button. It shows what the submenu shows and nothing more: both ask the same service of the section, and
 * the grid only drops the limit of the submenu (AC-002).
 */
final class SignB2eProcessCatalogComponent extends SignBaseComponent
{
	private const GRID_ID = 'SIGN_B2E_PROCESS_CATALOG_GRID';
	private const FILTER_ID = 'SIGN_B2E_PROCESS_CATALOG_FILTER';

	private readonly ProcessCatalogService $catalogService;
	private readonly UserRepository $userRepository;

	public function __construct($component = null)
	{
		parent::__construct($component);
		$this->catalogService = Container::instance()->getBizprocProcessCatalogService();
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
		$processes = $this->getAvailableProcesses();

		$this->setParam('GRID_ID', self::GRID_ID);
		$this->setParam('FILTER_ID', self::FILTER_ID);
		$this->setParam('COLUMNS', $this->getGridColumnList());
		$this->setParam('FILTER_FIELDS', $this->getFilterFieldList());
		$this->setResult('PROCESSES', $processes);
		$this->setResult('AUTHORS', $this->getAuthors($processes));
		$this->setResult('TOTAL_COUNT', $processes->count());
		$this->setResult('CAN_CREATE_PROCESS', $this->canCreateProcess());
		$this->setResult('CREATE_PROCESS_URL', $this->getStringParam('CREATE_PROCESS_URL'));
	}

	private function getAvailableProcesses(): AvailableProcessCollection
	{
		$userId = (int)CurrentUser::get()->getId();
		if ($userId < 1)
		{
			return AvailableProcessCollection::emptyList();
		}

		return $this->catalogService->getAvailableProcesses($userId, $this->getSearchQuery());
	}

	/**
	 * The right to create a process is not the right to start one (AC-013), so the button of the toolbar
	 * is answered by the role matrix of bizproc and never by the contents of the grid.
	 */
	private function canCreateProcess(): bool
	{
		$userId = (int)CurrentUser::get()->getId();
		if ($userId < 1 || !Loader::includeModule('bizproc'))
		{
			return false;
		}

		return (new TemplateAccessService())->canCreate($userId);
	}

	private function getSearchQuery(): ?string
	{
		$filterData = (new Options(self::FILTER_ID))->getFilter($this->getFilterFieldList());
		$searchQuery = trim((string)($filterData['NAME'] ?? $filterData['FIND'] ?? ''));

		return $searchQuery === '' ? null : $searchQuery;
	}

	private function getAuthors(AvailableProcessCollection $processes): UserCollection
	{
		$authorIds = [];
		foreach ($processes as $process)
		{
			/** @var AvailableProcess $process */
			if ($process->authorId > 0)
			{
				$authorIds[$process->authorId] = $process->authorId;
			}
		}

		return $this->userRepository->getByIds(array_values($authorIds));
	}

	/**
	 * The columns of the grid of the business processes section, minus the two it has no data for: the
	 * number of running instances and the personal pin both belong to bizproc and to its own storage.
	 */
	private function getGridColumnList(): array
	{
		return [
			[
				'id' => 'NAME',
				'name' => (string)Loc::getMessage('SIGN_B2E_PROCESS_CATALOG_COLUMN_NAME'),
				'default' => true,
			],
			[
				'id' => 'AUTHOR',
				'name' => (string)Loc::getMessage('SIGN_B2E_PROCESS_CATALOG_COLUMN_AUTHOR'),
				'default' => true,
			],
			[
				'id' => 'MODIFIED',
				'name' => (string)Loc::getMessage('SIGN_B2E_PROCESS_CATALOG_COLUMN_MODIFIED'),
				'default' => true,
			],
			[
				'id' => 'ACTION',
				'name' => (string)Loc::getMessage('SIGN_B2E_PROCESS_CATALOG_COLUMN_ACTION'),
				'default' => true,
			],
		];
	}

	private function getFilterFieldList(): array
	{
		return [
			[
				'id' => 'NAME',
				'name' => (string)Loc::getMessage('SIGN_B2E_PROCESS_CATALOG_FILTER_FIELD_NAME'),
				'type' => 'string',
				'default' => true,
			],
		];
	}
}
