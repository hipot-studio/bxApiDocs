<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Integration\AiAssistant\Dto\GetLandingCrmFormBlocksDto;
use Bitrix\Landing\Subtype\Form;

class GetLandingCrmFormBlocksHandler extends AbstractCrmFormHandler
{
	public function handle(GetLandingCrmFormBlocksDto $dto): array
	{
		$formsById = $this->getFormsById(activeOnly: false);
		$blocks = [];

		foreach (Form::getLandingFormBlocks($dto->pageId) as $block)
		{
			$item = $this->buildBlockItem($dto->pageId, $block, $formsById);
			if ($item !== null)
			{
				$blocks[] = $item + ['position' => count($blocks) + 1];
			}
		}

		return [
			'pageId' => $dto->pageId,
			'blocks' => $blocks,
			'total' => count($blocks),
		];
	}
}
