<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\PageLayout\SwitchUsersPageLayout;

use Bitrix\Crm\Dto\Caster;
use Bitrix\Crm\Dto\Validator\DefinedEntityTypeId;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\ScalarCollectionField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class SwitchUsersPageLayoutToolDto extends AbstractToolDto
{
	public ?int $entityTypeId = null;
	public ?string $pageLayoutId = null;
	public ?array $userIds = null;

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName) {
			'userIds' => new Caster\CollectionCaster(new Caster\IntCaster()),
			default => null,
		};
	}

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'entityTypeId'),
			new IntegerField($this, 'entityTypeId'),
			new DefinedEntityTypeId($this, 'entityTypeId'),

			new RequiredField($this, 'pageLayoutId'),
			new StringField($this, 'pageLayoutId'),
			new NotEmptyField($this, 'pageLayoutId'),

			new RequiredField($this, 'userIds'),
			new ScalarCollectionField($this, 'userIds'),
			new NotEmptyField($this, 'userIds'),
		];
	}
}
