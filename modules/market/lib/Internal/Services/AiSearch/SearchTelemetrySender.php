<?php

declare(strict_types=1);

namespace Bitrix\Market\Internal\Services\AiSearch;

use Bitrix\Main\Application;
use Bitrix\Market\Internal\Services\AiSearch\Enum\SearchEventType;
use Bitrix\Market\Rest\Actions;
use Bitrix\Market\Rest\Transport;

final class SearchTelemetrySender
{
	public const EVENT_SOCKET_TIMEOUT = 2;
	public const EVENT_STREAM_TIMEOUT = 3;

	private const APP_CODE_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/D';

	public function send(
		SearchEventType $eventType,
		string $appCode,
		Context $context,
		Visitor $visitor,
		int $position = 0,
	): bool
	{
		if (!$this->isValid($eventType, $appCode, $position))
		{
			return false;
		}

		$params = [
			'event_type' => $eventType->value,
			'payload' => $this->buildPayload($eventType, $appCode, $context, $visitor, $position),
		];

		Application::getInstance()->addBackgroundJob(static function () use ($params): void {
			try
			{
				Transport::instance()->batch(
					[Actions::METHOD_AI_SEARCH_EVENT => [Actions::METHOD_AI_SEARCH_EVENT, $params]],
					self::EVENT_SOCKET_TIMEOUT,
					self::EVENT_STREAM_TIMEOUT,
				);
			}
			catch (\Throwable)
			{
			}
		});

		return true;
	}

	private function isValid(SearchEventType $eventType, string $appCode, int $position): bool
	{
		if (preg_match(self::APP_CODE_PATTERN, $appCode) !== 1)
		{
			return false;
		}

		return $eventType !== SearchEventType::SearchResultClicked || $position >= 1;
	}

	private function buildPayload(
		SearchEventType $eventType,
		string $appCode,
		Context $context,
		Visitor $visitor,
		int $position,
	): array
	{
		$payload = [
			'visitor_id' => $visitor->getVisitorId(),
			'search_session_id' => $visitor->getSearchSessionId(),
			'engine' => $context->getEngine()->value,
		];

		if ($context->getSearchRequestId() !== '')
		{
			$payload['search_request_id'] = $context->getSearchRequestId();
		}

		if ($context->getResultSetId() !== '')
		{
			$payload['result_set_id'] = $context->getResultSetId();
		}

		$payload['app_code'] = $appCode;

		if ($eventType === SearchEventType::SearchResultClicked)
		{
			$payload['position'] = $position;
		}

		return $payload;
	}
}
