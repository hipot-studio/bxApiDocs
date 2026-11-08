<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Chat\Dto;

/**
 * Raw `entities` payload of im.v2.Chat.Structure.link: a list of [type, id] pairs.
 * Pairs are passed on as they came - a malformed one must reach the action and get its own
 * error code instead of an auto-wiring exception, and the `<id>:F` form must survive
 * untouched because the suffix selects the non-recursive access code.
 */
final class StructureLinkFieldsDto
{
	private function __construct(
		private readonly array $entities,
	)
	{
	}

	public static function create(array $fields): self
	{
		$entities = $fields['entities'] ?? [];

		return new self(is_array($entities) ? array_values($entities) : []);
	}

	public function getEntities(): array
	{
		return $this->entities;
	}

	public function hasEntities(): bool
	{
		return $this->entities !== [];
	}
}
