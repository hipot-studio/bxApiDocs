<?php

namespace Bitrix\Call\Analytics;

use Bitrix\Main\Error;
use Bitrix\Call\ControllerClient;
use Bitrix\Call\Track;

/**
 * Telemetry for track download pipeline (all track types).
 *
 * @internal
 */
class TrackAnalytics extends AbstractAnalytics
{
	/**
	 * @param Track $source Track object (always available in download pipeline).
	 * @param string $status 'success' or 'error'.
	 * @param string|null $errorCode
	 * @param string $event Event type identifier.
	 * @param Error|null $error
	 * @param array $context Extra event-specific payload merged into the telemetry data.
	 * @return self
	 */
	public function sendTelemetry(
		Track $source,
		string $status,
		?string $errorCode = null,
		string $event = 'track_status',
		Error|null $error = null,
		array $context = []
	): self
	{
		$sourceData = [
			'trackId' => $source->getId(),
			'externalTrackId' => $source->getExternalTrackId(),
			'trackType' => $source->getType(),
		];

		if ($context)
		{
			$sourceData += $context;
		}

		$this->baseSendTelemetry($sourceData, $status, $errorCode, $event, $error);

		return $this;
	}

	protected function sendTelemetryRequest(array $telemetryData): void
	{
		(new ControllerClient())->sendTrackTelemetry($telemetryData);
	}
}
