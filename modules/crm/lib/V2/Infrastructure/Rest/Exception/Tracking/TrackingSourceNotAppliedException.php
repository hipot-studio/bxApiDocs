<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Exception\Tracking;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;
use CRestServer;

/**
 * The item was written, its tracking source was not.
 *
 * The attribution is assigned after the item is written, outside its transaction, so a refusal at that
 * step leaves the written item behind: an added item exists without the source, an updated one keeps
 * its other changes. The id of the item is part of the answer, so a caller repeats the change of the
 * source instead of the whole add.
 */
final class TrackingSourceNotAppliedException extends RestException implements SkipWriteToLogException
{
	public const CODE = 'CRM_TRACKING_SOURCE_NOT_APPLIED';

	protected const STATUS = CRestServer::STATUS_CONFLICT;

	/**
	 * @param bool $isConflict the attribution of the item was changed by a parallel request
	 */
	public function __construct(
		private readonly int $itemId,
		private readonly bool $isConflict = false,
	)
	{
		parent::__construct();
	}

	public function getItemId(): int
	{
		return $this->itemId;
	}

	public function getRegistryCode(): string
	{
		return self::CODE;
	}

	public function output(?string $responseLanguage = null): array
	{
		$out = parent::output($responseLanguage);
		$out['id'] = $this->itemId;

		return $out;
	}

	protected function getMessagePhraseCode(): string
	{
		return $this->isConflict
			? 'CRM_V2_REST_TRACKING_SOURCE_CONFLICT'
			: 'CRM_V2_REST_TRACKING_SOURCE_NOT_APPLIED'
		;
	}
}
