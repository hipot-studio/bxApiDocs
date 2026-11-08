<?php

namespace Bitrix\Crm\Feature;

use Bitrix\Crm\Feature\Category\BaseCategory;
use Bitrix\Crm\Feature\Category\Integration;
use Bitrix\Main\Localization\Loc;

/**
 * Visibility of the outgoing open line message trigger, off until the node is rolled out. While the feature is
 * off the trigger leaves the registry of automation triggers, so neither the classic automation nor the node
 * catalog of the designer offers it.
 *
 * The flag governs the offer, not the run: a trigger already stored on a stage is looked up by its code on the
 * target, not in that registry, so turning the feature off does not stop an automation somebody configured while
 * it was on.
 */
final class OpenLineOutgoingMessageTriggerAvailability extends BaseFeature
{
	public function getName(): string
	{
		return Loc::getMessage('CRM_FEATURE_OPENLINE_OUTGOING_MESSAGE_TRIGGER_NAME') ?? '';
	}

	public function getCategory(): BaseCategory
	{
		return Integration::getInstance();
	}

	protected function getOptionName(): string
	{
		return 'openline_outgoing_message_trigger';
	}

	/**
	 * No secret link: the GET switch of the admin surface asks for CRM admin alone, while its ajax switcher
	 * additionally admits only an etalon or a test portal. A node that is not rolled out yet must not be
	 * turned on by a link on a customer portal, so the switch stays with the stricter of the two.
	 */
	public function allowSwitchBySecretLink(): bool
	{
		return false;
	}
}
