<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Measure\Grid;

use Bitrix\Crm\V2\Internal\Access\Measure\MeasureAccess;
use Bitrix\Crm\V2\Internal\Model\Measure\Measure;
use Bitrix\Crm\V2\Internal\Service\Measure\MeasureMutationService;
use Bitrix\Main;
use InvalidArgumentException;

/**
 * @internal
 */
final class MeasureGrid extends Main\Grid\Grid
{
	public const ID = 'CRM_MEASURE_LIST';

	public function __construct(
		Main\Grid\Settings $settings,
		private readonly string $editUrlTemplate,
		private readonly MeasureAccess $access = new MeasureAccess(),
		private readonly MeasureMutationService $mutationService = new MeasureMutationService(),
	)
	{
		parent::__construct($settings);
	}

	/**
	 * @param iterable<Measure> $rawValue
	 */
	public function setRawRows(iterable $rawValue): void
	{
		$rows = [];
		foreach ($rawValue as $measure)
		{
			if (!$measure instanceof Measure)
			{
				throw new InvalidArgumentException('MeasureGrid accepts only Measure models.');
			}

			$rows[] = $this->mapMeasureToRow($measure);
		}

		parent::setRawRows($rows);
	}

	protected function createColumns(): Main\Grid\Column\Columns
	{
		return new Main\Grid\Column\Columns(
			new Column\MeasureDataProvider(),
		);
	}

	protected function createRows(): Main\Grid\Row\Rows
	{
		return new Main\Grid\Row\Rows(
			new Row\MeasureRowAssembler($this->getVisibleColumnsIds()),
			new Row\Action\MeasureDataProvider(
				$this->getSettings(),
				$this->access,
				$this->mutationService,
			),
		);
	}

	protected function createPanel(): Main\Grid\Panel\Panel
	{
		return new Main\Grid\Panel\Panel(
			new Panel\Action\MeasureDataProvider(
				$this->getSettings(),
				$this->access,
				$this->mutationService,
			),
		);
	}

	/**
	 * @return array<string, bool|int|string|null>
	 */
	private function mapMeasureToRow(Measure $measure): array
	{
		return [
			'ID' => $measure->id,
			'CODE' => $measure->code,
			'MEASURE_TITLE' => $measure->title,
			'SYMBOL_RUS' => $measure->symbolRus,
			'SYMBOL_INTL' => $measure->symbolIntl,
			'SYMBOL_LETTER_INTL' => $measure->symbolLetterIntl,
			'IS_DEFAULT' => $measure->isDefault,
			'PATH_TO_MEASURE_EDIT' => \CComponentEngine::MakePathFromTemplate(
				$this->editUrlTemplate,
				['measure_id' => $measure->id],
			),
		];
	}
}
