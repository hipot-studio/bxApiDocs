<?php

namespace Bitrix\Landing\Update\Block;

use Bitrix\Landing\Internals\RepoTable;
use Bitrix\Main\Config\Option;

class ClassAsset
{
	private const OPTION_CODE = 'last_block_id_whose_manifest_assets_class_checked';
	private const ROW_LIMIT = 30;

	public static function updateManifest(): ?string
	{
		$start = microtime(true);
		$maxExecTime = (int)ini_get('max_execution_time');
		$timeLimit = $maxExecTime > 0 ? (int)($maxExecTime * 0.9) : 50;

		$lastId = Option::get('landing', self::OPTION_CODE, 0);
		$res = RepoTable::getList([
			'select' => [
				'ID',
				'MANIFEST',
			],
			'filter' => [
				'>ID' => $lastId,
				'%MANIFEST' => 's:5:"class"',
			],
			'order' => [
				'ID' => 'ASC',
			],
			'limit' => self::ROW_LIMIT,
		]);

		$limitCount = 0;
		while ($row = $res->fetch())
		{
			$limitCount++;
			if (!empty($row['MANIFEST']))
			{
				if (microtime(true) - $start > $timeLimit)
				{
					return __CLASS__ . '::' . __FUNCTION__ . '();';
				}

				$manifestArray = unserialize($row['MANIFEST'], ['allowed_classes' => false]);
				if ($manifestArray === false)
				{
					Option::set('landing', self::OPTION_CODE, $row['ID']);
					continue;
				}

				if (is_array($manifestArray) && isset($manifestArray['assets']['class']))
				{
					unset($manifestArray['assets']['class']);
				}

				$manifestStringFixed = serialize($manifestArray);
				if ($manifestStringFixed !== $row['MANIFEST'])
				{
					RepoTable::update(
						$row['ID'],
						[
							'MANIFEST' => $manifestStringFixed
						]
					);
				}

				Option::set('landing', self::OPTION_CODE, $row['ID']);
			}
		}

		if ($limitCount >= self::ROW_LIMIT)
		{
			return __CLASS__ . '::' . __FUNCTION__ . '();';
		}

		Option::delete('landing', ['name' => self::OPTION_CODE]);

		return '';
	}
}
