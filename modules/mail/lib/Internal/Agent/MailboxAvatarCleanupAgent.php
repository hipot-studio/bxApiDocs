<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Agent;

use Bitrix\Mail\Internal\Service\Avatar\Resource\AvatarCleanupRunner;
use Bitrix\Mail\Internal\Service\Mailbox\Avatar\AvatarResourceService;
use Bitrix\Main\Application;

final class MailboxAvatarCleanupAgent
{
	public static function getName(int $afterId = 0): string
	{
		return sprintf('%s::run(%u);', self::class, max(0, $afterId));
	}

	public static function run(int $afterId = 0): string
	{
		$service = new AvatarResourceService();
		$nextId = self::processBatch(
			$service->findCleanupResourceIds(...),
			$service->cleanup(...),
			static fn(\Throwable $exception) => Application::getInstance()
				->getExceptionHandler()
				->writeToLog($exception),
			max(0, $afterId),
		);

		return self::getName($nextId);
	}

	private static function processBatch(
		callable $loadBatch,
		callable $cleanup,
		callable $logException,
		int $afterId,
	): int
	{
		return (new AvatarCleanupRunner($logException))->run($loadBatch, $cleanup, $afterId);
	}
}
