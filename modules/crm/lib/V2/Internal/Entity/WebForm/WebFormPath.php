<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Section;

/**
 * Addresses of the input document of a CRM form write, in the one notation the contract publishes.
 *
 * An index of a collection is written in brackets and a property is written after a dot, so
 * `responsible.userIds[0]` tells the entry of a list from a property named `0` without any guessing
 * on the side of the caller. Both stages of the validation build the address here: told in two
 * notations, the same property of the same request would get two public addresses depending on which
 * stage refused it, and a caller matching the answer against what it sent would break on the second.
 *
 * The paths of the collections themselves live here for the same reason - the transport, the domain
 * validation and the storage refusals all name them.
 *
 * @internal
 */
final class WebFormPath
{
	public const RESPONSIBLE_USER_IDS =
		WebForm::SECTION_RESPONSIBLE . '.' . Section\Responsible::FIELD_USER_IDS;
	public const RESPONSIBLE_CHECK_WORK_TIME =
		WebForm::SECTION_RESPONSIBLE . '.' . Section\Responsible::FIELD_CHECK_WORK_TIME;
	public const AGREEMENT_ITEMS = WebForm::SECTION_AGREEMENTS . '.' . Section\Agreements::FIELD_ITEMS;
	public const ADS = WebForm::SECTION_INTEGRATIONS . '.' . Section\Integrations::FIELD_ADS;
	public const AD_FIELD_MAPPINGS = self::ADS . '.' . Section\AdIntegration::FIELD_FIELD_MAPPINGS;
	public const DEPENDENCY_RULES =
		WebForm::SECTION_DEPENDENCIES . '.' . Section\DependencyGroup::FIELD_RULES;
	public const DEPENDENCY_CONDITION = self::DEPENDENCY_RULES . '.' . Section\DependencyRule::FIELD_CONDITION;
	public const DEPENDENCY_ACTION = self::DEPENDENCY_RULES . '.' . Section\DependencyRule::FIELD_ACTION;
	public const ENTITY_SCHEME = WebForm::SECTION_CRM . '.' . Section\CrmSettings::FIELD_ENTITY_SCHEME;
	public const PAYMENT_ENABLED = WebForm::SECTION_PAYMENT . '.' . Section\Payment::FIELD_ENABLED;
	public const CALLBACK_ENABLED = WebForm::SECTION_CALLBACK . '.' . Section\Callback::FIELD_ENABLED;
	public const CALLBACK_FROM = WebForm::SECTION_CALLBACK . '.' . Section\Callback::FIELD_FROM;
	public const WHATS_APP_ENABLED =
		WebForm::SECTION_INTEGRATIONS . '.' . Section\Integrations::FIELD_WHATS_APP_ENABLED;
	public const DUPLICATE_MODE = WebForm::SECTION_CRM . '.' . Section\CrmSettings::FIELD_DUPLICATE_MODE;
	public const DEAL_CATEGORY_ID = WebForm::SECTION_CRM . '.' . Section\CrmSettings::FIELD_DEAL_CATEGORY_ID;
	public const DEAL_DUPLICATE_CONTROL_ENABLED =
		WebForm::SECTION_CRM . '.' . Section\CrmSettings::FIELD_DEAL_DUPLICATE_CONTROL_ENABLED;
	public const SMART_PROCESS_CATEGORY_ID =
		WebForm::SECTION_CRM . '.' . Section\CrmSettings::FIELD_SMART_PROCESS_CATEGORY_ID;
	public const SMART_PROCESS_DUPLICATE_CONTROL_ENABLED =
		WebForm::SECTION_CRM . '.' . Section\CrmSettings::FIELD_SMART_PROCESS_DUPLICATE_CONTROL_ENABLED;
	public const REDIRECT_DELAY = WebForm::SECTION_SUBMISSION . '.' . Section\Submission::FIELD_REDIRECT_DELAY;
	public const REFILL = WebForm::SECTION_SUBMISSION . '.' . Section\Submission::FIELD_REFILL;
	public const REFILL_ENABLED = self::REFILL . '.' . Section\SubmissionRefill::FIELD_ENABLED;
	public const REFILL_CAPTION = self::REFILL . '.' . Section\SubmissionRefill::FIELD_CAPTION;
	public const DISABLED_PAY_SYSTEMS_ID =
		WebForm::SECTION_PAYMENT . '.' . Section\Payment::FIELD_DISABLED_PAY_SYSTEMS_ID;

	/**
	 * The element of a collection at the position it arrived at.
	 */
	public static function item(string $collection, int $index): string
	{
		return sprintf('%s[%d]', $collection, $index);
	}

	/**
	 * The property of a section, of an element of a collection or of an object inside one.
	 */
	public static function property(string $path, string $property): string
	{
		return $path === '' ? $property : $path . '.' . $property;
	}
}
