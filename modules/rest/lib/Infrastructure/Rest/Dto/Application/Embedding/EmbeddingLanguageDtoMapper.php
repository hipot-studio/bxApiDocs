<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Dto\Application\Embedding;

use Bitrix\Rest\Internal\Entity\Application\Embedding\EmbeddingLanguage;
use Bitrix\Rest\Internal\Entity\Application\Embedding\EmbeddingLanguageCollection;
use Bitrix\Rest\V3\Dto\DtoCollection;

class EmbeddingLanguageDtoMapper
{
	private const DTO_CLASS = EmbeddingLanguageDto::class;

	public function toDto(EmbeddingLanguage $language): EmbeddingLanguageDto
	{
		$dto = new (static::DTO_CLASS)();

		$dto->languageId = $language->getLanguageId();
		$dto->title = $language->getTitle();
		$dto->description = $language->getDescription();
		$dto->groupName = $language->getGroupName();

		return $dto;
	}

	public function toDtoCollection(EmbeddingLanguageCollection $entityCollection): DtoCollection
	{
		$collection = new DtoCollection(static::DTO_CLASS);
		foreach ($entityCollection as $entity) {
			$collection->add($this->toDto($entity));
		}

		return $collection;
	}
}
