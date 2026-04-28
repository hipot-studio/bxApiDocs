<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Internals\BlockTable;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;

class UpdateBlockLinks extends Blank
{
	public function action(): void
	{
		$ratio = $this->context->getRatio();
		$blocks = $ratio->get(RatioPart::Blocks) ?? [];
		$landings = $ratio->get(RatioPart::Landings) ?? [];
		$blocksPending = $ratio->get(RatioPart::BlocksPending) ?? [];

		$replace = [];
		ksort($blocks);
		ksort($landings);
		$blocks = array_reverse($blocks, true);
		$landings = array_reverse($landings, true);
		foreach ($blocks as $oldId => $newId)
		{
			$replace['/#block' . $oldId . '([^\d]{1})/'] = '#block' . $newId . '$1';
		}
		foreach ($landings as $oldId => $newId)
		{
			$replace['/#landing' . $oldId . '([^\d]{1})/'] = '#landing' . $newId . '$1';
		}

		$res = BlockTable::getList([
			'select' => [
				'ID', 'CONTENT',
			],
			'filter' => [
				'ID' => array_values($blocks),
				'!ID' => $blocksPending,
			],
		]);
		while ($row = $res->fetch())
		{
			$count = 0;
			$row['CONTENT'] = preg_replace(
				array_keys($replace),
				array_values($replace),
				$row['CONTENT'],
				-1,
				$count
			);
			if ($count)
			{
				BlockTable::update($row['ID'], [
					'CONTENT' => $row['CONTENT'],
				]);
			}
		}
	}
}
