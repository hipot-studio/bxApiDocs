<?php

namespace Bitrix\BIConnector\Superset\Dashboard;

use Bitrix\BIConnector\Internal\Repository\SupersetDashboardShareRepository;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Type\DateTime;

/**
 * Deletes links that stopped working more than RETENTION_DAYS ago: switched off or expired.
 */
class ShareRetentionAgent
{
	public const RETENTION_DAYS = 30;
	private const BATCH_SIZE = 500;

	public static function run(int $batchSize = self::BATCH_SIZE): string
	{
		/** @var SupersetDashboardShareRepository $repository */
		$repository = ServiceLocator::getInstance()->get('biconnector.repository.share');
		$threshold = (new DateTime())->add('-' . self::RETENTION_DAYS . ' days');

		foreach ($repository->deleteRetired($threshold, $batchSize) as $shareId)
		{
			ShareExpireAgent::remove($shareId);
		}

		return self::class . '::run();';
	}
}
