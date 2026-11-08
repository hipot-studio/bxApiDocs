<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Embedding\EmbeddingSelectionDto;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\EmbeddingSelection;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding\EmbeddingVariant;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentTypeException;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;
use InvalidArgumentException;

/** Maps the selected embedding value without adding fields of the full embedding response. */
final class EmbeddingSelectionDtoMapper extends Mapper
{
	/**
	 * @param EmbeddingSelection[] $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(EmbeddingSelectionDto::class);
		foreach ($items as $item)
		{
			if (!$item instanceof EmbeddingSelection)
			{
				throw new ArgumentTypeException('items', EmbeddingSelection::class);
			}

			$collection->add($this->mapSelection($item));
		}

		return $collection;
	}

	private function mapSelection(EmbeddingSelection $selection): EmbeddingSelectionDto
	{
		$dto = new EmbeddingSelectionDto();
		$dto->variant = $selection->variant;

		if ($selection->variant === EmbeddingSelection::LINK)
		{
			if ($selection->url === null)
			{
				throw new ArgumentNullException('url');
			}

			$dto->url = $selection->url;

			return $dto;
		}

		if (!in_array($selection->variant, self::codeVariants(), true))
		{
			throw new InvalidArgumentException(sprintf('Unknown embedding variant `%s`', $selection->variant));
		}

		$dto->code = $selection->code;

		return $dto;
	}

	/** @return string[] */
	private static function codeVariants(): array
	{
		return [EmbeddingVariant::INLINE, EmbeddingVariant::CLICK, EmbeddingVariant::AUTO];
	}
}
