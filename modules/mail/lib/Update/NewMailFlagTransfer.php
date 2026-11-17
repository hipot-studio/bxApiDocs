<?php

declare(strict_types=1);

namespace Bitrix\Mail\Update;

use Bitrix\Mail\Public\Features\Bitrix24MailFeature;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Feature\FeatureFlagTable;
use Bitrix\Main\Config\Option;

/**
 * Moves a portal that ran the New mail on the legacy options onto the flag once, so the update of mail does not
 * stop a running migration. A stored value of the flag is a decision of the portal and is never overridden;
 * the legacy options stay as they are in the database, unread by anything else: this one-time transfer is
 * their only reader.
 */
final class NewMailFlagTransfer
{
	private const LEGACY_RECEIVING = ['mail', 'source_generations_enabled'];
	private const LEGACY_EXCHANGE = ['mailservice', 'mailhost_enabled'];

	public static function run(): void
	{
		if (FeatureFlagTable::getById(Bitrix24MailFeature::class)->fetch() !== false)
		{
			return;
		}

		if (
			Option::get(...[...self::LEGACY_RECEIVING, 'N']) !== 'Y'
			|| Option::get(...[...self::LEGACY_EXCHANGE, 'N']) !== 'Y'
		)
		{
			return;
		}

		Feature::enable(Bitrix24MailFeature::class);
	}
}
