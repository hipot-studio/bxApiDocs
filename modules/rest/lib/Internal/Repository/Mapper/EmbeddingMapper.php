<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Repository\Mapper;

use Bitrix\Rest\EO_Placement;
use Bitrix\Rest\EO_Placement_Collection;
use Bitrix\Rest\EO_PlacementLang;
use Bitrix\Rest\EO_PlacementLang_Collection;
use Bitrix\Rest\Internal\Entity\Application\Embedding\Embedding;
use Bitrix\Rest\Internal\Entity\Application\Embedding\EmbeddingCollection;
use Bitrix\Rest\Internal\Entity\Application\Embedding\EmbeddingLanguage;
use Bitrix\Rest\Internal\Entity\Application\Embedding\EmbeddingLanguageCollection;

class EmbeddingMapper
{
	public function convertFromOrm(EO_Placement $ormEntity): Embedding
	{
		$embedding = new Embedding(
			placement: $ormEntity->getPlacement(),
			handler: $ormEntity->getPlacementHandler(),
			appId: $ormEntity->getAppId(),
			userId: $ormEntity->getUserId(),
			iconId: $ormEntity->getIconId(),
			title: $ormEntity->getTitle(),
			groupName: $ormEntity->getGroupName(),
			description: $ormEntity->getComment(),
			dateCreate: $ormEntity->getDateCreate(),
			additional: $ormEntity->getAdditional(),
			options: $ormEntity->getOptions(),
		);

		$embedding->setId($ormEntity->getId());

		if ($ormEntity->getLangAll() instanceof EO_PlacementLang_Collection)
		{
			$embedding->setLanguages($this->convertLanguageCollectionFromOrm($ormEntity->getLangAll()));
		}

		return $embedding;
	}

	public function convertCollectionFromOrm(EO_Placement_Collection $ormCollection): EmbeddingCollection
	{
		$collection = new EmbeddingCollection();
		foreach ($ormCollection as $ormEntity) {
			$collection->add($this->convertFromOrm($ormEntity));
		}

		return $collection;
	}

	public function convertLanguageFromOrm(EO_PlacementLang $ormEntity): EmbeddingLanguage
	{
		$language = new EmbeddingLanguage(
			embeddingId: $ormEntity->getPlacementId(),
			languageId: $ormEntity->getLanguageId(),
    		title: $ormEntity->getTitle(),
    		description: $ormEntity->getDescription(),
    		groupName: $ormEntity->getGroupName(),
		);

		$language->setId($ormEntity->getId());

		return $language;
	}

	public function convertLanguageCollectionFromOrm(
		EO_PlacementLang_Collection $ormCollection,
	): EmbeddingLanguageCollection
	{
		$collection = new EmbeddingLanguageCollection();
		foreach ($ormCollection as $ormEntity) {
			$collection->add($this->convertLanguageFromOrm($ormEntity));
		}

		return $collection;
	}
}
