<?php

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\UI\Filter\Options;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Sign\Config\Feature;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\Crm\MyCompanyService;
use Bitrix\Sign\Service\Sign\Document\TemplateService;
use Bitrix\Sign\Service\Sign\DocumentService;

Loc::loadMessages(__FILE__);

CBitrixComponent::includeComponentClass('bitrix:sign.base');

final class SignB2eEmployeeTemplateListComponent extends SignBaseComponent
{
	private const DEFAULT_GRID_ID = 'SIGN_B2E_EMPLOYEE_TEMPLATE_LIST_GRID';
	private const DEFAULT_FILTER_ID = 'SIGN_B2E_EMPLOYEE_TEMPLATE_LIST_FILTER';
	private const DEFAULT_NAVIGATION_KEY = 'sign-b2e-employee-template-list';
	private const DEFAULT_PAGE_SIZE = 10;
	private const ADD_NEW_TEMPLATE_LINK = '/sign/b2e/doc/0/?mode=template';
	private readonly TemplateService $documentTemplateService;
	private readonly PageNavigation $pageNavigation;
	private readonly DocumentRepository $documentRepository;
	private readonly DocumentService $documentService;
	private readonly MyCompanyService $myCompanyService;

	public function __construct($component = null)
	{
		parent::__construct($component);
		$this->documentTemplateService = Container::instance()->getDocumentTemplateService();
		$this->documentRepository = Container::instance()->getDocumentRepository();
		$this->documentService = Container::instance()->getDocumentService();
		$this->myCompanyService = Container::instance()->getCrmMyCompanyService();
		$this->pageNavigation = $this->getPageNavigation();
	}

	public function executeComponent(): void
	{
		if (!Storage::instance()->isB2eAvailable())
		{
			showError((string)Loc::getMessage('SIGN_B2E_EMPLOYEE_TEMPLATE_LIST_B2E_NOT_ACTIVATED'));

			return;
		}

		if (!Feature::instance()->isSendDocumentByEmployeeEnabled())
		{
			showError((string)Loc::getMessage('SIGN_B2E_EMPLOYEE_TEMPLATE_LIST_TO_EMPLOYEE_NOT_ACTIVATED'));

			return;
		}

		parent::executeComponent();
	}

	public function exec(): void
	{
		$this->setResult('NAVIGATION_KEY', $this->pageNavigation->getId());
		$this->setResult('CURRENT_PAGE', $this->getNavigation()->getCurrentPage());
		$this->setParam('ADD_NEW_TEMPLATE_LINK', self::ADD_NEW_TEMPLATE_LINK);
		$this->setParam('COLUMNS', $this->getGridColumnList());
		$this->setParam('FILTER_FIELDS', $this->getFilterFieldList());
		$this->setParam('DEFAULT_FILTER_FIELDS', $this->getFilterFieldList());
		$this->setParam('GRID_ID', self::DEFAULT_GRID_ID);
		$this->setParam('FILTER_ID', self::DEFAULT_FILTER_ID);
		$this->setResult('TOTAL_COUNT', $this->pageNavigation->getRecordCount());
		$this->setResult('DOCUMENT_TEMPLATES', $this->getGridData());
		$this->setResult('PAGE_SIZE', $this->pageNavigation->getPageSize());
		$this->setResult('PAGE_NAVIGATION', $this->pageNavigation);
	}

	private function prepareNavigation(): PageNavigation
	{
		$pageNavigation = new \Bitrix\Sign\Util\UI\PageNavigation($this->arResult['NAVIGATION_KEY']);
		$pageNavigation
			->setPageSize($this->arResult['PAGE_SIZE'] ?? $this->pageNavigation->getPageSize())
			->allowAllRecords(false)
			->initFromUri()
		;
		$this->arResult['PAGE_NAVIGATION'] = $pageNavigation;

		return $pageNavigation;
	}

	private function getNavigation(): PageNavigation
	{
		if (!isset($this->arResult['PAGE_NAVIGATION']))
		{
			return $this->prepareNavigation();
		}

		return $this->arResult['PAGE_NAVIGATION'];
	}

	private function getGridData(): array
	{
		$currentPageElements = $this->getCurrentPageElements();

		if (empty($currentPageElements))
		{
			$this->decrementCurrentPage();
			$currentPageElements = $this->getCurrentPageElements();
		}

		return $this->mapElementsToGridData($currentPageElements);
	}

	private function getCurrentPageElements(): array
	{
		return $this->documentTemplateService->getB2eEmployeeTemplateList(
			$this->getFilterQuery(),
			$this->pageNavigation->getPageSize(),
			$this->pageNavigation->getOffset()
		)->toArray();
	}

	private function decrementCurrentPage(): void
	{
		$this->pageNavigation->setCurrentPage($this->pageNavigation->getCurrentPage() - 1);
	}

	private function mapElementsToGridData(array $elements): array
	{
		return array_map([$this, 'mapTemplateToGridData'], $elements);
	}

