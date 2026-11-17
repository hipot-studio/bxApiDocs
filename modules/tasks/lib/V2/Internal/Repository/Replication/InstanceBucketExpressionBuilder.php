<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Replication;

use Bitrix\Main\DB\SqlHelper;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Service\Replication\Schedule\PeriodBucketer;

final class InstanceBucketExpressionBuilder
{
	public const FIELD_NAME = 'BUCKET_KEY';

	public function __construct(
		private readonly PeriodBucketer $bucketer = new PeriodBucketer(),
	)
	{
	}

	/**
	 * @param string[] $keys
	 */
	public function build(
		SqlHelper $sqlHelper,
		string $scale,
		array $keys,
		int $tzOffset,
	): ExpressionField
	{
		$clauses = [];
		$buildFrom = [];

		foreach ($keys as $key)
		{
			$range = $this->bucketer->getBucketRange($scale, $key, $tzOffset);
			if ($range === null)
			{
				continue;
			}

			$from = $this->dateLiteral($sqlHelper, $range['from']);
			$to = $this->dateLiteral($sqlHelper, $range['to']);
			$clauses[] = "WHEN %s >= {$from} AND %s < {$to} THEN '{$key}'";
			$buildFrom[] = 'CREATED_DATE';
			$buildFrom[] = 'CREATED_DATE';
		}

		$expression = $clauses === []
			? 'NULL'
			: 'CASE ' . implode(' ', $clauses) . ' END';

		return new ExpressionField(self::FIELD_NAME, $expression, $buildFrom);
	}

	private function dateLiteral(SqlHelper $sqlHelper, int $timestamp): string
	{
		$value = DateTime::createFromTimestamp($timestamp)->format('Y-m-d H:i:s');

		return $sqlHelper->getCharToDateFunction($value);
	}
}
