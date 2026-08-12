<?php

namespace Bitrix\Call\Track;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Call\Track;
use Bitrix\Call\NotifyService;
use Bitrix\Call\CallChatMessage;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Track\Downloader\DownloadAgent;
use Bitrix\Call\Analytics\CloudRecordAnalytics;
use Bitrix\Call\Call\Registry;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message\Send\SendingConfig;
use Bitrix\Im\V2\Service\Context;

/**
 * Unified agent for cloud recording expectation with automatic error/fallback handling
 *
 * This agent replaces PreviewWaitAgent and RecordingWaitAgent.
 * It handles all scenarios:
 * - Recording download failure -> error notification
 * - Preview download failure -> create default preview
 * - Successful download -> already handled by TrackService
 *
 * @internal
 */
class CloudRecordExpectationAgent
{
	/** Initial wait time before first check (4 hours) */
	public const INITIAL_WAIT_TIME = 14400;

	/** Reschedule delay when downloads are still in progress (1 hour) */
	public const RESCHEDULE_DELAY = 3600;

	/** Maximum total wait time - safety limit (12 hours) */
	public const MAX_WAIT_TIME = 43200;

	/**
	 * Main agent callback
	 * @param int $callId Call ID
	 * @param int $startTime Agent start timestamp
	 * @return string Agent name to reschedule or empty string to stop
	 */
	public static function run(int $callId, int $startTime): string
	{
		if (!Loader::includeModule('call') || !Loader::includeModule('im'))
		{
			return '';
		}

		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$log && $logger->info("CloudRecordExpectationAgent::run: CallId: {$callId}, StartTime: {$startTime}");

		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			$log && $logger->error("CloudRecordExpectationAgent::run: call not found CallId: {$callId}");
			return '';
		}

		self::sendTelemetry($call, 'success', 'run');

		// Check safety timeout
		$elapsed = time() - $startTime;
		if ($elapsed > self::MAX_WAIT_TIME)
		{
			$log && $logger->error("CloudRecordExpectationAgent: Max wait time exceeded. CallId: {$callId}");

			self::sendTelemetry($call, 'error', 'timeout', 'max_wait_time_exceeded');

			self::sendErrorToChat($callId);
			return '';
		}

		$records = Track::getTracksForCall($callId, Track::TYPE_VIDEO_RECORD);

		// [1] Record tracks missing?
		if ($records->count() === 0)
		{
			$log && $logger->error("CloudRecordExpectationAgent: Record tracks not found. CallId: {$callId}");

			self::sendTelemetry($call, 'error', 'record_not_found', 'record_not_found');

			self::sendErrorToChat($callId);
			return '';
		}

		// Process each record track individually
		$needsReschedule = false;
		foreach ($records as $record)
		{
			$status = self::processRecordTrack($record, $callId);
			if ($status === 'reschedule')
			{
				$needsReschedule = true;
			}
		}

		// A record may be downloaded (and thus "processed") while its preview is still being
		// downloaded — delivery to chat waits for the preview. Keep the agent alive until the
		// preview download resolves, otherwise nobody falls back to a default preview if the
		// preview download later gives up.
		if (!$needsReschedule && self::hasActiveDownloadAgentsForCall($callId))
		{
			$log && $logger->info("CloudRecordExpectationAgent: Preview still downloading. Rescheduling. CallId: {$callId}");
			$needsReschedule = true;
		}

		if ($needsReschedule)
		{
			$log && $logger->info("CloudRecordExpectationAgent: Some tracks still downloading. Rescheduling. CallId: {$callId}");

			self::sendTelemetry($call, 'success', 'reschedule');

			return self::buildAgentName($callId, $startTime);
		}

		// All tracks processed or failed — cleanup
		$log && $logger->info("CloudRecordExpectationAgent: All tracks processed. CallId: {$callId}");

		self::sendTelemetry($call, 'success', 'completed');

