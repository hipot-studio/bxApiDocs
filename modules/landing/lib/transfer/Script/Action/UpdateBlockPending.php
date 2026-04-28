<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Block;
use Bitrix\Landing\Internals\BlockTable;
use Bitrix\Landing\Transfer\AppConfiguration;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;

class UpdateBlockPending extends Blank
{
	public function action(): void
	{
		$ratio = $this->context->getRatio();

		$pendingIds = $ratio->get(RatioPart::BlocksPending) ?? [];
		if (empty($pendingIds))
		{
			return;
		}
		$replace = [
			'block' => $ratio->get(RatioPart::Blocks) ?? [],
			'landing' => $ratio->get(RatioPart::Landings) ?? [],
		];

		$replaceEncoded = base64_encode(serialize($replace));
		$res = BlockTable::getList([
			'select' => [
				'ID',
			],
			'filter' => [
				'ID' => $pendingIds,
			],
		]);
		while ($row = $res->fetch())
		{
			$blockInstance = new Block($row['ID']);
			if ($blockInstance->exist())
			{
				$blockInstance->updateNodes([
					AppConfiguration::SYSTEM_COMPONENT_REST_PENDING => [
						'REPLACE' => $replaceEncoded,
					],
				]);
				$blockInstance->save();
			}
		}
	}
}
