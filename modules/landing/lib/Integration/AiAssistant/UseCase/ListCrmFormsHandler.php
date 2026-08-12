<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Integration\AiAssistant\Dto\ListCrmFormsDto;

class ListCrmFormsHandler extends AbstractCrmFormHandler
{
	public function handle(ListCrmFormsDto $dto): array
	{
		$forms = array_map(
			fn(array $form): array => $this->buildFormItem($form),
			array_values($this->filterForms($this->getFormsById(activeOnly: true), $dto->query)),
		);

		return [
			'forms' => $forms,
			'total' => count($forms),
			'hasMore' => false,
		];
	}

	private function filterForms(array $formsById, ?string $query): array
	{
		if ($query === null)
		{
			return $formsById;
		}

		$needle = mb_strtolower($query);

		return array_filter(
			$formsById,
			static function (array $form, int $formId) use ($needle): bool
			{
				$name = mb_strtolower((string)($form['NAME'] ?? ''));

				return str_contains((string)$formId, $needle) || str_contains($name, $needle);
			},
			ARRAY_FILTER_USE_BOTH,
		);
	}
}
