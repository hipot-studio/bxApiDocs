<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Placement;

use Bitrix\Rest\AppTable;
use Bitrix\Rest\EO_Placement;
use Bitrix\Rest\EO_Placement_Collection;
use Bitrix\Rest\Internal\Service\Embedding\EmbeddingOwnerResolver;

final class HandlerCacheRowMapper
{
	public function __construct(
		private readonly LegacyAppIdPresenter $legacyAppIdPresenter = new LegacyAppIdPresenter(),
	)
	{
	}

	public function mapLegacyHandler(EO_Placement $handler): array
	{
		return $this->mapHandler($handler);
	}

	public function mapLegacyAccessCandidate(array $handler): array
	{
		return [
			'ID' => (int)$handler['ID'],
			'APP_ID' => $handler['APP_ID'],
			'INSTALLED' => $handler['INSTALLED'],
			'APP_ACCESS' => $handler['APP_ACCESS'],
		];
	}

	public function mapOwnerAwareCollection(EO_Placement_Collection $collection): array
	{
		$result = [];
		$collection->fillLangAll();

		foreach ($collection as $handler)
		{
			$isEmbedding = $this->isEmbedding($handler);
			$applicationActive = $handler->getRestApp()?->getActive();
			$row = $this->mapHandler($handler, $isEmbedding);
			$row['ACTIVE'] = ($isEmbedding || $this->isFlagOn($applicationActive))
				? AppTable::ACTIVE
				: AppTable::INACTIVE;
			$result[] = $row;
		}

		return $result;
	}

	public function mapOwnerAwareAccessCandidate(array $handler): array
	{
		$isEmbedding = EmbeddingOwnerResolver::isNonAppOwner($handler);
		$applicationActive = $handler['ACTIVE'] ?? null;

		return [
			'ID' => (int)$handler['ID'],
			'APP_ID' => $isEmbedding
				? $this->legacyAppIdPresenter->encodeEmbeddingId((int)$handler['ID'])
				: $handler['APP_ID'],
			'ACTIVE' => ($isEmbedding || $this->isFlagOn($applicationActive))
				? AppTable::ACTIVE
				: AppTable::INACTIVE,
			'INSTALLED' => $isEmbedding ? AppTable::INSTALLED : $handler['INSTALLED'],
			'APP_ACCESS' => $isEmbedding ? '' : $handler['APP_ACCESS'],
		];
	}

	private function mapHandler(EO_Placement $handler, bool $isEmbedding = false): array
	{
		$id = $handler->getId();
		$app = $handler->getRestApp();
		$row = [
			'ID' => $id,
			'APP_ID' => $isEmbedding
				? $this->legacyAppIdPresenter->encodeEmbeddingId($id)
				: $handler->getAppId(),
			'USER_ID' => $handler->getUserId() ?? 0,
			'ICON_ID' => $handler->getIconId(),
			'ADDITIONAL' => $handler->getAdditional(),
			'HANDLER' => $handler->getPlacementHandler(),
			'TITLE' => $isEmbedding ? (string)$handler->getTitle() : '',
			'COMMENT' => '',
			'DESCRIPTION' => $isEmbedding ? (string)$handler->getComment() : '',
			'GROUP_NAME' => $isEmbedding ? (string)$handler->getGroupName() : '',
			'OPTIONS' => $handler->getOptions(),
			'INSTALLED' => $isEmbedding ? AppTable::INSTALLED : $app?->getInstalled(),
			'APP_NAME' => $isEmbedding ? '' : $app?->getAppName(),
			'APP_ACCESS' => $isEmbedding ? '' : $app?->getAccess(),
			'LANG_ALL' => [],
		];

		if ($row['ICON_ID'] > 0 && ($file = \CFile::GetFileArray($row['ICON_ID'])))
		{
			$row['ICON'] = array_change_key_case($file, CASE_LOWER);
		}

		if ($handler->getLangAll() !== null)
		{
			foreach ($handler->getLangAll() as $lang)
			{
				$row['LANG_ALL'][$lang->getLanguageId()] = [
					'TITLE' => $lang->getTitle(),
					'DESCRIPTION' => $lang->getDescription(),
					'GROUP_NAME' => $lang->getGroupName(),
				];
			}
		}

		return $row;
	}

	private function isEmbedding(EO_Placement $handler): bool
	{
		return EmbeddingOwnerResolver::isNonAppOwnerPlacement($handler);
	}

	private function isFlagOn(mixed $value): bool
	{
		return $value === true || $value === AppTable::ACTIVE;
	}
}
