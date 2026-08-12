<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Integration\AiAssistant\Dto\ListCrmFormAvailableFieldsDto;

class ListCrmFormAvailableFieldsHandler extends AbstractCrmFormFieldsHandler
{
	public function handle(ListCrmFormAvailableFieldsDto $dto): array
	{
		$options = $this->loadOptions((int)$dto->formId);
		$categories = $this->serializeAvailableFieldCategories(
			$this->getAddableAvailableFieldsTree($this->getAvailableFieldsPresetId($options)),
			$dto->query,
		);

		return [
			'formId' => $dto->formId,
			'categories' => $categories,
			'layoutTypes' => $this->getLayoutTypes(),
			'supportsProductField' => false,
		];
	}
}
