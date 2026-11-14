<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\AI\Context;
use Bitrix\Crm\Badge;
use Bitrix\Crm\Copilot\Pipeline\StepContext;
use Bitrix\Crm\Copilot\Pipeline\TargetResolver;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Integration\AI\Config;
use Bitrix\Crm\Integration\AI\Dto\TranscribeCallRecordingPayload;
use Bitrix\Crm\Integration\AI\Dto\TranscriptSegment;
use Bitrix\Crm\Integration\AI\Enum\SpeakerSource;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\AI\EventHandler;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\Analytics\Builder\AI\AIBaseEvent;
use Bitrix\Crm\Integration\Analytics\Builder\AI\AudioToTextEvent;
use Bitrix\Crm\Integration\StorageType;
use Bitrix\Crm\Integration\VoxImplantManager;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\Ai\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Uri;
use CCrmActivity;
use CCrmOwnerType;
use CFile;

final class TranscribeCallRecording extends AbstractOperation
{
	public const TYPE_ID = 1;
	public const CONTEXT_ID = 'transcribe_call_recording';

	// launch provenance: marks a transcription requested by the repeat-sale gate (step I.5) so these
	// launches can be told apart from transcriptions of other scenarios in the operation-progress log
	public const LAUNCH_SOURCE_REPEAT_SALE = 'repeat_sale';

	private ?string $launchSource = null;

	public const SUPPORTED_TARGET_ENTITY_TYPE_IDS = [
		CCrmOwnerType::Activity,
	];

	public const SUPPORTED_AUDIO_EXTENSIONS = \Bitrix\Crm\Service\Timeline\Config::ALLOWED_AUDIO_EXTENSIONS;

	protected const PAYLOAD_CLASS = TranscribeCallRecordingPayload::class;
	protected const ENGINE_CATEGORY = 'audio';
	protected const ENGINE_CODE = EventHandler::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_AUDIO_CODE;

	public function __construct(
		ItemIdentifier $target,
		private readonly int $storageTypeId,
		private readonly int $storageElementId,
		?int $userId = null,
		?int $parentJobId = null,
	)
	{
		parent::__construct($target, $userId, $parentJobId);
	}

	public function setLaunchSource(?string $launchSource): self
	{
		$this->launchSource = $launchSource;

		return $this;
	}

	protected function logOperationLaunched(string $hash, ?int $parentJobId): void
	{
		parent::logOperationLaunched($hash, $parentJobId);

		if ($this->launchSource === self::LAUNCH_SOURCE_REPEAT_SALE)
		{
			self::logOperationProgress('repeatSaleTranscriptionLaunched', $this->target, $hash, $parentJobId);
		}
	}

	public static function isAccessGranted(int $userId, ItemIdentifier $target): bool
	{
		return parent::isAccessGranted($userId, $target)
			&& CCrmActivity::CheckItemUpdatePermission(
				['ID' => $target->getEntityId()],
				Container::getInstance()->getUserPermissions($userId)->getCrmPermissions(),
			)
		;
	}

	public static function isSuitableTarget(ItemIdentifier $target): bool
	{
		if ($target->getEntityTypeId() === CCrmOwnerType::Activity)
		{
			$activity = Container::getInstance()->getActivityBroker()->getById($target->getEntityId());
			if (
				is_array($activity)
				&& VoxImplantManager::isActivityBelongsToVoximplant($activity)
			)
			{
				return true;
			}
		}

		return false;
	}

	public static function canProceedToNextStep(Result $result, StepContext $context): bool
	{
		if (!$result->isSuccess())
		{
			return false;
		}

		$payload = $result->getPayload();

		return $payload instanceof TranscribeCallRecordingPayload && !empty($payload->transcription);
	}

	protected function getAIPayload(): \Bitrix\Main\Result
	{
		$result = new \Bitrix\Main\Result();

		[$fileUrl, $contentType, $originalFileName] = $this->getFileInfo(
			$this->storageTypeId,
			$this->storageElementId,
		);
		if (!$fileUrl || !$contentType)
		{
			return $result->addError(ErrorCode::getFileNotFoundError());
		}

		$fileExtension = $this->getSupportedFileExtension($originalFileName);

		if ($fileExtension === '')
		{
			return $result->addError(new Error(
				'File is not a supported as a call recording.'
				. ' Allowed extensions are: ' . implode(', ', self::SUPPORTED_AUDIO_EXTENSIONS),
				ErrorCode::FILE_NOT_SUPPORTED,
				['supportedExtensions' => self::SUPPORTED_AUDIO_EXTENSIONS],
			));
		}

		return $result->setData([
			'payload' =>
				(new \Bitrix\AI\Payload\Audio($fileUrl, $fileExtension ?? ''))
					->setMarkers(['type' => $contentType]),
		]);
	}

	private function getSupportedFileExtension(string $originalFileName): ?string
	{
		if (empty($originalFileName))
		{
			return null;
		}

		$fileExtension = GetFileExtension($originalFileName);

		if (!in_array($fileExtension, self::SUPPORTED_AUDIO_EXTENSIONS, true))
		{
			return '';
		}

		return $fileExtension;
	}

