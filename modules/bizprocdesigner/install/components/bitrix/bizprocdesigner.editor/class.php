<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\BizprocDesigner\Infrastructure\Enum\StartTrigger;

use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\Api;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Internal\Service\Feature\BpDesignerFeature;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Errorable;
use Bitrix\Main\ErrorableImplementation;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class BizprocdesignerEditorComponent extends \CBitrixComponent implements Errorable
{
	use ErrorableImplementation;
	private \CMain $application;
	private bool $isLegacyPropertiesDialog;
	private BpDesignerFeature $bpDesignerFeature;

	public function __construct($component = null, ?BpDesignerFeature $bpDesignerFeature = null)
	{
		parent::__construct($component);
		Loader::requireModule('bizproc');
		Loader::requireModule('bizprocdesigner');

		$this->errorCollection = new ErrorCollection();
		$this->application = $GLOBALS['APPLICATION'];
		$this->isLegacyPropertiesDialog = (bool)\Bitrix\Main\Config\Option::get('bizproc', 'legacy_properties_dialog', 1);

		$this->bpDesignerFeature = $bpDesignerFeature ?? ServiceLocator::getInstance()->get(BpDesignerFeature::class);
	}

	/**
	 * @param array $params
	 * @return array
	 */
	public function onPrepareComponentParams($params): array
	{
		$params['SET_TITLE'] = (($params['SET_TITLE'] ?? '') !== 'N');
		$params['BACK_URL'] = (
			!empty($_REQUEST['back_url'])
			&& $_REQUEST['back_url'][0] === '/'
			&& $_REQUEST['back_url'][1] !== '/'
		)
		? (string)$_REQUEST['back_url']
		: null;

		if (!isset($params['MODULE_ID'], $params['ENTITY'], $params['DOCUMENT_TYPE']))
		{
			[
				$params['MODULE_ID'],
				$params['ENTITY'],
				$params['DOCUMENT_TYPE'],
			] = \Bitrix\Bizproc\Public\Entity\Document\Workflow::getComplexType();
		}

		if (!empty($params['ID']))
		{
			$templateResult = WorkflowTemplateTable::getList([
				'filter' => [
					'=ID' => $params['ID'],
					'=TYPE' => Api\Enum\Template\WorkflowTemplateType::Nodes->value,
				],
				'select' => ['MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE'],
			]);
			if ($row = $templateResult->fetch())
			{
				[$params['MODULE_ID'], $params['ENTITY'], $params['DOCUMENT_TYPE']] = array_values($row);
			}
		}

		return $params;
	}

	public function executeComponent(): void
	{
		if (!$this->bpDesignerFeature->isAvailable())
		{
			$this->arResult['ERROR_TITLE'] = Loc::getMessage('BIZPROCDESIGNER_EDITOR_TARIFF_ERROR_TITLE') ?? '';
			$this->arResult['ERROR_SUBTITLE'] = Loc::getMessage('BIZPROCDESIGNER_EDITOR_TARIFF_ERROR_SUBTITLE') ?? '';

			$this->includeComponentTemplate('error');

			return;
		}

		$this->setTemplateTitle(Loc::getMessage('BIZPROCDESIGNER_EDITOR_MAIN_PAGE_TITLE'));

		if (!empty($this->arParams['START_TRIGGER']))
		{
			$this->fillStartTrigger($this->arParams['START_TRIGGER']);
		}

		$user = new CBPWorkflowTemplateUser(\CBPWorkflowTemplateUser::CurrentUser);

		if (!$user->isAdmin())
		{
			$this->arResult['ERROR_TITLE'] = Loc::getMessage('BIZPROCDESIGNER_EDITOR_ACCESS_RIGHTS_ERROR_TITLE') ?? '';
			$this->arResult['ERROR_SUBTITLE'] = Loc::getMessage('BIZPROCDESIGNER_EDITOR_ACCESS_RIGHTS_ERROR_SUBTITLE') ?? '';

			$this->includeComponentTemplate('error');

			return;
		}

		if ($this->isLegacyPropertiesDialog)
		{
			$this->fillLegacyModeResult();
		}

		$this->arResult['templateId'] = (int)($this->arParams['ID'] ?? 0);
		$this->arResult['documentType'] = \CBPHelper::normalizeComplexDocumentId(
			[
				$this->arParams['MODULE_ID'] ?? '',
				$this->arParams['ENTITY'] ?? '',
				$this->arParams['DOCUMENT_TYPE'] ?? '',
			],
		);

		$this->includeComponentTemplate();
	}

	protected function setTemplateTitle(?string $templateName): void
	{
		$this->application->SetTitle($templateName);
	}

	/**
	 * @return void
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	private function fillLegacyModeResult(): void
	{
		$this->arResult['IS_LEGACY_PROPERTIES_DIALOG'] = true;

		/** @var Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher $activitySearcher */
		$activitySearcher = \Bitrix\Main\DI\ServiceLocator::getInstance()->get('bizproc.runtime.activitysearcher.searcher');
		$documentType = [$this->arParams['MODULE_ID'], $this->arParams['ENTITY'], $this->arParams['DOCUMENT_TYPE']];

		$this->arResult['ALL_NODES'] =
			$activitySearcher
				->searchByType([ActivityType::NODE->value, ActivityType::ACTIVITY->value, ActivityType::TRIGGER->value], $documentType)
				->computeDescriptionFilter($documentType)
				->sort()
		;
	}

	private function fillStartTrigger(?string $startTrigger): void
	{
		$this->arResult['startTrigger'] = StartTrigger::tryFrom($startTrigger)?->value;
	}
}
