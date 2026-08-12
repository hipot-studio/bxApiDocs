<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Subtype\Form;

abstract class AbstractCrmFormHandler extends AbstractSiteHandler
{
	protected function getFormsById(bool $activeOnly): array
	{
		$formsById = [];

		foreach (Form::getForms(true) as $form)
		{
			$formId = (int)($form['ID'] ?? 0);
			if ($formId <= 0)
			{
				continue;
			}

			if ($activeOnly && !$this->isFormActive($form))
			{
				continue;
			}

			$formsById[$formId] = $form;
		}

		return $formsById;
	}

	protected function buildFormItem(array $form): array
	{
		$item = [
			'formId' => (int)($form['ID'] ?? 0),
			'name' => (string)($form['NAME'] ?? ''),
			'active' => $this->isFormActive($form),
			'isCallback' => ($form['IS_CALLBACK_FORM'] ?? 'N') === 'Y',
		];

		return $item;
	}

	protected function buildBlockItem(int $pageId, array $block, array $formsById): ?array
	{
		$blockId = (int)($block['ID'] ?? 0);
		if ($blockId <= 0)
		{
			return null;
		}

		$currentFormId = Form::getFormByBlock($blockId);
		if ($currentFormId === null)
		{
			return null;
		}

		$currentForm = $formsById[$currentFormId] ?? null;
		$item = [
			'pageId' => $pageId,
			'blockId' => $blockId,
			'currentFormId' => $currentFormId,
		];

		if (is_array($currentForm))
		{
			$item['currentFormName'] = (string)($currentForm['NAME'] ?? '');
			$item['currentFormActive'] = $this->isFormActive($currentForm);
		}

		return $item;
	}

	protected function isFormActive(array $form): bool
	{
		return !array_key_exists('ACTIVE', $form) || $form['ACTIVE'] === 'Y';
	}
}
