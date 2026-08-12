<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Scenario;

interface IStepMetaProvider
{
	/**
	 * @return array<int, array{code: string, label: string}>
	 */
	public function getStepMeta(): array;
}
