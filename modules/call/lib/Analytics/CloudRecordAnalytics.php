<?php

namespace Bitrix\Call\Analytics;

use Bitrix\Main\Error;
use Bitrix\Call\Analytics\Event\CloudRecordEvent;
use Bitrix\Call\Call\BitrixCall;
use Bitrix\Call\Call\ConferenceCall;
use Bitrix\Call\Call\PlainCall;
use Bitrix\Call\ControllerClient;
use Bitrix\Call\Track;

/**
 * @internal
 */
class CloudRecordAnalytics extends AbstractAnalytics
{
	public function addDownload(Track $track, ?string $errorCode = null): self
	{
		$this->async(function () use ($track, $errorCode)
		{
			(new CloudRecordEvent('server_download', $this->call))
				->setType($this->getCallType())
				->setStatus($errorCode !== null ? 'error_' . $errorCode : 'success')
				->setP3('recordType_' . $this->getRecordType($track))
				->setP4('recordId_' . $track->getId())
				->send()
			;
		});

		return $this;
	}

	public function addSendMessage(Track $track, ?string $errorCode = null): self
	{
		$this->async(function () use ($track, $errorCode)
		{
			(new CloudRecordEvent('send_message', $this->call))
				->setType($this->getCallType())
				->setStatus($errorCode !== null ? 'error_' . $errorCode : 'success')
				->setP3('recordType_' . $this->getRecordType($track))
				->setP4('recordId_' . $track->getId())
				->send()
			;
		});

		return $this;
	}

	private function getCallType(): string
	{
		return match(true)
		{
			$this->call instanceof ConferenceCall => 'videoconf',
			$this->call instanceof PlainCall => 'private',
			$this->call instanceof BitrixCall => 'group',
			default => 'unknown',
		};
	}

	private function getRecordType(Track $track): string
	{
		return match($track->getType())
		{
			Track::TYPE_VIDEO_RECORD, Track::TYPE_VIDEO_PREVIEW => 'cloud',
			default => 'local',
		};
	}

	/**
	 * Send telemetry data about cloud recording events to callcontroller.
	 * @param Track|null $source Track object for cloud record events or null.
	 * @param string $status Allows 'success' or 'error'.
	 * @param string|null $errorCode
	 * @param string $event Event type identifier.
	 * @param Error|null $error
	 * @return self
	 */
	public function sendTelemetry(
		Track|null $source,
		string $status,
		?string $errorCode = null,
		string $event = 'task_status',
		Error|null $error = null
	): self
	{
		$sourceData = [];
		if ($source instanceof Track)
		{
			$sourceData['trackId'] = $source->getId();
			$sourceData['trackType'] = $source->getType();
			$sourceData['fileId'] = $source->getFileId();
			$sourceData['mimeType'] = $source->getFileMimeType();
			$sourceData['fileName'] = $source->getFileName();
		}

		$this->baseSendTelemetry($sourceData, $status, $errorCode, $event, $error);

		return $this;
	}

	protected function sendTelemetryRequest(array $telemetryData): void
	{
		(new ControllerClient())->sendCloudRecordTelemetry($telemetryData);
	}
}
