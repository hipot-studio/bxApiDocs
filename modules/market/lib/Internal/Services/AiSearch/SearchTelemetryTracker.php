<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Services\AiSearch;

use Bitrix\Market\Internal\Services\AiSearch\Enum\SearchEventType;

final class SearchTelemetryTracker
{
	public function __construct(private readonly SearchTelemetrySender $telemetrySender)
	{
	}

	public function trackClientEvent(string $eventType, string $appCode, string $searchContext, int $position): bool
	{
		$type = SearchEventType::tryFrom($eventType);
		if ($type === null || !$type->isClient())
		{
			return false;
		}

		$context = Context::fromToken($searchContext);
		if ($context === null)
		{
			return false;
		}

		return $this->telemetrySender->send($type, $appCode, $context, Visitor::createCurrent(), $position);
	}

	public function trackInstallCompleted(string $appCode, array $installResult, string $searchContext): void
	{
		if (empty($installResult['success']))
		{
			return;
		}

		$context = Context::fromToken($searchContext);
		if ($context === null)
		{
			return;
		}

		try
		{
			$this->telemetrySender->send(
				SearchEventType::AppInstallCompleted,
				$appCode,
				$context,
				Visitor::createCurrent(),
			);
		}
		catch (\Throwable)
		{
		}
	}
}
