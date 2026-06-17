<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Dto\Mapper;

final class DtoMapper
{
	/**
	 * @template TDto of object
	 *
	 * @param array<string, mixed>|object $source
	 * @param class-string<TDto> $dtoClass
	 * @return TDto
	 */
	public function mapToDto(array|object $source, string $dtoClass): object
	{
		return $dtoClass::mapFromArray($this->normalizeSource($source));
	}

	/**
	 * @template TDtoCollection of object
	 *
	 * @param iterable<array<string, mixed>|object> $sources
	 * @param class-string $dtoClass
	 * @param class-string<TDtoCollection> $dtoCollectionClass
	 * @return TDtoCollection
	 */
	public function mapToDtoCollection(iterable $sources, string $dtoClass, string $dtoCollectionClass): object
	{
		$items = [];

		foreach ($sources as $source)
		{
			if (!is_array($source) && !is_object($source))
			{
				continue;
			}

			$items[] = $this->mapToDto($source, $dtoClass);
		}

		return new $dtoCollectionClass(...$items);
	}

	/**
	 * @param array<string, mixed>|object $source
	 * @return array<string, mixed>
	 */
	private function normalizeSource(array|object $source): array
	{
		if (is_array($source))
		{
			return $source;
		}

		return get_object_vars($source);
	}
}
