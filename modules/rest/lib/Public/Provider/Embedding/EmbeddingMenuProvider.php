<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Embedding;

use Bitrix\Rest\EO_Placement;
use Bitrix\Rest\Internal\Service\Embedding\EmbeddingOwnerResolver;
use Bitrix\Rest\Lang;
use Bitrix\Rest\Marketplace\Url;
use Bitrix\Rest\PlacementTable;
use Bitrix\Rest\Public\Provider\Dto\Embedding\EmbeddingMenuItemDto;

final class EmbeddingMenuProvider
{
	/**
	 * Returns application placement items suitable for binding menus.
	 *
	 * @param string[] $placements
	 * @return EmbeddingMenuItemDto[]
	 */
	public function getBindingItems(array $placements): array
	{
		return $this->getItems(
			placements: $placements,
			filter: ['>APP_ID' => 0],
			order: ['ID' => 'desc'],
		);
	}

	/**
	 * Returns application and valid module-owned placement items suitable for the left menu.
	 *
	 * @param string $placement
	 * @return EmbeddingMenuItemDto[]
	 */
	public function getLeftMenuItems(string $placement): array
	{
		return $this->getItems(
			placements: [$placement],
			onlyMenuOwners: true,
			forLeftMenu: true,
		);
	}

	/**
	 * @param string[] $placements
	 * @param array<string, mixed> $filter
	 * @param array<string, string>|null $order
	 * @return EmbeddingMenuItemDto[]
	 */
	private function getItems(
		array $placements,
		array $filter = [],
		?array $order = null,
		bool $onlyMenuOwners = false,
		bool $forLeftMenu = false,
	): array
	{
		if (empty($placements))
		{
			return [];
		}

		$query = [
			'select' => [
				'ID',
				'APP_ID',
				'PLACEMENT',
				'TITLE',
				'GROUP_NAME',
				'MODULE_ID',
				'ENTITY_TYPE',
				'ENTITY_ID',
				'LANG_ALL',
				'APP_NAME' => 'REST_APP.APP_NAME',
			],
			'filter' => [
				'=PLACEMENT' => $placements,
				...$filter,
			],
		];
		if ($order !== null)
		{
			$query['order'] = $order;
		}

		$result = [];
		$placementsResult = PlacementTable::getList($query);
		foreach ($placementsResult->fetchCollection() as $placementRow)
		{
			if ($onlyMenuOwners && !$this->isMenuOwner($placementRow))
			{
				continue;
			}

			if ($forLeftMenu)
			{
				$bindingTitle = '';
				$bindingGroupName = '';
				$leftMenuTitle = $this->getLeftMenuTitle($placementRow);
			}
			else
			{
				[$bindingTitle, $bindingGroupName] = $this->getBindingTexts($placementRow);
				$leftMenuTitle = '';
			}

			$result[] = new EmbeddingMenuItemDto(
				id: $placementRow->getId(),
				appId: $placementRow->getAppId() > 0 ? $placementRow->getAppId() : null,
				placement: $placementRow->getPlacement(),
				bindingTitle: $bindingTitle,
				bindingGroupName: $bindingGroupName,
				leftMenuTitle: $leftMenuTitle,
				applicationTitle: $placementRow->getRestApp()?->getAppName() ?? '',
				defaultTitle: (string)PlacementTable::getDefaultTitle($placementRow->getId()),
				url: Url::getApplicationPlacementUrl($placementRow->getId()),
			);
		}

		return $result;
	}

	private function isMenuOwner(EO_Placement $placementRow): bool
	{
		if ($placementRow->getAppId() > 0)
		{
			return true;
		}

		return EmbeddingOwnerResolver::isNonAppOwnerPlacement($placementRow);
	}

	private function getBindingTexts(EO_Placement $placementRow): array
	{
		$localizedTexts = [];
		foreach ($this->getLanguages($placementRow) as $language)
		{
			$title = trim($language->getTitle());
			if ($title !== '')
			{
				$localizedTexts[$language->getLanguageId()] = [
					'title' => $title,
					'groupName' => (string)$language->getGroupName(),
				];
			}
		}

		if (empty($localizedTexts))
		{
			return ['', ''];
		}

		foreach (Lang::listLanguage() as $languageId)
		{
			if (isset($localizedTexts[$languageId]))
			{
				return [$localizedTexts[$languageId]['title'], $localizedTexts[$languageId]['groupName']];
			}
		}

		$localizedText = reset($localizedTexts);

		return [$localizedText['title'], $localizedText['groupName']];
	}

	private function getLeftMenuTitle(EO_Placement $placementRow): string
	{
		$languageRows = [];
		foreach ($this->getLanguages($placementRow) as $language)
		{
			$title = trim((string)$language->getTitle());
			if ($title !== '')
			{
				$languageRows[$language->getLanguageId()] = [
					'TITLE' => $title,
				];
			}
		}

		return (string)(Lang::mergeFromLangAll([
			'TITLE' => $placementRow->getTitle(),
			'LANG_ALL' => $languageRows,
		])['TITLE'] ?? '');
	}

	private function getLanguages(EO_Placement $placementRow): iterable
	{
		if ($placementRow->getLangAll() === null)
		{
			$placementRow->fillLangAll();
		}

		return $placementRow->getLangAll() ?? [];
	}
}
