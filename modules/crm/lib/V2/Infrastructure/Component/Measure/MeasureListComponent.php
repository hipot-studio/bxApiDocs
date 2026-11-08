<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Measure;

use Bitrix\Crm\Component\Base;
use Bitrix\Crm\V2\Infrastructure\Component\Measure\Grid\MeasureGrid;
use Bitrix\Crm\V2\Internal\Access\Measure\MeasureAccess;
use Bitrix\Crm\V2\Internal\Service\Measure\BatchDeleteService;
use Bitrix\Crm\V2\Internal\Service\Measure\MeasureMutationService;
use Bitrix\Crm\V2\Internal\Service\Measure\MeasureReadService;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Error;
use Bitrix\Main\Grid\Component\ComponentParams;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * @internal
 */
class MeasureListComponent extends Base implements Controllerable
{
	public function configureActions(): array
	{
		return [
			'deleteItem' => [
				'+prefilters' => $this->getActionPreFilters(),
			],
			'delete' => [
				'+prefilters' => $this->getActionPreFilters(),
			],
			'cancel' => [
				'+prefilters' => $this->getActionPreFilters(),
			],
			'finalize' => [
				'+prefilters' => $this->getActionPreFilters(),
			],
		];
	}

	public function deleteItemAction(int $id): void
	{
		if ($this->prepareActionContext() === null)
		{
			return;
		}

		$result = $this->getMeasureMutationService()->delete($id);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
		}
	}

	/**
	 * @param array<int, mixed> $ids
	 */
	public function deleteAction(string $PROCESS_TOKEN, string $scope, array $ids = []): ?array
	{
		$userId = $this->prepareActionContext();
		if ($userId === null)
		{
			return null;
		}

		$result = $this->getBatchDeleteService()->delete($userId, $PROCESS_TOKEN, $scope, $ids);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $this->localizeDto($result, 'delete');
	}

	public function cancelAction(string $PROCESS_TOKEN, ?string $cancelingAction = null): ?array
	{
		$userId = $this->prepareActionContext();
		if ($userId === null)
		{
			return null;
		}

		if ($cancelingAction !== null && $cancelingAction !== 'delete')
		{
			$this->addError(new Error('Invalid canceling action.', 'INVALID_ARGUMENT'));

			return null;
		}

		$result = $this->getBatchDeleteService()->cancel($userId, $PROCESS_TOKEN);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $this->localizeDto($result, 'cancel');
	}

	public function finalizeAction(string $PROCESS_TOKEN, ?string $cancelingAction = null): ?array
	{
		$userId = $this->prepareActionContext();
		if ($userId === null)
		{
			return null;
		}

		if ($cancelingAction !== null && $cancelingAction !== 'delete')
		{
			$this->addError(new Error('Invalid canceling action.', 'INVALID_ARGUMENT'));

			return null;
		}

		$result = $this->getBatchDeleteService()->finalize($userId, $PROCESS_TOKEN);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $this->localizeDto($result, 'finalize');
	}

	public function executeComponent(): void
	{
		$this->init();
		$this->prepareParameters();

		try
		{
			if (!$this->getMeasureAccess()->canEdit())
			{
				\ShowError(Loc::getMessage('CRM_PERMISSION_DENIED'));

				return;
			}

			$this->arResult['grid'] = $this->prepareGridParameters();
		}
		catch (LoaderException)
		{
			\ShowError(Loc::getMessage('CATALOG_MODULE_NOT_INSTALLED'));

			return;
		}

		$this->includeComponentTemplate();
	}

	protected function getMeasureAccess(): MeasureAccess
	{
		return new MeasureAccess();
	}

	protected function getMeasureReadService(): MeasureReadService
	{
		return new MeasureReadService();
	}

	protected function getBatchDeleteService(): BatchDeleteService
	{
		return new BatchDeleteService();
	}

	protected function getMeasureMutationService(): MeasureMutationService
	{
		return new MeasureMutationService();
	}

	private function getActionPreFilters(): array
	{
		return [
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			new ActionFilter\Csrf(),
		];
	}

	private function prepareActionContext(): ?int
	{
		$this->init();
		if (!check_bitrix_sessid())
		{
			$this->addError(new Error('Invalid user session.', 'INVALID_SESSION'));

			return null;
		}

		$userId = \CCrmSecurityHelper::GetCurrentUserID();
		if ($userId <= 0)
		{
			$this->addError(new Error('Invalid user session.', 'INVALID_SESSION'));

			return null;
		}

		if (!$this->getMeasureAccess()->canEdit())
		{
			$this->addError(new Error('Access denied.', 'ACCESS_DENIED'));

			return null;
		}

		return $userId;
	}

	private function localizeDto(Result $result, string $action): array
	{
		Loc::loadMessages(__FILE__);

		$data = $result->getData();
		$errorCount = count($data['ERRORS']);
		$data['WARNING'] = $errorCount > 0
			? (string)Loc::getMessage('CRM_MEASURE_BATCH_DELETE_WARNING', ['#COUNT#' => $errorCount])
			: ''
		;

		if ($action === 'cancel')
		{
			$data['SUMMARY'] = (string)Loc::getMessage('CRM_MEASURE_BATCH_DELETE_CANCELED');
		}
		elseif ($action === 'finalize')
		{
			$data['SUMMARY'] = (string)Loc::getMessage('CRM_MEASURE_BATCH_DELETE_FINALIZED');
		}
		elseif ($data['STATUS'] === 'COMPLETED')
		{
			$data['SUMMARY'] = (string)Loc::getMessage(
				'CRM_MEASURE_BATCH_DELETE_COMPLETED',
				[
					'#DELETED#' => $data['DELETED_ITEMS'],
					'#TOTAL#' => $data['TOTAL_ITEMS'],
				],
			);
		}
		else
		{
			$data['SUMMARY'] = (string)Loc::getMessage(
				'CRM_MEASURE_BATCH_DELETE_PROGRESS',
				[
					'#PROCESSED#' => $data['PROCESSED_ITEMS'],
					'#TOTAL#' => $data['TOTAL_ITEMS'],
				],
			);
		}

		return $data;
	}

	private function prepareParameters(): void
	{
		$this->arParams['PATH_TO_MEASURE_LIST'] = \CrmCheckPath(
			'PATH_TO_MEASURE_LIST',
			(string)($this->arParams['PATH_TO_MEASURE_LIST'] ?? ''),
			'',
		);
		$this->arParams['PATH_TO_MEASURE_EDIT'] = \CrmCheckPath(
			'PATH_TO_MEASURE_EDIT',
			(string)($this->arParams['PATH_TO_MEASURE_EDIT'] ?? ''),
			'',
		);

		$isSidePanel = in_array($this->arParams['IS_SIDE_PANEL'] ?? false, [true, 'Y'], true);

		$this->arResult['GRID_ID'] = MeasureGrid::ID;
		$this->arResult['IS_SIDE_PANEL'] = $isSidePanel;
		$this->arResult['PATH_TO_MEASURE_LIST'] = $this->arParams['PATH_TO_MEASURE_LIST'];
		$this->arResult['PATH_TO_MEASURE_EDIT'] = $this->arParams['PATH_TO_MEASURE_EDIT'];
	}

	private function prepareGridParameters(): array
	{
		$grid = new MeasureGrid(
			new Settings([
				'ID' => MeasureGrid::ID,
			]),
			$this->arParams['PATH_TO_MEASURE_EDIT'],
		);
		$readService = $this->getMeasureReadService();

		$grid->initPagination($readService->getCount());
		$grid->processRequest();
		$grid->setRawRows(
			$readService->getList(
				$grid->getOrmOrder(),
				$grid->getPagination(),
			),
		);

		return ComponentParams::get($grid, [
			'AJAX_MODE' => 'Y',
			'USE_CHECKBOX_LIST_FOR_SETTINGS_POPUP' => \Bitrix\Main\ModuleManager::isModuleInstalled('ui'),
		]);
	}
}
