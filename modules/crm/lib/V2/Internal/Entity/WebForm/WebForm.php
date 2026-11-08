<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Section;
use Bitrix\Main\Type\DateTime;

/**
 * @internal
 */
final readonly class WebForm
{
	public const FIELD_ID = 'id';
	public const FIELD_CODE = 'code';
	public const FIELD_NAME = 'name';
	public const FIELD_ACTIVE = 'active';
	public const FIELD_SYSTEM = 'system';
	public const FIELD_LANGUAGE_ID = 'languageId';
	public const FIELD_TEMPLATE_ID = 'templateId';
	public const FIELD_CREATED_TIME = 'createdTime';
	public const FIELD_MODIFIED_TIME = 'modifiedTime';
	public const FIELD_ACTIVE_CHANGED_BY_ID = 'activeChangedById';
	public const FIELD_ACTIVE_CHANGED_TIME = 'activeChangedTime';

	public const SECTION_APPEARANCE = 'appearance';
	public const SECTION_CRM = 'crm';
	public const SECTION_FIELDS = 'fields';
	public const SECTION_DEPENDENCIES = 'dependencies';
	public const SECTION_PRESET_FIELDS = 'presetFields';
	public const SECTION_RESPONSIBLE = 'responsible';
	public const SECTION_AGREEMENTS = 'agreements';
	public const SECTION_SUBMISSION = 'submission';
	public const SECTION_PAYMENT = 'payment';
	public const SECTION_CALLBACK = 'callback';
	public const SECTION_BOOKING = 'booking';
	public const SECTION_ANALYTICS = 'analytics';
	public const SECTION_SPAM_PROTECTION = 'spamProtection';
	public const SECTION_INTEGRATIONS = 'integrations';

	/**
	 * @param array<Section\Field>|null $fields
	 * @param array<Section\DependencyGroup>|null $dependencies
	 * @param array<Section\PresetField>|null $presetFields
	 */
	public function __construct(
		public int $id,
		public ?string $code = null,
		public ?string $name = null,
		public ?bool $active = null,
		public ?bool $system = null,
		public ?string $languageId = null,
		public ?string $templateId = null,
		public ?DateTime $createdTime = null,
		public ?DateTime $modifiedTime = null,
		public ?int $activeChangedById = null,
		public ?DateTime $activeChangedTime = null,
		public ?Section\Appearance $appearance = null,
		public ?Section\CrmSettings $crm = null,
		public ?array $fields = null,
		public ?array $dependencies = null,
		public ?array $presetFields = null,
		public ?Section\Responsible $responsible = null,
		public ?Section\Agreements $agreements = null,
		public ?Section\Submission $submission = null,
		public ?Section\Payment $payment = null,
		public ?Section\Callback $callback = null,
		public ?Section\Booking $booking = null,
		public ?Section\Analytics $analytics = null,
		public ?Section\SpamProtection $spamProtection = null,
		public ?Section\Integrations $integrations = null,
	)
	{
	}

	public function getId(): int
	{
		return $this->id;
	}
}
