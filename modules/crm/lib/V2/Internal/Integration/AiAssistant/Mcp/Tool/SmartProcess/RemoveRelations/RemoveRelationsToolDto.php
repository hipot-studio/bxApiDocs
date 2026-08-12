<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\RemoveRelations;

use Bitrix\Crm\Dto\Caster;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\ScalarCollectionField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Service\SmartProcessRelationsService;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class RemoveRelationsToolDto extends AbstractToolDto
{
	public ?int $smartProcessId = null;

	/** @var int[]|null */
	public ?array $parentEntities = null;

	/** @var int[]|null */
	public ?array $childEntities = null;

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName) {
			'parentEntities', 'childEntities' => new Caster\CollectionCaster(new Caster\IntCaster()),
			default => null,
		};
	}

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'smartProcessId'),
			new IntegerField($this, 'smartProcessId', min: 0),

			new ScalarCollectionField(
				$this,
				'parentEntities',
				maxCount: SmartProcessRelationsService::MAX_RELATIONS_PER_DIRECTION,
			),
			new ScalarCollectionField(
				$this,
				'childEntities',
				maxCount: SmartProcessRelationsService::MAX_RELATIONS_PER_DIRECTION,
			),
		];
	}
}
