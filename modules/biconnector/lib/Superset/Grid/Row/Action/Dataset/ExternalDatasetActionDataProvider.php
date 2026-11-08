<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\Dataset;

use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Table\TableAccessChecker;
use Bitrix\BIConnector\ExternalSource\Type;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Grid\Settings\ExternalDatasetSettings;
use Bitrix\BIConnector\Superset\Grid\TableGroupRepository;
use Bitrix\Main\Grid\Row\Action\DataProvider;

/**
 * @method ExternalDatasetSettings getSettings()
 */
class ExternalDatasetActionDataProvider extends DataProvider
{
	private ?TableAccessChecker $tableAccessChecker = null;

	public function prepareActions(): array
	{
		$actions = [
			new OpenDatasetAction(),
			new ExportCsvDatasetAction(),
			new EditTableGroupAction(),
			new DeleteTableGroupAction(),
		];

		if (SupersetInitializer::isSupersetReady())
		{
			$actions[] = new CreateExternalDatasetAction();
			$actions[] = new DeleteDatasetAction();
		}

		return $actions;
	}

	public function prepareControls(array $rawFields): array
	{
		$result = [];

		$isGroupRow = ($rawFields['ENTITY_TYPE'] ?? '') === TableGroupRepository::TYPE_GROUP;
		$isSystem = !empty($rawFields['IS_SYSTEM']);
		$tableCode = (string)($rawFields['NAME'] ?? '');
		$canEdit = $isSystem || $this->canDo(ActionDictionary::ACTION_BIC_TABLE_EDIT, $tableCode);

		foreach ($this->prepareActions() as $actionsItem)
		{
			$isGroupAction =
				$actionsItem instanceof EditTableGroupAction
				|| $actionsItem instanceof DeleteTableGroupAction
			;

			// A group is no table: nothing that opens, exports or deletes a table belongs in its menu.
			if ($isGroupRow !== $isGroupAction)
			{
				continue;
			}

			if (
				($rawFields['TYPE'] ?? '') !== Type::Csv->value
				&& $actionsItem instanceof ExportCsvDatasetAction
			)
			{
				continue;
			}

			if (
				$isSystem
				&& $actionsItem instanceof DeleteDatasetAction
			)
			{
				continue;
			}

			if (
				!$canEdit
				&& ($actionsItem instanceof DeleteDatasetAction || $actionsItem instanceof CreateExternalDatasetAction)
			)
			{
				continue;
			}

			if (
				!$isSystem
				&& $actionsItem instanceof ExportCsvDatasetAction
				&& !$this->canDo(ActionDictionary::ACTION_BIC_TABLE_EXPORT, $tableCode)
			)
			{
				continue;
			}

			$actionConfig = $actionsItem->getControl($rawFields);

			if (isset($actionConfig))
			{
				$result[] = $actionConfig;
			}
		}

		return $result;
	}

	private function canDo(string $action, string $tableCode): bool
	{
		$this->tableAccessChecker ??= new TableAccessChecker();

		return $this->tableAccessChecker->canDo($action, $tableCode);
	}
}
