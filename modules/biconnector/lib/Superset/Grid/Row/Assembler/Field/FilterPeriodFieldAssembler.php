<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field;

use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;
use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\Date;

class FilterPeriodFieldAssembler extends FieldAssembler
{
    protected function prepareColumn($value): string
    {
        if (EmbeddedFilter\DateTime::PERIOD_RANGE !== $value['FILTER_PERIOD']) {
            return EmbeddedFilter\DateTime::getPeriodName($value['FILTER_PERIOD']) ?? '';
        }

        $dateStart = $value['DATE_FILTER_START'];
        if ($dateStart instanceof Date) {
            $dateStart->toString();
        }

        $dateEnd = $value['DATE_FILTER_END'];
        if ($dateEnd instanceof Date) {
            $dateEnd->toString();
        }

        $preparedValue = "{$dateStart} - {$dateEnd}";

        if ($value['INCLUDE_LAST_FILTER_DATE']) {
            $preparedValue .= " {$this->getHint()}";
        }

        return $preparedValue;
    }

    protected function prepareRow(array $row): array
    {
        if (empty($this->getColumnIds())) {
            return $row;
        }

        $row['columns'] ??= [];

        foreach ($this->getColumnIds() as $columnId) {
            if ($row['data'][$columnId]) {
                $value = [
                    'DATE_FILTER_START' => $row['data']['DATE_FILTER_START'],
                    'DATE_FILTER_END' => $row['data']['DATE_FILTER_END'],
                    'FILTER_PERIOD' => $row['data']['FILTER_PERIOD'],
                    'INCLUDE_LAST_FILTER_DATE' => $row['data']['INCLUDE_LAST_FILTER_DATE'],
                ];
            } else {
                $value = [];
            }
            $row['columns'][$columnId] = $this->prepareColumn($value);
        }

        return $row;
    }

    private function getHint(): string
    {
        $hint = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_GRID_PERIOD_INCLUDE_LAST_FILTER_DATE_HINT');

        return "<span data-hint=\"{$hint}\" data-hint-interactivity><span class=\"ui-hint-icon\"></span></span>";
    }
}
