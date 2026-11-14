<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Tracking;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking\ChannelDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Tracking\SourceDto;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Channel;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Source;
use Bitrix\Crm\V2\Internal\Entity\Tracking\SourceChanges;

/**
 * Maps the sources and the channels of the domain to their REST contract and the input of
 * `crm.tracking.source.add|update` to the changes of a source.
 */
final class SourceDtoMapper
{
	/**
	 * @param string[] $select the top-level fields to answer; empty answers every field
	 */
	public function toDto(Source $source, array $select = []): SourceDto
	{
		$values = [
			'id' => $source->id,
			'code' => $source->code?->value,
			'kind' => $source->isBuiltIn() ? SourceDto::KIND_BUILT_IN : SourceDto::KIND_OWN,
			'name' => $source->name,
			'color' => $source->color,
			'isActive' => $source->isActive,
			'isConfigurable' => $source->isConfigurable,
			'isConfigured' => $source->isConfigured,
			'refDomains' => array_values($source->refDomains),
			'utmSources' => array_values($source->utmSources),
		];
		$fields = empty($select) ? SourceDto::OUTPUT_FIELDS : array_intersect(SourceDto::OUTPUT_FIELDS, $select);

		$dto = new SourceDto();
		foreach ($fields as $field)
		{
			$dto->{$field} = $values[$field];
		}

		return $dto;
	}

	public function toChannelDto(Channel $channel): ChannelDto
	{
		$dto = new ChannelDto();
		$dto->code = $channel->code->value;
		$dto->name = $channel->name;
		$dto->isConfigurable = $channel->isConfigurable;
		$dto->isConfigured = $channel->isConfigured;

		return $dto;
	}

	/**
	 * Reads the changes off the input of `add` or `update`, already checked against the DTO: a field that was
	 * not passed, or was passed as null, is not changed.
	 */
	public function toChanges(SourceDto $dto): SourceChanges
	{
		return new SourceChanges(
			name: $dto->name ?? null,
			color: $dto->color ?? null,
			refDomains: $dto->refDomains ?? null,
			utmSources: $dto->utmSources ?? null,
		);
	}
}
