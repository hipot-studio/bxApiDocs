<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Task\Recurrence;

use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Exception\DtoValidationException;

class TemplateIdsDto
{
	use MapTypeTrait;

	public const MAX_ITEMS = 200;

	protected function __construct(
		public readonly ?array $templateIds = null,
		public readonly ?array $taskIds = null,
	)
	{
	}

	public static function fromArray(array $props): static
	{
		$templateIds = static::mapArray($props, 'templateIds');
		$taskIds = static::mapArray($props, 'taskIds');

		static::validateIdentifierIds($templateIds, $taskIds);

		return new static(templateIds: $templateIds, taskIds: $taskIds);
	}

	public static function getInputSchema(?int $maxItems = null): array
	{
		$maxItems ??= static::MAX_ITEMS;

		return [
			'type' => 'object',
			'properties' => [
				'templateIds' => static::buildIdsSchema(
					$maxItems,
					'task template',
					'template',
				),
				'taskIds' => static::buildIdsSchema(
					$maxItems,
					'task',
					'task',
				),
			],
			'oneOf' => [
				['required' => ['templateIds']],
				['required' => ['taskIds']],
			],
			'additionalProperties' => false,
		];
	}

	public function usesTaskIds(): bool
	{
		return $this->taskIds !== null;
	}

	protected static function validateIdentifierIds(?array $templateIds, ?array $taskIds): void
	{
		if (($templateIds === null) === ($taskIds === null))
		{
			throw new DtoValidationException('Provide exactly one of templateIds or taskIds.');
		}

		static::validateIds($templateIds ?? $taskIds, $templateIds !== null ? 'templateIds' : 'taskIds');
	}

	protected static function validateIds(?array $ids, string $fieldName): void
	{
		if ($ids === null || $ids === [])
		{
			throw new DtoValidationException("{$fieldName} must contain at least one item.");
		}

		if (count($ids) > static::MAX_ITEMS)
		{
			throw new DtoValidationException(
				"{$fieldName} must contain no more than " . static::MAX_ITEMS . ' items.',
			);
		}

		$uniqueIds = [];
		foreach ($ids as $id)
		{
			if (!is_int($id) || $id < 1)
			{
				throw new DtoValidationException("{$fieldName} must contain only positive integers.");
			}

			if (isset($uniqueIds[$id]))
			{
				throw new DtoValidationException("{$fieldName} must contain unique integers.");
			}

			$uniqueIds[$id] = true;
		}
	}

	private static function buildIdsSchema(int $maxItems, string $entity, string $fieldPrefix): array
	{
		return [
			'type' => 'array',
			'description' =>
				'The array must contain 1 to ' . $maxItems . " unique positive {$entity} IDs. "
				. "Send all target {$fieldPrefix} IDs together in one call. "
				. 'Use this field instead of the alternative identifier field.'
			,
			'items' => [
				'type' => 'integer',
				'minimum' => 1,
			],
			'minItems' => 1,
			'maxItems' => $maxItems,
			'uniqueItems' => true,
		];
	}
}
