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
use Bitrix\Call\Analytics\FollowUpAnalytics;
use Bitrix\Im\Call\Registry;
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
 */
class CloudRecordExpectationAgent
{
	/** Initial wait time before first check (3 hours) */
	public const INITIAL_WAIT_TIME = 10800;

	/** Reschedule delay when downloads are still in progress (1 hour) */
	public const RESCHEDULE_DELAY = 3600;

	/** Maximum total wait time - safety limit (12 hours) */
	public const MAX_WAIT_TIME = 43200;

	/**
	 * Main agent callback
	 *
	 * Logic flow:
	 * [1] Record track missing? -> sendErrorToChat() -> STOP
	 * [2] Record track not downloaded?
	 *     - Download agent exists? -> reschedule (1h) -> CONTINUE
	 *     - No agent? -> sendErrorToChat() -> STOP
	 * [3] Preview not downloaded?
	 *     - Download agent exists? -> reschedule (1h) -> CONTINUE
	 *     - No agent? -> delete preview, create default, link, notify -> STOP
	 * [4] All good -> STOP (already processed by TrackService)
	 *
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

		// Telemetry: agent run started
		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			$log && $logger->error("CloudRecordExpectationAgent::run: call not found CallId: {$callId}");
			return '';
		}

		(new FollowUpAnalytics($call))
			->sendTelemetry(
				source: null,
				status: 'success',
				event: 'cloud_record_expectation_run'
			);

		// Check safety timeout
		$elapsed = time() - $startTime;
		if ($elapsed > self::MAX_WAIT_TIME)
		{
			$log && $logger->error("CloudRecordExpectationAgent: Max wait time exceeded. CallId: {$callId}");

			(new FollowUpAnalytics($call))
				->sendTelemetry(
					source: null,
					status: 'error',
					errorCode: 'max_wait_time_exceeded',
					event: 'cloud_record_expectation_timeout'
					);

			self::sendErrorToChat($callId);
			return '';
		}

		// Get tracks
		$record = Track::getTrackForCall($callId, Track::TYPE_VIDEO_RECORD);
		$preview = Track::getTrackForCall($callId, Track::TYPE_VIDEO_PREVIEW);

		// [1] Record track missing?
		if (!$record)
		{
			$log && $logger->error("CloudRecordExpectationAgent: Record track not found. CallId: {$callId}");

			(new FollowUpAnalytics($call))
				->sendTelemetry(
					source: null,
					status: 'success',
					event: 'cloud_record_expectation_record_not_found'
				);

			self::sendErrorToChat($callId);
			return '';
		}

		// [2] Record track not downloaded?
		if (!$record->getDownloaded())
		{
			if (self::hasDownloadAgentForTrack($record->getId()))
			{
				$log && $logger->info("CloudRecordExpectationAgent: Record download in progress. Rescheduling. CallId: {$callId}");
				(new FollowUpAnalytics($call))
					->sendTelemetry(
						source: null,
						status: 'success',
						event: 'cloud_record_expectation_reschedule_' . $record->getId()
					);

				return self::buildAgentName($callId, $startTime);
			}

			$log && $logger->error("CloudRecordExpectationAgent: Record not downloaded and no download agent. CallId: {$callId}");
			(new FollowUpAnalytics($call))
				->sendTelemetry(
					source: null,
					status: 'success',
					event: 'cloud_record_expectation_record_not_downloaded_' . $record->getId()
				);

			self::sendErrorToChat($callId);
			return '';
		}

		// Record is downloaded
		// [3] Preview not downloaded?
		if (
			($preview && !$preview->getDownloaded())
			|| (!$preview && str_starts_with($record->getFileMimeType(), 'video/'))
		)
		{
			$log && $logger->warning("CloudRecordExpectationAgent: Preview failed. Creating default. CallId: {$callId}");
			(new FollowUpAnalytics($call))
				->sendTelemetry(
					source: null,
					status: 'success',
					event: 'cloud_record_expectation_create_default_preview'
				);

			self::createDefaultPreviewAndNotify($callId, $record, $preview);
		}

		(new FollowUpAnalytics($call))
			->sendTelemetry(
				source: null,
				status: 'success',
				event: 'cloud_record_expectation_process_track'
			);
		TrackService::getInstance()->processCloudTrack($record);

		// [4] Both downloaded - should already be processed by TrackService
		$log && $logger->info("CloudRecordExpectationAgent: All tracks downloaded. CallId: {$callId}");

		(new FollowUpAnalytics($call))
			->sendTelemetry(
				source: null,
				status: 'success',
				event: 'cloud_record_expectation_completed'
			);

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
			(new FollowUpAnalytics($call))
				->sendTelemetry(
					source: null,
					status: 'success',
					event: 'cloud_record_expectation_scheduled'
				);
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
	private static function hasDownloadAgentForTrack(int $trackId): bool
	{
		$pattern = DownloadAgent::class . "::run({$trackId},%";
		$agents = \CAgent::getList([], [
			'MODULE_ID' => 'call',
			'NAME' => $pattern,
		]);
		return (bool)$agents->fetch();
	}

	/**
	 * Send error notification to chat
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

		(new FollowUpAnalytics($call))
			->sendTelemetry(
				source: null,
				status: 'error',
				errorCode: 'recording_download_failed',
				event: 'cloud_record_expectation_failed'
			);

		$errorText = Loc::getMessage('CALL_RECORDING_DOWNLOAD_ERROR', ['#CALL_ID#' => $callId]);
		$message = CallChatMessage::makeCloudRecordErrorMessage($call, $chat, $errorText);

		$sendingConfig = (new SendingConfig())
			->enableSkipCounterIncrements()
			->enableSkipUrlIndex()
		;
		$context = (new Context())->setUser($call->getInitiatorId());

		NotifyService::getInstance()->sendMessageDeferred($chat, $message, $sendingConfig, $context);

		$log && $logger->info("CloudRecordExpectationAgent::sendErrorToChat: Sent. CallId: {$callId}");
	}

	/**
	 * Create default preview and send recording ready notification
	 *
	 * @param int $callId Call ID
	 * @param Track $record Record track (must be downloaded)
	 * @param Track|null $preview Existing preview track (will be deleted if exists)
	 */
	private static function createDefaultPreviewAndNotify(int $callId, Track $record, ?Track $preview): void
	{
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			$log && $logger->error("CloudRecordExpectationAgent: Call not found. CallId: {$callId}");
			return;
		}

		// Delete existing failed preview
		if ($preview)
		{
			$preview->delete();
		}

		// Create default preview
		$result = TrackService::getInstance()->createDefaultPreview($callId);
		if (!$result->isSuccess())
		{
			$errorCode = 'default_preview_creation_failed';
			$errors = $result->getErrors();
			if (!empty($errors))
			{
				$errorCode = $errors[0]->getCode();
			}

			$log && $logger->error("CloudRecordExpectationAgent: Could not create default preview. CallId: {$callId}, Error: {$errorCode}. Send record as a file.");

			(new FollowUpAnalytics($call))
				->sendTelemetry(
					source: null,
					status: 'error',
					errorCode: $errorCode,
					event: 'cloud_preview_default_creation_failed'
				);

			// Send record without preview
			NotifyService::getInstance()->sendRecordingReadyMessage($call, $record);
			return;
		}

		$defaultPreview = $result->getData()['track'];
		$log && $logger->info("CloudRecordExpectationAgent: Default preview created. CallId: {$callId}");

		(new FollowUpAnalytics($call))
			->sendTelemetry(
				source: null,
				status: 'success',
				event: 'cloud_preview_default_created'
			);
	}
}
