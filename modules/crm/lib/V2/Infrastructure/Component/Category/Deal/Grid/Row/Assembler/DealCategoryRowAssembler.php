<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Category\Deal\Grid\Row\Assembler;

use Bitrix\Crm\Category\DealCategory;
use Bitrix\Main\Grid\Row\Assembler\Field\StringFieldAssembler;
use Bitrix\Main\Grid\Row\RowAssembler;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Web\Json;

final class DealCategoryRowAssembler extends RowAssembler
{
	private const STAGE_EDITOR_PATH = '/bitrix/components/bitrix/crm.config.status/slider.php';

	public function prepareRows(array $rowsList): array
	{
		$rowsList = parent::prepareRows($rowsList);

		foreach ($rowsList as &$row)
		{
			$data = $row['data'];
			$id = (int)($data['ID'] ?? 0);
			$isDefault = $id === 0;

			$dto = [
				'id' => $id,
				'name' => (string)($data['NAME'] ?? ''),
				'sort' => max(0, (int)($data['SORT'] ?? 0)),
				'createdDate' => $this->formatCreatedDate($data['CREATED_DATE'] ?? null),
				'isDefault' => $isDefault,
				'stageEditorUrl' => $this->getStageEditorUrl($id),
			];

			$row['attrs'] ??= [];
			$row['attrs']['data-category'] = Json::encode($dto);
		}
		unset($row);

		return $rowsList;
	}

	protected function prepareFieldAssemblers(): array
	{
		return [
			new StringFieldAssembler(['NAME']),
		];
	}

	private function formatCreatedDate(mixed $createdDate): ?string
	{
		if ($createdDate instanceof Date)
		{
			return $createdDate->toString();
		}

		if ($createdDate === null || $createdDate === '')
		{
			return null;
		}

		return (string)$createdDate;
	}

	private function getStageEditorUrl(int $categoryId): string
	{
		return
			self::STAGE_EDITOR_PATH
			. '?ACTIVE_TAB=status_tab_'
			. DealCategory::getStatusEntityID($categoryId);
	}
}
