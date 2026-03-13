<?php

namespace Bitrix\Call\Track;

use Bitrix\Call;
use Bitrix\Call\Track;
use Bitrix\Call\Call\Registry;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Integration\AI\Task\AITask;
use Bitrix\Call\Analytics\FollowUpAnalytics;
use Bitrix\Main\Result;

final class TrackDeletionService
{
	private static ?TrackDeletionService $service = null;

	private function __construct()
	{}

	public static function getInstance(): self
	{
		if (!self::$service)
		{
			self::$service = new self();
		}
		return self::$service;
	}

	/**
	 * Try to delete AI tracks from mixer if both conditions are met:
	 * - Tracks are downloaded
	 * - All AI tasks are completed
	 */
	public function tryDeleteAiTracksFromMixer(int $callId): void
	{
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		if (!$this->areAiTracksDownloaded($callId))
		{
			$log && $logger->info("tryDeleteAiTracksFromMixer: Tracks not yet downloaded for call #{$callId}");
			return;
		}

		if (!$this->areAllAiTasksCompleted($callId))
		{
			$log && $logger->info("tryDeleteAiTracksFromMixer: AI tasks still pending for call #{$callId}");
			return;
		}

		$log && $logger->info("tryDeleteAiTracksFromMixer: Both conditions met, deleting tracks for call #{$callId}");
		$this->deleteAiTracksFromMixer($callId);
	}

	/**
	 * Delete AI tracks (TYPE_RECORD, TYPE_TRACK_PACK) from mixer
	 */
	public function deleteAiTracksFromMixer(int $callId): void
	{
		$trackTypes = [Track::TYPE_RECORD, Track::TYPE_TRACK_PACK];
		foreach ($trackTypes as $type)
		{
			$track = Track::getTrackForCall($callId, $type);
			if ($track && $track->getExternalTrackId())
			{
				$this->deleteFromMixer($track);
			}
		}
	}

	/**
	 * Delete cloud tracks (TYPE_VIDEO_RECORD, TYPE_VIDEO_PREVIEW) from mixer
	 */
	public function deleteCloudTracksFromMixer(int $callId): void
	{
		$trackTypes = [Track::TYPE_VIDEO_RECORD, Track::TYPE_VIDEO_PREVIEW];
		foreach ($trackTypes as $type)
		{
			$track = Track::getTrackForCall($callId, $type);
			if ($track && $track->getExternalTrackId())
			{
				$this->deleteFromMixer($track);
			}
		}
	}

	/**
	 * Delete track file from mixer after successful download
	 */
	public function deleteFromMixer(Call\Track $track): Result
	{
		$result = new Result();

		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
			$logger->info("Deleting track from mixer. TrackId: {$track->getId()}");
		}

		$call = Registry::getCallWithId($track->getCallId());
		$eventName = implode(
			'_',
			['track_deleted_from_mixer', $track->getId(), $track->getType()]
		);

		$controllerClient = new Call\ControllerClient();
		$dropResult = $controllerClient->dropTrack($track);
		if (!$dropResult->isSuccess())
		{
			$log && $logger->error("Failed to delete from mixer via controller. Error: " . implode('; ', $dropResult->getErrorMessages()));
			$result->addError(new TrackError(TrackError::MIXER_DELETE_ERROR, 'Failed to delete file from mixer'));

			if ($call)
			{
				(new FollowUpAnalytics($call))
					->sendTelemetry(
						source: null,
						status: 'error',
						errorCode: $dropResult->getError(),
						event: $eventName
					);
			}
		}
		else
		{
			$log && $logger->info("Track deleted from mixer successfully. TrackId: {$track->getId()}");

			if ($call)
			{
				(new FollowUpAnalytics($call))
					->sendTelemetry(
						source: null,
						status: 'success',
						event: $eventName
					);
			}
		}

		return $result;
	}

	/**
	 * Check if all AI tasks for call are completed (not pending)
	 */
	protected function areAllAiTasksCompleted(int $callId): bool
	{
		$result = true;

		$tasks = AITask::getTasksForCall($callId);
		foreach ($tasks as $task)
		{
			$result &= $task->isFinished();
		}

		return $result;
	}

	/**
	 * Check if AI tracks are downloaded (have fileId)
	 */
	protected function areAiTracksDownloaded(int $callId): bool
	{
		$result = true;

		$trackTypes = [Track::TYPE_RECORD, Track::TYPE_TRACK_PACK];
		foreach ($trackTypes as $type)
		{
			$track = Track::getTrackForCall($callId, $type);
			if ($track && !$track->getFileId())
			{
				$result = false;
			}
		}

		return $result;
	}
}
