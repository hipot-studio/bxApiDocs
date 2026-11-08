<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Controller\Sync\ActionFilter;

use Bitrix\Disk\Infrastructure\Rest\V3\Exception\FeatureNotSupportedException;
use Bitrix\Disk\Internal\Service\Sync\SyncFeatureOption;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * Refuses every method of the contour while the portal switch is off.
 *
 * The refusal is explicit rather than an empty answer: a client reading an empty listing would
 * conclude the user has no files and would delete what it already holds.
 */
final class SyncFeatureEnabled extends Base
{
	private SyncFeatureOption $featureOption;

	public function __construct(?SyncFeatureOption $featureOption = null)
	{
		parent::__construct();

		$this->featureOption = $featureOption ?? new SyncFeatureOption();
	}

	/**
	 * @throws FeatureNotSupportedException
	 */
	public function onBeforeAction(Event $event): ?EventResult
	{
		if (!$this->featureOption->isEnabled())
		{
			throw new FeatureNotSupportedException();
		}

		return null;
	}
}
