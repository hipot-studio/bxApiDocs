<?php

namespace Bitrix\Intranet\Internal\Integration\Disk\AnnualSummary;

use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\TypeFile;
use Bitrix\Intranet\Internal\Entity\AnnualSummary\BoardFeature;
use Bitrix\Intranet\Internal\Repository\AnnualSummaryRepository;
use Bitrix\Intranet\Internal\Service\AnnualSummary\AbstractFeatureProvider;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Type\DateTime;

class BoardProvider extends AbstractFeatureProvider
{
	/**
	 * @throws LoaderException
	 */
	public function calcValue(int $userId, DateTime $from, DateTime $to): int
	{
		return (int)ObjectTable::query()
			->where('TYPE_FILE', TypeFile::FLIPCHART)
			->where('CREATED_BY', $userId)
			->where('CREATE_TIME', '>=', $from)
			->where('CREATE_TIME', '<', $to)
			->queryCountTotal()
		;
	}

	public function createFeature(int $value): BoardFeature
	{
		return new BoardFeature($value);
	}

	public function precalcValue(int $userId): ?int
	{
		return (new AnnualSummaryRepository($userId))->getSerializedOption('annual_summary_25_board_count');
	}

	public function isAvailable(): bool
	{
		return Loader::includeModule('disk');
	}
}
