<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Scenario;

use Bitrix\Landing\Copilot\Generation;
use Bitrix\Landing\Copilot\Generation\Step;
use Bitrix\Landing\Metrika;

class ChangeBlock extends BaseScenario
{
	protected const EVENT_FINISH = 'onChangeBlockFinish';

	/**
	 * @inheritdoc
	 */
	protected function buildMap(): array
	{
		return [
			10 => new Step\RequestBlockContent(),
			15 => new Step\TaskPresaveBlocksHistory(),
			20 => new Step\TaskUpdateBlock(),
			30 => new Step\RequestImages(),
			40 => new Step\TaskSaveBlocksHistory(),
			1000 => new Step\Finish(),
		];
	}

	/**
	 * @inheritdoc
	 */
	/**
	 * Returns the number of the scenario step at which to check request limits.
	 *
	 * @return int
	 */
	public function getQuotaCalculateStep(): int
	{
		return 10;
	}

	public function getAnalyticCategory(): Metrika\Categories
	{
		return Metrika\Categories::BlockEdition;
	}

	public function getAsyncRelations(): ?array
	{
		return [
			30 => [
				40,
			],
		];
	}

	/**
	 * @inheritdoc
	 */
	public function onFinish(Generation $generation): void
	{
		$generation->getEvent()->send(
			self::EVENT_FINISH,
			[
				'blockData' => $generation->getBlocksData($generation->getSiteData()->getBlocks()),
			],
		);
	}
}
