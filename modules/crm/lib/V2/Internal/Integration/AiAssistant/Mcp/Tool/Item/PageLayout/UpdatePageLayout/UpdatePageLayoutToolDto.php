<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\PageLayout\UpdatePageLayout;

use Bitrix\Crm\Dto\Validator\DefinedEntityTypeId;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\ObjectField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Crm\Entity\EntityEditorConfigScope;
use Bitrix\Crm\V2\Infrastructure\Component\Details\PageLayout\PageLayoutId;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Request\PageLayout;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Validator\PageLayoutTitleField;

final class UpdatePageLayoutToolDto extends AbstractToolDto
{
	public ?int $entityTypeId = null;
	public ?string $pageLayoutId = null;
	public ?PageLayout $pageLayout = null;

	protected function getValidators(array $fields): array
	{
		$validators = [
			new RequiredField($this, 'entityTypeId'),
			new IntegerField($this, 'entityTypeId'),
			new DefinedEntityTypeId($this, 'entityTypeId'),

			new RequiredField($this, 'pageLayoutId'),
			new StringField($this, 'pageLayoutId'),
			new NotEmptyField($this, 'pageLayoutId'),

			new RequiredField($this, 'pageLayout'),
			new ObjectField($this, 'pageLayout'),
		];

		$pageLayoutId = PageLayoutId::fromCode((string)($fields['pageLayoutId'] ?? ''));
		if ($pageLayoutId?->scope === EntityEditorConfigScope::CUSTOM)
		{
			$validators[] = new PageLayoutTitleField($this, isRequired: true);
		}
		elseif (
			$pageLayoutId?->scope === EntityEditorConfigScope::COMMON
			|| $pageLayoutId?->scope === EntityEditorConfigScope::PERSONAL
		)
		{
			$validators[] = new PageLayoutTitleField($this, isRequired: false);
		}

		return $validators;
	}
}
