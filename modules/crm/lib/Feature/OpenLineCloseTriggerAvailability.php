<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Crm\Feature\Category\BaseCategory;
use Bitrix\Crm\Feature\Category\Integration;
use Bitrix\Main\Localization\Loc;

/**
 * Visibility of the open line close trigger, off until the node is rolled out. While the feature is off the
 * trigger leaves the registry of automation triggers, so neither the classic automation nor the node catalog of
 * the designer offers it.
 *
 * The flag governs the offer, not the run: a trigger already stored on a stage is looked up by its code on the
 * target, not in that registry, so turning the feature off does not stop an automation somebody configured while
 * it was on.
 */
final class OpenLineCloseTriggerAvailability extends BaseFeature
{
	public function getName(): string
	{
		return Loc::getMessage('CRM_FEATURE_OPENLINE_CLOSE_TRIGGER_NAME') ?? '';
	}

	public function getCategory(): BaseCategory
	{
		return Integration::getInstance();
	}

	/**
	 * The state is changed from code, so the secret link of the feature list is closed: that link is the one
	 * switch of a feature that works on a client portal, where nobody is meant to turn the node on by hand.
	 * The toggle of the feature list keeps working - it lives behind the access check of that page, which lets
	 * in an admin of an etalon portal or a stand only.
	 */
	public function allowSwitchBySecretLink(): bool
	{
		return false;
	}

	protected function getOptionName(): string
	{
		return 'openline_close_trigger';
	}
}
