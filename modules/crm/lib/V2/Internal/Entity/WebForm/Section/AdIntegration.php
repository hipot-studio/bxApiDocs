<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

/**
 * @internal
 */
final readonly class AdIntegration
{
	public const FIELD_DIRECTION = 'direction';
	public const FIELD_PROVIDER_CODE = 'providerCode';
	public const FIELD_ACCOUNT_ID = 'accountId';
	public const FIELD_ACCOUNT_NAME = 'accountName';
	public const FIELD_FORM_ID = 'formId';
	public const FIELD_FORM_NAME = 'formName';
	public const FIELD_FIELD_MAPPINGS = 'fieldMappings';

	/**
	 * @param null|array<AdFieldMapping> $fieldMappings
	 */
	public function __construct(
		public ?int $direction = null,
		public ?string $providerCode = null,
		public ?string $accountId = null,
		public ?string $accountName = null,
		public ?string $formId = null,
		public ?string $formName = null,
		public ?array $fieldMappings = null,
	)
	{
	}
}
