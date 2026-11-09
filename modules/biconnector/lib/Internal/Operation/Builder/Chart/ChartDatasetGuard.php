<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Chart;

use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\User;
use Bitrix\BIConnector\Internal\Operation\Builder\EntityLookup;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\Result;

final class ChartDatasetGuard
{
	public function validate(array $config, ?User $user, bool $allowMissingDataset = false): Result
	{
		$lookup = (new EntityLookup())->dataset((int)($config['datasetId'] ?? 0), $user);
		if (!$lookup->isSuccess())
		{
			if ($allowMissingDataset && $lookup->getErrors()[0]->getCode() === FailureCode::NotFound->value)
			{
				return new Result();
			}

			return $lookup;
		}

		$temporalColumn = trim((string)($config['temporalColumn'] ?? ''));
		foreach ($lookup->getData()['entity']['columns'] ?? [] as $column)
		{
			if (
				is_array($column)
				&& ($column['column_name'] ?? null) === $temporalColumn
				&& self::isTemporal($column)
			)
			{
				return new Result();
			}
		}

		return Refusal::error(
			FailureCode::InvalidArgument,
			'temporalColumn must reference a date/datetime column of the dataset.',
		);
	}

	/**
	 * The column type decides, not the `is_dttm` mark a user may set on any column. Columns written through
	 * the proxy are typed DATE/DATETIME, while an SQL dataset carries the type of its database,
	 * such as `TIMESTAMP(3)` in Trino.
	 */
	private static function isTemporal(array $column): bool
	{
		$type = $column['type'] ?? null;

		return is_string($type) && preg_match('/^\s*(DATE|DATETIME|TIMESTAMP)(?![A-Z0-9_])/i', $type) === 1;
	}
}