	private function mapTemplateToGridData(Document\Template $template): array
	{
		$responsibleData = CUser::GetByID($template->modifiedById ?? $template->createdById)->Fetch();
		$personalPhoto = $responsibleData['PERSONAL_PHOTO'] ?? false;
		$responsibleAvatarPath = $personalPhoto
			? htmlspecialcharsbx(CFile::GetPath($personalPhoto))
			: ''
		;
		$responsibleName = $responsibleData['NAME'] ?? '';
		$responsibleLastName = $responsibleData['LAST_NAME'] ?? '';
		$responsibleFullName = htmlspecialcharsbx("$responsibleName $responsibleLastName");

		$documents = $this->documentRepository->listByTemplateIds([$template->id]);
		$companyIds = $this->documentService->listMyCompanyIdsForDocuments($documents);
		$firstDocument = $documents->getFirst();
		$companyId = $firstDocument?->id !== null
			? $companyIds[$firstDocument->id] ?? null
			: null
		;
		$companies = $this->myCompanyService->listWithTaxIds(inIds: $companyIds);

		$company = null;
		if ($companyId !== null)
		{
			$company = $companies->findById($companyId);
		}

		return [
			'id' => $template->id,
			'columns' => [
				'ID' => $template->id,
				'TITLE' => $template->title,
				'DATE_MODIFY' => $template->dateModify ?? $template->dateCreate ?? null,
				'RESPONSIBLE' => [
					'ID' => $template->modifiedById,
					'FULL_NAME' => $responsibleFullName,
					'AVATAR_PATH' => $responsibleAvatarPath,
				],
				'VISIBILITY' => $template->visibility,
				'STATUS' => $template->status,
				'COMPANY' => $company?->name,
			]
		];
	}


	private function getGridColumnList(): array
	{
		return [
			[
				'id' => 'ID',
				'name' => (string)Loc::getMessage('SIGN_B2E_EMPLOYEE_TEMPLATE_LIST_COLUMN_ID'),
				'default' => false,
			],
			[
				'id' => 'TITLE',
				'name' => (string)Loc::getMessage('SIGN_B2E_EMPLOYEE_TEMPLATE_LIST_COLUMN_NAME'),
				'default' => true,
			],
			[
				'id' => 'COMPANY',
				'name' => (string)Loc::getMessage('SIGN_B2E_EMPLOYEE_TEMPLATE_LIST_COLUMN_COMPANY'),
				'default' => true,
			],
			[
				'id' => 'RESPONSIBLE',
				'name' => (string)Loc::getMessage('SIGN_B2E_EMPLOYEE_TEMPLATE_LIST_COLUMN_RESPONSIBLE'),
				'default' => true,
			],
			[
				'id' => 'DATE_MODIFY',
				'name' => (string)Loc::getMessage('SIGN_B2E_EMPLOYEE_TEMPLATE_LIST_COLUMN_DATE_MODIFY'),
				'default' => true,
			],
			[
				'id' => 'VISIBILITY',
				'name' => (string)Loc::getMessage('SIGN_B2E_EMPLOYEE_TEMPLATE_LIST_COLUMN_VISIBILITY'),
				'default' => true,
			],
		];
	}

	private function getFilterFieldList(): array
	{
		return [
			[
				'id' => 'TITLE',
				'name' => (string)Loc::getMessage('SIGN_B2E_EMPLOYEE_TEMPLATE_LIST_COLUMN_NAME'),
				'default' => true,
			],
			[
				'id' => 'DATE_MODIFY',
				'name' => (string)Loc::getMessage('SIGN_B2E_EMPLOYEE_TEMPLATE_LIST_COLUMN_DATE_MODIFY'),
				'type' => 'date',
				'default' => true,
			],
		];
	}

	private function getPageNavigation(): PageNavigation
	{
		$pageSize = (int)$this->getParam('PAGE_SIZE');
		$pageSize = $pageSize > 0 ? $pageSize : self::DEFAULT_PAGE_SIZE;
		$navigationKey = $this->getParam('NAVIGATION_KEY') ?? self::DEFAULT_NAVIGATION_KEY;

		$pageNavigation = new \Bitrix\Sign\Util\UI\PageNavigation($navigationKey);
		$pageNavigation->setPageSize($pageSize)
			->setRecordCount($this->documentTemplateService->getB2eEmployeeTemplateListCount($this->getFilterQuery()))
			->allowAllRecords(false)
			->initFromUri()
		;

		return $pageNavigation;
	}

	private function getFilterQuery(): ConditionTree
	{
		$filterData = $this->getFilterValues();

		return $this->prepareQueryFilter($filterData);
	}

	private function getFilterValues(): array
	{
		$options = new Options(self::DEFAULT_FILTER_ID);

		return $options->getFilter($this->getFilterFieldList());
	}

	private function prepareQueryFilter(array $filterData): ConditionTree
	{
		$filter = Bitrix\Main\ORM\Query\Query::filter();

		$dateModifyFrom = $filterData['DATE_MODIFY_from'] ?? null;
		$dateModifyTo = $filterData['DATE_MODIFY_to'] ?? null;
		$find = $filterData['FIND'] ?? null;
		$title = $find ?: $filterData['TITLE'] ?? null;

		if ($dateModifyFrom && \Bitrix\Main\Type\DateTime::isCorrect($dateModifyFrom))
		{
			$filter->where('DATE_MODIFY', '>=', new \Bitrix\Main\Type\DateTime($dateModifyFrom));
		}

		if ($dateModifyTo && \Bitrix\Main\Type\DateTime::isCorrect($dateModifyTo))
		{
			$filter->where('DATE_MODIFY', '<=', new \Bitrix\Main\Type\DateTime($dateModifyTo));
		}

		if ($title)
		{
			$filter->whereLike('TITLE', '%' . $title . '%');
		}

		return $filter;
	}
}
