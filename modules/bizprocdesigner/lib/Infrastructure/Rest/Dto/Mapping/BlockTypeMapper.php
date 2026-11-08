<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\Mapping;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\BlockTypeDto;
use Bitrix\BizprocDesigner\Internal\Entity\BlockType;
use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * Maps the agent-facing block catalog onto its transport shape.
 *
 * The item decides the projection: a BlockType is a catalog entry and fills the header only, a
 * BlockTypeDetail is one block read in full and fills the detail parts as well. Both answer with the
 * same dto, because the detail is a composition around the block type, not a resource of its own.
 */
final class BlockTypeMapper extends AbstractAgentMapper
{
	/**
	 * @param list<BlockType|BlockTypeDetail> $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(BlockTypeDto::class);
		foreach ($items as $item)
		{
			$dto = match (true)
			{
				$item instanceof BlockTypeDetail => $this->mapDetail($item, $fields),
				$item instanceof BlockType => $this->mapType($item, $fields),
				default => new BlockTypeDto(),
			};

			$collection->add($dto);
		}

		return $collection;
	}

	/**
	 * The listing leaves the detail parts uninitialized, so a catalog entry carries no empty keys.
	 */
	private function mapType(BlockType $blockType, array $fields): BlockTypeDto
	{
		return $this->fill(new BlockTypeDto(), $blockType->toArray(), $fields);
	}

	/**
	 * The nested `block` part is lifted into the same dto - that is what makes the read of one block a
	 * flat object of the same resource. The rest is transferred as the domain emits it, so neither the
	 * unconditional emission of settings, carriedSettings, settingConstraints, returnFields and
	 * typesDescription nor the conditional emission of defaultValues, defaultSettings and complexActions
	 * is duplicated here.
	 */
	private function mapDetail(BlockTypeDetail $detail, array $fields): BlockTypeDto
	{
		$payload = $detail->toArray();
		unset($payload['block']);

		return $this->fill($this->mapType($detail->block, $fields), $payload, $fields);
	}

	private function fill(BlockTypeDto $dto, array $payload, array $fields): BlockTypeDto
	{
		foreach ($payload as $propertyName => $value)
		{
			if (!$this->isRequested($propertyName, $fields))
			{
				continue;
			}

			$dto->{$propertyName} = $value;
		}

		return $dto;
	}
}
