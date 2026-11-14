<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Tracking;

use Bitrix\Crm\V2\Internal\Entity\Tracking\SourceRefusal;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;
use CRestServer;

/**
 * A change of a source of the end-to-end analytics is refused although its values are valid: the state of the
 * source, of the portal or of a parallel request does not allow it. Nothing is written.
 *
 * Each reason has its own code, so a client tells a source to bring back from the archive from a tariff to pay
 * for or a request to repeat.
 */
final class TrackingSourceRefusedException extends RestException implements SkipWriteToLogException
{
	public const CODE_ARCHIVED = 'CRM_TRACKING_SOURCE_ARCHIVED';
	public const CODE_NOT_CONFIGURABLE = 'CRM_TRACKING_SOURCE_NOT_CONFIGURABLE';
	public const CODE_ACTIVE_EXISTS = 'CRM_TRACKING_SOURCE_ACTIVE_EXISTS';
	public const CODE_REGION_UNAVAILABLE = 'CRM_TRACKING_SOURCE_REGION_UNAVAILABLE';
	public const CODE_OWN_TARIFF_RESTRICTED = 'CRM_TRACKING_SOURCES_OWN_TARIFF_RESTRICTED';
	public const CODE_BUSY = 'CRM_TRACKING_SOURCE_BUSY';

	public function __construct(private readonly SourceRefusal $reason)
	{
		parent::__construct(status: self::statusOf($reason));
	}

	public function getRegistryCode(): string
	{
		return match ($this->reason)
		{
			SourceRefusal::Archived => self::CODE_ARCHIVED,
			SourceRefusal::NotConfigurable => self::CODE_NOT_CONFIGURABLE,
			SourceRefusal::ActiveExists => self::CODE_ACTIVE_EXISTS,
			SourceRefusal::RegionUnavailable => self::CODE_REGION_UNAVAILABLE,
			SourceRefusal::TariffRestricted => self::CODE_OWN_TARIFF_RESTRICTED,
			SourceRefusal::Busy => self::CODE_BUSY,
		};
	}

	protected function getMessagePhraseCode(): string
	{
		return match ($this->reason)
		{
			SourceRefusal::Archived => 'CRM_V2_REST_TRACKING_SOURCE_ARCHIVED',
			SourceRefusal::NotConfigurable => 'CRM_V2_REST_TRACKING_SOURCE_NOT_CONFIGURABLE',
			SourceRefusal::ActiveExists => 'CRM_V2_REST_TRACKING_SOURCE_ACTIVE_EXISTS',
			SourceRefusal::RegionUnavailable => 'CRM_V2_REST_TRACKING_SOURCE_REGION_UNAVAILABLE',
			SourceRefusal::TariffRestricted => 'CRM_V2_REST_TRACKING_SOURCES_OWN_TARIFF_RESTRICTED',
			SourceRefusal::Busy => 'CRM_V2_REST_TRACKING_SOURCE_BUSY',
		};
	}

	private static function statusOf(SourceRefusal $reason): string
	{
		return match ($reason)
		{
			SourceRefusal::Archived, SourceRefusal::ActiveExists, SourceRefusal::Busy => CRestServer::STATUS_CONFLICT,
			SourceRefusal::NotConfigurable => CRestServer::STATUS_WRONG_REQUEST,
			SourceRefusal::RegionUnavailable => CRestServer::STATUS_FORBIDDEN,
			SourceRefusal::TariffRestricted => CRestServer::STATUS_PAYMENT_REQUIRED,
		};
	}
}
