<?php

namespace Bitrix\Call\Analytics;

use Bitrix\Call\Call;
use Bitrix\Main\Application;
use Bitrix\Main\Error;

/**
 * @internal
 */
abstract class AbstractAnalytics
{
	protected ?Call $call = null;

	public function __construct(?Call $call)
	{
		$this->call = $call;
	}

	protected function async(callable $job): void
	{
		if ($this->call === null)
		{
			return;
		}

		Application::getInstance()->addBackgroundJob($job);
	}

	/**
	 * Build base telemetry payload, merge source-specific data, and send.
	 *
	 * @param array $sourceData Source-specific data (task fields, track fields, etc.)
	 * @param string $status 'success' or 'error'
	 * @param string|null $errorCode
	 * @param string $event Event type identifier
	 * @param Error|null $error
	 * @return static
	 */
	protected function baseSendTelemetry(
		array $sourceData,
		string $status,
		?string $errorCode,
		string $event,
		?Error $error
	): static
	{
		if ($this->call === null)
		{
			return $this;
		}

		$this->async(function () use ($sourceData, $status, $errorCode, $event, $error)
		{
			$telemetry = [
				'callId' => $this->call->getId(),
				'roomId' => $this->call->getUuid(),
				'status' => $status,
				'userId' => $this->call->getInitiatorId() ?: 0,
				'event' => $event,
				'timestamp' => time(),
			];

			$data = $sourceData;
			if ($errorCode !== null)
			{
				$data['errorCode'] = $errorCode;
			}
			if ($error instanceof Error)
			{
				$data['errorCode'] = $error->getCode();
				if (
					$error instanceof \Bitrix\Call\Error
					&& $error->getDescription()
				)
				{
					$data['error'] = $error->getDescription();
				}
				else
				{
					$data['error'] = $error->getMessage();
				}
			}
			$telemetry['data'] = $data;

			$this->sendTelemetryRequest($telemetry);
		});

		return $this;
	}

	protected function sendTelemetryRequest(array $telemetryData): void
	{
	}
}
