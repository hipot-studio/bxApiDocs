<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Rest\Dto\Application\Embedding;

use Bitrix\Rest\Internal\Entity\Application\Embedding\Embedding;
use Bitrix\Rest\Internal\Entity\Application\Embedding\EmbeddingCollection;
use Bitrix\Rest\V3\Dto\DtoCollection;

class EmbeddingDtoMapper
{
	private const DTO_CLASS = EmbeddingDto::class;
	private ?EmbeddingLanguageDtoMapper $languageDtoMapper = null;

	public function toDto(Embedding $ormEntity): EmbeddingDto
	{
		$dto = new (self::DTO_CLASS)();

		$dto->id = $ormEntity->getId();
		$dto->userId = $ormEntity->getUserId();
		$dto->placement = $ormEntity->getPlacement();
		$dto->handler = $ormEntity->getHandler();
		$dto->title = $ormEntity->getTitle();
		$dto->description = $ormEntity->getDescription();
		$dto->groupName = $ormEntity->getGroupName();
		$dto->additional = $ormEntity->getAdditional();
		$dto->options = $ormEntity->getOptions();

		if (!$ormEntity->getLanguages()->isEmpty()) {
			$dto->languages = $this->getLanguageDtoMapper()->toDtoCollection($ormEntity->getLanguages());
		}

		return $dto;
	}

	public function toDtoCollection(EmbeddingCollection $entityCollection): DtoCollection
	{
		$dtoCollection = new DtoCollection(self::DTO_CLASS);
		foreach ($entityCollection as $entity) {
			$dtoCollection->add($this->toDto($entity));
		}

		return $dtoCollection;
	}

	private function getLanguageDtoMapper(): EmbeddingLanguageDtoMapper
	{
		return $this->languageDtoMapper ??= new EmbeddingLanguageDtoMapper();
	}
}
