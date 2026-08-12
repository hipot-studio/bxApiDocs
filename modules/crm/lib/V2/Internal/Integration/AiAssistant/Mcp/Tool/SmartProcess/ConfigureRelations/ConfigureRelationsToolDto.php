<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\ConfigureRelations;

use Bitrix\Crm\Dto\Caster;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\ObjectCollectionField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Request\RelationEntityDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\SmartProcessRelationsService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class ConfigureRelationsToolDto extends AbstractToolDto
{
	public ?int $smartProcessId = null;

	/** @var RelationEntityDto[]|null */
	public ?array $parentEntities = null;

	/** @var RelationEntityDto[]|null */
	public ?array $childEntities = null;

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName) {
			'parentEntities', 'childEntities' => new Caster\CollectionCaster(
				new Caster\ObjectCaster(RelationEntityDto::class),
			),
			default => null,
		};
	}

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'smartProcessId'),
			new IntegerField($this, 'smartProcessId', min: 0),

			new ObjectCollectionField(
				$this,
				'parentEntities',
				maxCount: SmartProcessRelationsService::MAX_RELATIONS_PER_DIRECTION,
			),
			new ObjectCollectionField(
				$this,
				'childEntities',
				maxCount: SmartProcessRelationsService::MAX_RELATIONS_PER_DIRECTION,
			),
		];
	}
}
