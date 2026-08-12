<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Integration\AiAssistant\Dto\GetCrmFormFieldsDto;

class GetCrmFormFieldsHandler extends AbstractCrmFormFieldsHandler
{
	public function handle(GetCrmFormFieldsDto $dto): array
	{
		$formId = $this->resolveFormId($dto);
		$source = $dto->pageId !== null && $dto->blockId !== null
			? [
				'pageId' => $dto->pageId,
				'blockId' => $dto->blockId,
			]
			: null
		;

		return $this->buildFieldsResponse($formId, $this->loadOptions($formId), $source);
	}
}
