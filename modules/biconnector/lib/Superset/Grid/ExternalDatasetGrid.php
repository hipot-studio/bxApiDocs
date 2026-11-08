<?php

namespace Bitrix\BIConnector\Superset\Grid;

use Bitrix\BIConnector\ExternalSource\Type;
use Bitrix\BIConnector\Superset\Grid\Row\Assembler\ExternalDatasetRowAssembler;
use Bitrix\BIConnector\Superset\Grid\Settings\ExternalDatasetSettings;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Filter\Settings;
use Bitrix\Main\Grid\Column\Columns;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Row\Rows;
use Bitrix\Main\Localization\Loc;

/**
 * @method ExternalDatasetSettings getSettings()
 */
final class ExternalDatasetGrid extends Grid
{
	private const LEVEL_STORAGE_ID = 'biconnector-table-section-level';

	public function __construct(\Bitrix\Main\Grid\Settings $settings)
	{
		parent::__construct($settings);
		$this->getSettings()->setOrmFilter($this->getOrmFilter());
	}

	protected function createColumns(): Columns
	{
		return new Columns(
			new \Bitrix\BIConnector\Superset\Grid\Column\Provider\ExternalDatasetDataProvider()
		);
	}

	protected function createRows(): Rows
	{
		$rowAssembler = new ExternalDatasetRowAssembler(
			$this->getVisibleColumnsIds(),
			$this->getSettings()
		);

		return new Rows(
			$rowAssembler,
			new \Bitrix\BIConnector\Superset\Grid\Row\Action\Dataset\ExternalDatasetActionDataProvider($this->getSettings())
		);
	}

	protected function createFilter(): ?Filter
	{
		return new Filter(
			$this->getId(),
			new \Bitrix\BiConnector\Superset\Filter\Provider\ExternalDatasetDataProvider(
				new Settings(['ID' => $this->getId()])
			),
		);
	}

	public static function getFilterPresets(): array
	{
		$currentUser = CurrentUser::get();

		return [
			'mine' => [
				'name' => Loc::getMessage('BICONNECTOR_SUPERSET_EXTERNAL_DATASET_GRID_FILTER_PRESET_MINE') ?? '',
				'sort' => 100,
				'fields' => [
					'CREATED_BY_ID' => [$currentUser->getId()],
					'CREATED_BY_ID_label' => [$currentUser->getFormattedName()],
				],
			],
			'system' => [
				'name' => Loc::getMessage('BICONNECTOR_SUPERSET_EXTERNAL_DATASET_GRID_FILTER_PRESET_SYSTEM') ?? '',
				'sort' => 200,
				'fields' => [
					'TYPE' => Type::System->value,
				],
			],
			'external' => [
				'name' => Loc::getMessage('BICONNECTOR_SUPERSET_EXTERNAL_DATASET_GRID_FILTER_PRESET_EXTERNAL') ?? '',
				'sort' => 300,
				'fields' => [
					'TYPE' => \Bitrix\BIConnector\Superset\Filter\Provider\ExternalDatasetDataProvider::TYPE_EXTERNAL,
				],
			],
		];
	}

	public function getOrmParams(): array
	{
		$ormParams = parent::getOrmParams();

		$requiredFields = ['ID', 'TYPE', 'NAME'];
		foreach ($requiredFields as $field)
		{
			if (!in_array($field, $ormParams['select'], true))
			{
				$ormParams['select'][] = $field;
			}
		}

		return $ormParams;
	}

	/**
	 * Opens the first page of the list when the level of the section is not the one the page was left on.
	 *
	 * Every level of the section is the same grid narrowed by the filter, and the grid keeps the page
	 * it was left on in the session under its id: a group entered from the third page of the top level
	 * would ask for its own third page and show nothing at all. The level is compared with the one of
	 * the previous drawing rather than with the request, so every way of changing it - a click on the
	 * row of a group, the filter, the square of the condition, a stale condition taken off - is caught.
	 *
	 * Call it once the pagination is initialised.
	 *
	 * @param int[] $groupIds Groups the level stands on; an empty list is the top level.
	 */
	public function openFirstPageOnLevelChange(array $groupIds): void
	{
		$groupIds = array_values(array_unique(array_map('intval', $groupIds)));
		sort($groupIds);
		$level = implode(',', $groupIds);

		$storage = Application::getInstance()->getSessionLocalStorageManager()->get(self::LEVEL_STORAGE_ID);
		$key = $this->getId();
		// A session that has not drawn the section yet counts as another level: the page it keeps may
		// have been left on a level nobody remembers.
		$previousLevel = isset($storage[$key]) ? (string)$storage[$key] : null;
		if ($previousLevel === $level)
		{
			return;
		}

		$storage->set($key, $level);

		$pagination = $this->getPagination();
		if ($pagination === null || $pagination->getCurrentPage() === 1)
		{
			return;
		}

		$pagination->setCurrentPage(1);
		$this->getPaginationStorage()?->save($pagination);
	}
}
