<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\Main\Web\Json;
use Bitrix\Mobile\Tab\Preset\Model\ManagedPresetTable;

final class ManagedPresetReader
{
	public function getActiveById(int $presetId): ?array
	{
		if ($presetId <= 0)
		{
			return null;
		}

		$row = ManagedPresetTable::query()
			->setSelect(['*'])
			->where('ID', $presetId)
			->where('IS_DELETED', 'N')
			->setLimit(1)
			->fetch()
		;

		return is_array($row) ? $row : null;
	}

	public function getCacheVersionDataById(int $presetId, int $cacheTtl): ?array
	{
		if ($presetId <= 0)
		{
			return null;
		}

		$row = ManagedPresetTable::query()
			->setSelect(['UPDATED_AT', 'CONFIG_JSON', 'IS_DELETED'])
			->where('ID', $presetId)
			->setLimit(1)
			->setCacheTtl($cacheTtl)
			->fetch()
		;

		return is_array($row) ? $row : null;
	}

	public function decodeConfig(array $row): ?array
	{
		try
		{
			$config = Json::decode((string)($row['CONFIG_JSON'] ?? ''));
		}
		catch (\Throwable)
		{
			return null;
		}

		return is_array($config) ? $config : null;
	}
}
