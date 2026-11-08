<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Bitrix24;

use Bitrix\Bitrix24\Feature;
use Bitrix\Bitrix24\PhoneVerify;
use Bitrix\Crm\WebForm\Form;
use Bitrix\Main\Loader;

/**
 * The single point CRM V2 asks the portal plan what it allows to do with a web form.
 *
 * Every restriction here belongs to the cloud: a box portal has no plan to restrict by, so with the
 * module absent each question answers "nothing is restricted". The conditions enabling a
 * restriction are the ones the legacy editor controller applies, moved into the write layer where
 * they belong.
 *
 * @internal
 */
class WebFormWriteRestriction
{
	public const MODULE_ID = 'bitrix24';

	/** Plan feature covering every change of a web form, the editor and this contract alike. */
	private const FEATURE_WEB_FORM_WRITE = 'crm_webform_edit';

	/**
	 * Plans asking for a confirmed phone number before a form may be changed. Zone exceptions and
	 * the portal option forcing confirmation live inside the mechanism itself and are not repeated
	 * here.
	 */
	private const PHONE_VERIFIED_LICENSE_TYPES = [
		'project',
		'demo',
	];

	/**
	 * Tells whether the plan of the portal allows writing web forms at all.
	 */
	public function isWriteAllowed(): bool
	{
		if (!$this->isPlanRestricted())
		{
			return true;
		}

		return Feature::isFeatureEnabled(self::FEATURE_WEB_FORM_WRITE);
	}

	/**
	 * Tells whether the phone requirement of the portal is satisfied for the form.
	 *
	 * Answers true when the requirement does not apply at all: on a box portal, and on a plan that
	 * is not one of the restricted ones. Creation asks about the form id zero, the way the legacy
	 * editor does: the requirement is confirmed per form, and a form being created has no id yet.
	 */
	public function isPhoneVerified(int $formId): bool
	{
		if (!$this->isPlanRestricted())
		{
			return true;
		}

		if (!in_array(\CBitrix24::getLicenseType(), self::PHONE_VERIFIED_LICENSE_TYPES, true))
		{
			return true;
		}

		return (new PhoneVerify(Form::PHONE_VERIFY_ENTITY, (string)$formId))->isVerified();
	}

	/**
	 * Tells whether the number of working forms is limited on this portal at all.
	 *
	 * Only the answer lives here, never the limit itself: how many forms are working and how many
	 * the portal may keep working are counted by the repository reading forms. A box portal has no
	 * plan to be limited by and turns forms on without a count, the way the product does
	 * ({@see Form::canActivateForm()}).
	 */
	public function isActivationLimited(): bool
	{
		return $this->isPlanRestricted();
	}

	/**
	 * Tells whether the portal may publish a form without the copyright of the product. A form of a
	 * portal that may not keeps the copyright without an error of its own: this is the plan of the
	 * portal talking, not the input of the caller.
	 */
	public function canRemoveCopyright(): bool
	{
		if (!$this->isPlanRestricted())
		{
			return true;
		}

		return \CBitrix24::IsLicensePaid();
	}

	/**
	 * Tells whether the portal has a plan restricting it at all. A box portal has none.
	 */
	protected function isPlanRestricted(): bool
	{
		return Loader::includeModule(self::MODULE_ID);
	}
}
