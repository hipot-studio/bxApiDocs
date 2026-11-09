<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\UseCase;

use Bitrix\Landing\Internals\BlockTable;
use Bitrix\Landing\Markup\Manifest;
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

		$currentFormId = $this->getFormIdByBlock($blockId);
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

	/**
	 * An item per form binding of the markup block, in the manifest order.
	 * @param array<int, array> $formsById
	 * @return list<array>
	 */
	protected function buildMarkupBlockItems(int $pageId, int $blockId, Manifest $manifest, array $formsById): array
	{
		$items = [];
		foreach ($manifest->getForms() as $token => $binding)
		{
			$item = [
				'pageId' => $pageId,
				'blockId' => $blockId,
				'formToken' => (string)$token,
				'currentFormId' => $binding['formId'],
			];

			$currentForm = $formsById[$binding['formId']] ?? null;
			if (is_array($currentForm))
			{
				$item['currentFormName'] = (string)($currentForm['NAME'] ?? '');
				$item['currentFormActive'] = $this->isFormActive($currentForm);
			}

			$items[] = $item;
		}

		return $items;
	}

	protected function isFormActive(array $form): bool
	{
		return !array_key_exists('ACTIVE', $form) || $form['ACTIVE'] === 'Y';
	}

	protected function getFormIdByBlock(int $blockId): ?int
	{
		return Form::getFormByBlock($blockId);
	}

	/**
	 * Manifest of the markup block of the page, null for a block that is not a markup carrier of this page.
	 */
	protected function findMarkupBlockManifest(int $pageId, int $blockId): ?Manifest
	{
		return $this->findMarkupBlocks($pageId)[$blockId] ?? null;
	}

	/**
	 * @return array<int, Manifest> Manifests by block id.
	 */
	protected function findMarkupBlocks(int $pageId): array
	{
		return self::fetchMarkupBlockManifests($pageId);
	}

	/**
	 * Draft markup carriers of the page with a stored version: the blocks the CRM form tools target.
	 * @return array<int, Manifest> Manifests by block id.
	 */
	public static function fetchMarkupBlockManifests(int $pageId): array
	{
		$rows = BlockTable::query()
			->setSelect(['ID', 'MARKUP_MANIFEST'])
			->where('LID', $pageId)
			->where('PUBLIC', 'N')
			->where('DELETED', 'N')
			->whereNotNull('MARKUP_MANIFEST')
			->setOrder(['SORT' => 'ASC', 'ID' => 'ASC'])
			->exec()
		;

		$manifests = [];
		while ($row = $rows->fetch())
		{
			$manifest = Manifest::fromJson((string)$row['MARKUP_MANIFEST']);
			if ($manifest !== null)
			{
				$manifests[(int)$row['ID']] = $manifest;
			}
		}

		return $manifests;
	}
}
