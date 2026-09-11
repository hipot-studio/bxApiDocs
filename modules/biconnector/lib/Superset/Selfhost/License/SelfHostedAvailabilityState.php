<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\Selfhost\License;

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

enum SelfHostedAvailabilityState: string
{
	case Available = 'AVAILABLE';
	case TariffUnavailable = 'TARIFF_UNAVAILABLE';
	case BoxLicenseExpired = 'BOX_LICENSE_EXPIRED';
	case ExtensionMissing = 'EXTENSION_MISSING';
	/**
	 * The term of the extension is over, the grace after it is not. The work goes on, and the state exists to be
	 * announced: it is the last window in which a renewal changes nothing but the date.
	 */
	case ExtensionGrace = 'EXTENSION_GRACE';
	case ExtensionExpired = 'EXTENSION_EXPIRED';

	/**
	 * Every phrase key is spelled out in full so that a phrase can be found by a plain text search of its key.
	 * An available state announces nothing, so it has no text of its own; the states of an extension that is not
	 * bought and of a term that is over say everything in one sentence and need no heading over it.
	 */
	public function getTitle(): string
	{
		return match ($this)
		{
			// A term inside the grace carries no phrases at all: it is announced with two dates - the one that has
			// passed and the one the work stops on - and only the view can substitute them.
			self::Available, self::ExtensionMissing, self::ExtensionExpired, self::BoxLicenseExpired,
			self::ExtensionGrace => '',
			self::TariffUnavailable => (string)Loc::getMessage('BIC_SELFHOST_LICENSE_STATE_TARIFF_TITLE'),
		};
	}

	public function getDescription(): string
	{
		return match ($this)
		{
			self::Available, self::ExtensionGrace => '',
			self::TariffUnavailable => (string)Loc::getMessage('BIC_SELFHOST_LICENSE_STATE_TARIFF_DESCRIPTION'),
			self::BoxLicenseExpired => (string)Loc::getMessage('BIC_SELFHOST_LICENSE_STATE_BOX_EXPIRED_DESCRIPTION'),
			self::ExtensionMissing => (string)Loc::getMessage('BIC_SELFHOST_LICENSE_STATE_MISSING_DESCRIPTION'),
			self::ExtensionExpired => (string)Loc::getMessage('BIC_SELFHOST_LICENSE_STATE_EXPIRED_DESCRIPTION'),
		};
	}

	/**
	 * Continuation of the sentence the call to action starts, shown right after the link. Null when the call
	 * to action is a phrase of its own: only the request for the extension reads as one sentence with the link
	 * inside it.
	 */
	public function getActionNote(): ?string
	{
		return match ($this)
		{
			self::ExtensionMissing => (string)Loc::getMessage('BIC_SELFHOST_LICENSE_STATE_MISSING_ACTION_NOTE'),
			default => null,
		};
	}

	/**
	 * Null means the state offers nothing to do.
	 */
	public function getActionText(): ?string
	{
		return match ($this)
		{
			self::Available => null,
			self::TariffUnavailable => (string)Loc::getMessage('BIC_SELFHOST_LICENSE_STATE_TARIFF_ACTION'),
			self::ExtensionGrace => (string)Loc::getMessage('BIC_SELFHOST_LICENSE_STATE_GRACE_ACTION'),
			self::BoxLicenseExpired => (string)Loc::getMessage('BIC_SELFHOST_LICENSE_STATE_BOX_EXPIRED_ACTION'),
			self::ExtensionMissing => (string)Loc::getMessage('BIC_SELFHOST_LICENSE_STATE_MISSING_ACTION'),
			self::ExtensionExpired => (string)Loc::getMessage('BIC_SELFHOST_LICENSE_STATE_EXPIRED_ACTION'),
		};
	}
}
