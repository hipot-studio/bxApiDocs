<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Agent;

use Bitrix\Mail\Internal\Service\Avatar\Resource\AvatarCleanupRunner;
use Bitrix\Mail\Internal\Service\Sender\Avatar\SenderAvatarResourceService;
use Bitrix\Main\Application;

final class SenderAvatarCleanupAgent
{
	public static function getName(int $cursor = 0): string
	{
		return sprintf('%s::run(%u);', self::class, max(0, $cursor));
	}

	public static function run(int $cursor = 0): string
	{
		$service = new SenderAvatarResourceService();
		$nextCursor = (new AvatarCleanupRunner(
			static fn(\Throwable $exception) => Application::getInstance()
				->getExceptionHandler()
				->writeToLog($exception),
		))->run($service->findCleanupResourceIds(...), $service->cleanup(...), max(0, $cursor));

		return self::getName($nextCursor);
	}
}
