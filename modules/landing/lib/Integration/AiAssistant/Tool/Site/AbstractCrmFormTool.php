<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Tool\Site;

use Bitrix\Crm\WebForm\Manager as WebFormManager;
use Bitrix\Landing\Integration\AiAssistant\Tool\BaseTool;
use Bitrix\Landing\Integration\AiAssistant\UseCase\AbstractCrmFormFieldsHandler;
use Bitrix\Landing\Integration\AiAssistant\UseCase\AbstractCrmFormHandler;
use Bitrix\Landing\Rights;
use Bitrix\Landing\Subtype\Form;
use Bitrix\Main\Loader;
use InvalidArgumentException;

abstract class AbstractCrmFormTool extends BaseTool
{
	use AiSiteRuntimeAvailabilityToolGate;

	protected function assertCrmFormReadPermission(): void
	{
		$this->assertPermission(
			Loader::includeModule('crm') && WebFormManager::checkReadPermission(),
			'Access denied. You do not have permission to read CRM forms.',
		);
	}

	protected function assertCrmFormWritePermission(): void
	{
		$this->assertPermission(
			Loader::includeModule('crm')
			&& WebFormManager::checkWritePermission()
			&& $this->isCrmWebFormEditFeatureEnabled(),
			'Access denied. You do not have permission to edit CRM forms.',
		);
	}

	protected function assertPageEditPermission(int $pageId): void
	{
		$this->assertPermission(
			Rights::hasAccessForLanding($pageId, Rights::ACCESS_TYPES['edit']),
			'Access denied. You do not have permission to edit this page.',
		);
	}

	protected function assertBlockBelongsToPageAndHasCrmForm(int $pageId, int $blockId): void
	{
		$this->resolveCrmFormIdFromBlockTarget($pageId, $blockId);
	}

	protected function resolveCrmFormIdFromBlockTarget(int $pageId, int $blockId): int
	{
		foreach (Form::getLandingFormBlocks($pageId) as $block)
		{
			if ((int)($block['ID'] ?? 0) !== $blockId)
			{
				continue;
			}

			$formId = Form::getFormByBlock($blockId);
			if ($formId === null || (int)$formId <= 0)
			{
				throw new InvalidArgumentException('The selected block does not contain a CRM form binding.');
			}

			return (int)$formId;
		}

		throw new InvalidArgumentException('CRM form block not found on the specified page.');
	}

	/**
	 * Form ids bound to the markup block of the page, null for a block that is not a markup carrier of the page.
	 * @return list<int>|null
	 */
	protected function findMarkupBlockFormIds(int $pageId, int $blockId): ?array
	{
		$manifest = AbstractCrmFormHandler::fetchMarkupBlockManifests($pageId)[$blockId] ?? null;
		if ($manifest === null)
		{
			return null;
		}

		return array_values(array_unique(array_column($manifest->getForms(), 'formId')));
	}

	/**
	 * The form of a markup block: the given one must be bound to the block, a single binding is taken by itself.
	 * @param list<int> $boundFormIds
	 */
	protected function resolveMarkupBlockFormId(array $boundFormIds, ?int $formId): int
	{
		return AbstractCrmFormFieldsHandler::resolveMarkupFormId($boundFormIds, $formId);
	}

	protected function assertReadableActiveFormExists(int $formId): void
	{
		foreach (Form::getForms(true) as $form)
		{
			if ((int)($form['ID'] ?? 0) !== $formId)
			{
				continue;
			}

			if (array_key_exists('ACTIVE', $form) && $form['ACTIVE'] !== 'Y')
			{
				throw new InvalidArgumentException('Selected CRM form is inactive.');
			}

			return;
		}

		throw new InvalidArgumentException('Selected CRM form not found or access denied.');
	}

	private function isCrmWebFormEditFeatureEnabled(): bool
	{
		if (Loader::includeModule('bitrix24'))
		{
			return \Bitrix\Bitrix24\Feature::isFeatureEnabled('crm_webform_edit');
		}

		return true;
	}
}
