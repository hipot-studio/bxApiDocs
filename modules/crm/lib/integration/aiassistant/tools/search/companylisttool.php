<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Search;

use CCrmOwnerType;

final class CompanyListTool extends BaseListTool
{
	private const DEFAULT_CATEGORY_ID = 0;

	public function getName(): string
	{
		return 'company_list';
	}

	public function getDescription(): string
	{
		return <<<TEXT
Searches for companies by parameters. 
Use this function when you need to find companies by keyword or other criteria.
A limit on the number of companies to search can also be specified.
For the found companies, a special URL [`items_url`] is generated that opens the CRM company list.
TEXT;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'keyword' => [
					'description' => 'Keyword to search for a company (minimum 2 characters).',
					'type' => 'string',
					'minLength' => 2,
					'maxLength' => 50,
				],
				'limit' => [
					'description' => 'Maximum number of companies to return.',
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => self::DEFAULT_ITEMS_MAX_LIMIT,
					'default' => self::DEFAULT_ITEMS_LIMIT,
				],
			],
			'additionalProperties' => false,
		];
	}

	public function canRun(int $userId): bool
	{
		return $this
			->permissionService
			->canReadAllItemsOfType(
				$userId,
				$this->getEntityTypeId(),
				self::DEFAULT_CATEGORY_ID
			)
		;
	}

	protected function getEntityTypeId(): int
	{
		return CCrmOwnerType::Company;
	}

	protected function buildFilter(int $userId, array $args): array
	{
		return [
			'=CATEGORY_ID' => self::DEFAULT_CATEGORY_ID, // access only to default category
		];
	}
}
