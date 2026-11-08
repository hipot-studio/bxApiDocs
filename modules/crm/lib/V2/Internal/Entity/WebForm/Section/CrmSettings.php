<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

/**
 * The CRM settings of a form: which entities a submission creates, in which funnel, and how a
 * repeated submission is matched against what the CRM already holds.
 *
 * A funnel of null means the form names none. Zero is not that: it is the main funnel of deals.
 *
 * @internal
 */
final readonly class CrmSettings
{
	public const FIELD_ENTITY_SCHEME = 'entityScheme';
	public const FIELD_DUPLICATE_MODE = 'duplicateMode';
	public const FIELD_DEAL_CATEGORY_ID = 'dealCategoryId';
	public const FIELD_DEAL_DUPLICATE_CONTROL_ENABLED = 'dealDuplicateControlEnabled';
	public const FIELD_SMART_PROCESS_CATEGORY_ID = 'smartProcessCategoryId';
	public const FIELD_SMART_PROCESS_DUPLICATE_CONTROL_ENABLED = 'smartProcessDuplicateControlEnabled';

	public function __construct(
		public ?int $entityScheme = null,
		public ?string $duplicateMode = null,
		public ?int $dealCategoryId = null,
		public ?bool $dealDuplicateControlEnabled = null,
		public ?int $smartProcessCategoryId = null,
		public ?bool $smartProcessDuplicateControlEnabled = null,
	)
	{
	}
}