	private function getFileInfo(int $storageTypeId, int $fileId): array
	{
		//@codingStandardsIgnoreStart
		if ($fileId <= 0)
		{
			return ['', '', ''];
		}

		$bFileId = null;
		if ($storageTypeId === StorageType::Disk)
		{
			if (Loader::includeModule('disk'))
			{
				$bFileId = \Bitrix\Disk\File::loadById($fileId)?->getFileId();
			}
		}
		elseif ($storageTypeId === StorageType::File)
		{
			$bFileId = $fileId;
		}

		if ($bFileId <= 0)
		{
			return ['', '', ''];
		}

		$file = CFile::GetFileArray($bFileId);
		if (!is_array($file) || empty($file['SRC']) || empty($file['CONTENT_TYPE']))
		{
			return ['', '', ''];
		}
		//@codingStandardsIgnoreEnd

		$uri = new Uri($file['SRC']);
		if (empty($uri->getHost()))
		{
			// it seems that file is stored locally in /upload
			$host = \Bitrix\AI\Config::getValue('public_url') ?: \Bitrix\Main\Engine\UrlManager::getInstance()->getHostUrl();
			$uri = (new Uri($host))->setPath($file['SRC']);
		}

		return [
			(string)$uri,
			(string)$file['CONTENT_TYPE'],
			(string)($file['ORIGINAL_NAME'] ?? null),
		];
	}

	protected function getJobAddFields(): array
	{
		return
			['STORAGE_TYPE_ID' => $this->storageTypeId, 'STORAGE_ELEMENT_ID' => $this->storageElementId]
			+ parent::getJobAddFields()
		;
	}

	protected function getJobUpdateFields(): array
	{
		return
			['STORAGE_TYPE_ID' => $this->storageTypeId, 'STORAGE_ELEMENT_ID' => $this->storageElementId]
			+ parent::getJobUpdateFields()
		;
	}

	protected function getContextLanguageId(): string
	{
		$itemIdentifier = $this->targetResolver->findTarget($this->target->getEntityId());
		if ($itemIdentifier)
		{
			return Config::getLanguageId(
				$this->userId,
				$itemIdentifier->getEntityTypeId(),
				$itemIdentifier->getCategoryId()
			);
		}

		return parent::getContextLanguageId();
	}

	protected static function notifyTimelineAfterSuccessfulLaunch(Result $result): void {}

	protected static function notifyTimelineAfterSuccessfulJobFinish(Result $result): void {}

	protected static function notifyAboutJobError(
		Result $result,
		bool $withSyncBadges = true,
		bool $withSendAnalytics = true,
		?ItemIdentifier $target = null
	): void
	{
		$activityId = $result->getTarget()?->getEntityId();
		// Prefer the clicked entity carried across the async boundary (ERR-002/AC-030). The auto path and
		// legacy single-target callers pass no target and keep resolving the priority Deal/Lead via findTarget.
		// Gating on the resolved target (not findTarget alone) lets a contact-only manual launch get the badge.
		$badgeTarget = $target ?? (new TargetResolver())->findTarget($activityId);
		if ($badgeTarget)
		{
			if ($withSyncBadges)
			{
				Controller::getInstance()->onLaunchError(
					$badgeTarget,
					$activityId,
					[
						'OPERATION_TYPE_ID' => self::TYPE_ID,
						'ENGINE_ID' => self::$engineId,
						'ERRORS' => array_unique($result->getErrorMessages()),
					],
					$result->getUserId(),
				);

				self::syncBadges($activityId, Badge\Type\AiCallFieldsFillingResult::ERROR_PROCESS_VALUE, $badgeTarget);
			}

			self::notifyTimelinesAboutActivityUpdate($activityId);

			if ($withSendAnalytics)
			{
				self::sendCallParsingAnalyticsEvent(
					$result,
					$activityId
				);
			}
		}
	}

	protected static function onAfterSuccessfulJobFinish(Result $result, ?Context $context = null): void
	{
		$activityId = $result->getTarget()?->getEntityId();
		if ($activityId > 0)
		{
			self::notifyTimelinesAboutActivityUpdate($activityId);
		}
	}

	protected static function extractPayloadFromAIResult(\Bitrix\AI\Result $result, EO_Queue $job): Dto
	{
		$transcription = $result->getPrettifiedData();
		$raw = $result->getRawData();
		$transcriptVersion = self::determineTranscriptVersion($raw);
		if ($transcriptVersion === null)
		{
			return new TranscribeCallRecordingPayload(['transcription' => $transcription]);
		}

		$segments = TranscriptSegment::listFromResult($result);

		return new TranscribeCallRecordingPayload([
			'transcription' => $transcription,
			'segments' => $segments === null ? null : array_map(
				static fn(TranscriptSegment $segment): array => [
					'startTime' => $segment->startTime,
					'endTime' => $segment->endTime,
					'text' => $segment->text,
					'speaker' => $segment->speaker,
					'trackId' => $segment->trackId,
				],
				$segments,
			),
			'speakerSource' => SpeakerSource::fromResult($result)?->value,
			'transcriptVersion' => $transcriptVersion,
		]);
	}

	private static function determineTranscriptVersion(mixed $raw): ?int
	{
		return is_array($raw) && array_key_exists('speakerSource', $raw) ? 1 : null;
	}

	protected static function getJobFinishEventBuilder(): AIBaseEvent
	{
		return new AudioToTextEvent();
	}
}