		return '';
	}

	/**
	 * Schedule agent to wait for recording downloads
	 *
	 * @param int $callId Call ID
	 */
	public static function scheduleAgent(int $callId): void
	{
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		if (self::hasScheduledAgent($callId))
		{
			$log && $logger->info("CloudRecordExpectationAgent::scheduleAgent: Already exists. CallId: {$callId}");
			return;
		}

		$startTime = time();
		$agentName = self::buildAgentName($callId, $startTime);

		$log && $logger->info("CloudRecordExpectationAgent::scheduleAgent: Creating agent. CallId: {$callId}");

		$call = Registry::getCallWithId($callId);
		if ($call)
		{
			self::sendTelemetry($call, 'success', 'scheduled');
		}

		\CAgent::AddAgent(
			$agentName,
			'call',
			'N',
			self::RESCHEDULE_DELAY,
			'',
			'Y',
			\ConvertTimeStamp(\time() + \CTimeZone::GetOffset() + self::INITIAL_WAIT_TIME, 'FULL')
		);
	}

	/**
	 * Check if agent is already scheduled for this call
	 *
	 * @param int $callId Call ID
	 * @return bool
	 */
	public static function hasScheduledAgent(int $callId): bool
	{
		$pattern = self::class . "::run({$callId},%";
		$agents = \CAgent::getList([], [
			'MODULE_ID' => 'call',
			'NAME' => $pattern,
		]);
		return (bool)$agents->fetch();
	}

	/**
	 * Remove agent for this call
	 *
	 * @param int $callId Call ID
	 */
	public static function removeAgent(int $callId): void
	{
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$pattern = self::class . "::run({$callId},%";
		$agents = \CAgent::getList([], [
			'MODULE_ID' => 'call',
			'NAME' => $pattern,
		]);

		while ($agent = $agents->fetch())
		{
			\CAgent::RemoveAgent($agent['NAME'], 'call');
			$log && $logger->info("CloudRecordExpectationAgent::removeAgent: Removed. CallId: {$callId}");
		}
	}

	/**
	 * Build agent name with parameters
	 *
	 * @param int $callId Call ID
	 * @param int $startTime Start timestamp
	 * @return string
	 */
	private static function buildAgentName(int $callId, int $startTime): string
	{
		return self::class . "::run({$callId}, {$startTime});";
	}

	/**
	 * Check if download agent exists for a specific track
	 *
	 * @param int $trackId Track ID
	 * @return bool
	 */
	public static function hasDownloadAgentForTrack(int $trackId): bool
	{
		$pattern = DownloadAgent::class . "::run({$trackId},%";
		$agents = \CAgent::getList([], [
			'MODULE_ID' => 'call',
			'NAME' => $pattern,
		]);
		return (bool)$agents->fetch();
	}

	/**
	 * Check if any download agent is active for any cloud recording track of the call
	 *
	 * @param int $callId Call ID
	 * @return bool
	 */
	public static function hasActiveDownloadAgentsForCall(int $callId): bool
	{
		// Select only IDs — the full ORM objects aren't needed, just the agent check below.
		$result = \Bitrix\Call\Model\CallTrackTable::query()
			->setSelect(['ID'])
			->where('CALL_ID', $callId)
			->whereIn('TYPE', [Track::TYPE_VIDEO_RECORD, Track::TYPE_VIDEO_PREVIEW])
			->exec()
		;

		while ($track = $result->fetch())
		{
			if (self::hasDownloadAgentForTrack((int)$track['ID']))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Send error notification to chat.
	 *
	 * If the call has tracks with a non-empty DOWNLOAD_URL but not yet downloaded,
	 * a "Request again" button is attached so the user can restart the cloud download.
	 * Otherwise a fallback "contact support" message is sent.
	 *
	 * @param int $callId Call ID
	 */
	private static function sendErrorToChat(int $callId): void
	{
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			$log && $logger->error("CloudRecordExpectationAgent::sendErrorToChat: Call not found. CallId: {$callId}");
			return;
		}

		$chat = Chat::getInstance($call->getChatId());
		if (!$chat || $chat instanceof \Bitrix\Im\V2\Chat\NullChat)
		{
			$log && $logger->error("CloudRecordExpectationAgent::sendErrorToChat: Chat not found. CallId: {$callId}");
			return;
		}

		if (self::hasRetryableTracks($callId))
		{
			self::sendTelemetry($call, 'error', 'failed_retryable', 'recording_download_failed');
			$message = CallChatMessage::makeCloudRecordRetryableErrorMessage($call, $chat);
		}
		else
		{
			self::sendTelemetry($call, 'error', 'failed', 'recording_download_failed');
			$errorText = Loc::getMessage('CALL_RECORDING_DOWNLOAD_ERROR', ['#CALL_ID#' => $callId]);
			$message = CallChatMessage::makeCloudRecordErrorMessage($call, $chat, $errorText);
		}

		$sendingConfig = (new SendingConfig())
			->enableSkipCounterIncrements()
			->enableSkipUrlIndex()
		;
		$context = (new Context())->setUser($call->getInitiatorId());

		NotifyService::getInstance()->sendMessageDeferred($chat, $message, $sendingConfig, $context);

		$log && $logger->info("CloudRecordExpectationAgent::sendErrorToChat: Sent. CallId: {$callId}");
	}

	/**
	 * Check if call has at least one track with a download URL that has not been downloaded yet
	 *
	 * @param int $callId Call ID
	 * @return bool
	 */
	public static function hasRetryableTracks(int $callId): bool
	{
		foreach ([Track::TYPE_VIDEO_RECORD, Track::TYPE_VIDEO_PREVIEW] as $type)
		{
			foreach (Track::getTracksForCall($callId, $type) as $track)
			{
				if ($track->getDownloadUrl() && !$track->getDownloaded())
				{
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Process a single record track.
	 *
	 * Checks download status per-track and processes downloaded tracks immediately.
	 *
	 * @param Track $record Record track to process
	 * @param int $callId Call ID
	 * @return string 'processed'|'reschedule'|'failed'
	 */
	private static function processRecordTrack(Track $record, int $callId): string
	{
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		// Track not downloaded yet — check per-track download agent
		if (!$record->getDownloaded())
		{
			if (self::hasDownloadAgentForTrack($record->getId()))
			{
				$log && $logger->info("CloudRecordExpectationAgent: Record download in progress. TrackId: {$record->getId()}, CallId: {$callId}");
				return 'reschedule';
			}

			$log && $logger->error("CloudRecordExpectationAgent: Record not downloaded and no download agent. TrackId: {$record->getId()}, CallId: {$callId}");

			$call = Registry::getCallWithId($callId);
			if ($call)
			{
				self::sendTelemetry($call, 'error', 'track_failed', 'track_download_stuck');
			}

			return 'failed';
		}

		// Downloaded but no file — broken track
		if (!$record->getFileId())
		{
			$log && $logger->error("CloudRecordExpectationAgent: Record downloaded but no file. TrackId: {$record->getId()}, CallId: {$callId}");
			return 'failed';
		}

		// Track is downloaded and has a file — process it
		$log && $logger->info("CloudRecordExpectationAgent: Processing record track. TrackId: {$record->getId()}, CallId: {$callId}");

		TrackService::getInstance()->processCloudTrack($record);

		return 'processed';
	}

	private static function sendTelemetry(
		?\Bitrix\Call\Call $call,
		string $status,
		string $event,
		?string $errorCode = null
	): void
	{
		(new CloudRecordAnalytics($call))->sendTelemetry(
			source: null,
			status: $status,
			event: 'cloud_record_expectation_' . $event,
			errorCode: $errorCode
		);
	}
}
