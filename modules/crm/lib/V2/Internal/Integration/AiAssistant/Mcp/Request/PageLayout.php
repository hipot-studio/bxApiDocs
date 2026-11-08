<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Request;

use Bitrix\Crm\Dto\Caster;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\ObjectCollectionField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Request\PageLayout\Section;

final class PageLayout extends Dto
{
	public ?string $title = null;
	/** @var array<Section>|null  */
	public ?array $sections = null;

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName) {
			'sections' => new Caster\CollectionCaster(new Caster\ObjectCaster(Section::class)),
			default => null,
		};
	}

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'sections'),
			new ObjectCollectionField($this, 'sections'),
		];
	}
}
