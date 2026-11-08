<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\WebForm;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Section;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormChangeSet;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormSelect;

/**
 * The projection of the stored form a change set is weighed against: what may not be changed, and
 * what the validation of a partial change set has to fall back on.
 *
 * Several checks need more of the form than that, and each part is read only when the change set
 * can ask for it: the queue when the work time check is sent, because a form ending up without a
 * queue can not hold it, and the ad links when links are sent, because the names of a stored link
 * are not rewritten. A change touching none of them costs none of those reads.
 *
 * One rule serves the write and its dry run ({@see FormWriter}, {@see FormChecker}): both hand the
 * form they read to the same {@see WebFormValidator::validate()}, so a second rule would drift and
 * make the two answer about differently read forms.
 *
 * @internal
 */
final class CurrentStateSelect
{
	/**
	 * @param WebFormChangeSet|null $changeSet null asks for the paths every change is weighed
	 *        against and for nothing the sent change could widen the projection by
	 */
	public static function build(
		?WebFormChangeSet $changeSet,
		bool $includeModifiedTime = false,
		bool $includeActivity = false,
	): WebFormSelect
	{
		$paths = [
			WebForm::FIELD_ID,
			WebForm::FIELD_NAME,
			WebForm::FIELD_SYSTEM,
			WebFormValidator::ENTITY_SCHEME_PATH,
			WebForm::SECTION_FIELDS . '.' . Section\Field::FIELD_NAME,
			WebForm::SECTION_FIELDS . '.' . Section\Field::FIELD_TYPE,
		];
		if ($includeModifiedTime)
		{
			$paths[] = WebForm::FIELD_MODIFIED_TIME;
		}

		// Whether the form works decides whether its removal has to be confirmed, so the removal
		// reads it. A change neither writes the activity nor answers for it, and never asks.
		if ($includeActivity)
		{
			$paths[] = WebForm::FIELD_ACTIVE;
		}

		if ($changeSet?->has(WebFormValidator::CHECK_WORK_TIME_PATH))
		{
			$paths[] = WebFormValidator::RESPONSIBLE_USER_IDS_PATH;
		}

		// A move to another CRM scheme is answered by what the form already holds: the fields are
		// read anyway, the preset values only here.
		if ($changeSet?->has(WebFormValidator::ENTITY_SCHEME_PATH))
		{
			$paths[] = WebForm::SECTION_PRESET_FIELDS . '.' . Section\PresetField::FIELD_ENTITY_NAME;
			$paths[] = WebForm::SECTION_PRESET_FIELDS . '.' . Section\PresetField::FIELD_FIELD_NAME;
		}

		// An agreement the form already holds stays usable whatever its state, so the stored ones
		// take part in the check of the input.
		if ($changeSet?->has(WebFormValidator::AGREEMENT_ITEMS_PATH))
		{
			$paths[] = WebFormValidator::AGREEMENT_ITEMS_PATH . '.' . Section\AgreementItem::FIELD_AGREEMENT_ID;
		}

		// The switches the portal has to back are read as they are stored: a form that already has
		// one on is not refused for keeping it. The funnels, the pay systems switched off and the
		// caption of the offer to fill the form again follow the same rule, and reading one of them
		// costs the whole column of settings, so they are asked for only when they are sent.
		foreach ([
			WebFormValidator::PAYMENT_ENABLED_PATH,
			WebFormValidator::CALLBACK_ENABLED_PATH,
			WebFormValidator::WHATS_APP_ENABLED_PATH,
			WebFormValidator::CALLBACK_FROM_PATH,
			WebFormValidator::DEAL_CATEGORY_ID_PATH,
			WebFormValidator::SMART_PROCESS_CATEGORY_ID_PATH,
			WebFormValidator::DISABLED_PAY_SYSTEMS_ID_PATH,
		] as $gatedPath)
		{
			if ($changeSet?->has($gatedPath))
			{
				$paths[] = $gatedPath;
			}
		}

		// The offer to fill the form again is checked as a pair with its caption, so sending either
		// one reads both: they live in the column the read of either one costs anyway.
		if (
			$changeSet?->has(WebFormValidator::REFILL_ENABLED_PATH)
			|| $changeSet?->has(WebFormValidator::REFILL_CAPTION_PATH)
		)
		{
			$paths[] = WebFormValidator::REFILL_ENABLED_PATH;
			$paths[] = WebFormValidator::REFILL_CAPTION_PATH;
		}

		if ($changeSet?->has(WebFormValidator::ADS_PATH))
		{
			foreach ([
				// The direction comes along: the write works on the links importing leads, and a
				// link of the other direction shares nothing but the three values naming it.
				Section\AdIntegration::FIELD_DIRECTION,
				Section\AdIntegration::FIELD_PROVIDER_CODE,
				Section\AdIntegration::FIELD_ACCOUNT_ID,
				Section\AdIntegration::FIELD_FORM_ID,
				Section\AdIntegration::FIELD_ACCOUNT_NAME,
				Section\AdIntegration::FIELD_FORM_NAME,
			] as $property)
			{
				$paths[] = WebFormValidator::ADS_PATH . '.' . $property;
			}
		}

		return WebFormSelect::fromPaths($paths);
	}
}
