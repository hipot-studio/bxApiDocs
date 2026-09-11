<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\Selfhost\License;

/**
 * Registry of the option names the self-hosted license feature relies on.
 *
 * EXPIRY_DATE lives in the main module and is a read-only incoming contract: the licensing layer is its
 * only writer, and renaming it would silently drop the license on every box. The name is the one the core
 * team gave, in the form the readers of such terms use - the prefix of the update fields is already cut off,
 * the same way the term of the custom servers is read as `~custom_servers_expired_at`. The remaining options
 * belong to biconnector and are written only by this feature.
 */
final class LicenseOption
{
	public const EXPIRY_DATE_MODULE = 'main';

	public const EXPIRY_DATE = '~bi_constructor_expired_at';
	public const DELIVERED_EXPIRY_DATE = '~selfhost_license_delivered_expiry';
	public const DELIVERED_BOX_EXPIRY_DATE = '~selfhost_box_license_delivered_expiry';
	public const DELIVERED_EDITION_VERDICT = '~selfhost_edition_delivered_verdict';
	public const DELIVERY_RETRY_AFTER = '~selfhost_license_delivery_retry_after';
	public const DELIVERY_RETRY_DELAY = '~selfhost_license_delivery_retry_delay';
	public const EXPIRY_WARNING_SHOWN = '~selfhost_license_expiry_warning_shown';
	public const EARLY_WARNING_SHOWN = '~selfhost_license_early_warning_shown';
	public const DATA_REFUSAL_LOGGED = '~selfhost_license_data_refusal_logged';
	public const WORK_STOPPED = '~selfhost_license_work_stopped';
	public const CHECK_DISABLED = 'selfhost_license_check_disabled';
	public const GRACE_DAYS = 'selfhost_license_grace_days';
}
