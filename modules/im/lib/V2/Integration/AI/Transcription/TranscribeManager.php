<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\AI\Transcription;

use Bitrix\AI\Config;
use Bitrix\AI\Context;
use Bitrix\AI\Engine;
use Bitrix\AI\Payload\Audio;
use Bitrix\AI\Tuning\Manager;
use Bitrix\Im\Model\FileTranscriptionTable;
use Bitrix\Im\V2\Analytics\FileAnalytics;
use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Entity\File\FileItem;
use Bitrix\Im\V2\Integration\AI\CopilotError;
use Bitrix\Im\V2\Integration\AI\Transcription\Item\Status;
use Bitrix\Im\V2\Integration\AI\Transcription\Item\TranscribeFileItem;
use Bitrix\Im\V2\Integration\AI\Transcription\Result\TranscribeResult;
use Bitrix\Im\V2\Integration\AI\Restriction;
use Bitrix\Im\V2\Pull\Event\FileTranscriptionEvent;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\Uri;

final class TranscribeManager
{
	use ContextCustomer;

	public const CONTEXT_ID = 'chat_file_transcribe';
	public const MAX_TRANSCRIPTION_CHARS = 20000;
	public const MAX_TRANSCRIBABLE_FILE_SIZE = 26214400;
	private const MODULE_ID = 'im';
	private const LOCK_TIMEOUT = 3;

	private int $fileId;
	private int $diskFileId;
	private int $chatId;
	private int $messageId;

	public function __construct(int $fileId, int $diskFileId, int $chatId, int $messageId)
	{
		$this->fileId = $fileId;
		$this->diskFileId = $diskFileId;
		$this->chatId = $chatId;
		$this->messageId = $messageId;
	}

	public function transcribeFile(): TranscribeResult
	{
		$result = new TranscribeResult($this->createErrorFileItem());

		if (!Loader::includeModule('ai'))
		{
			return $result;
		}

		Application::getConnection()->lock($this->getLockName(), self::LOCK_TIMEOUT);

		try
		{
			$fileTranscription = $this->getFileTranscription();
			if (isset($fileTranscription))
			{
				$this->sendTranscribeEvent($fileTranscription, $this->getContext()->getUserId());
				$result->setFileItem($fileTranscription);

				return $result;
			}

			if ($this->hasPending())
			{
				$fileTranscription = $this->createPendingFileItem();
				$result->setFileItem($fileTranscription);

				return $result;
			}

			TranscribeQueueManager::getInstance()->add($this->fileId, $this->chatId);
		}
		finally
		{
			Application::getConnection()->unlock($this->getLockName());
		}

		$result = $this->sendAiQuery();

		if ($result->isSuccess())
		{
			$result->setFileItem($this->createPendingFileItem());
		}
		else
		{
			TranscribeQueueManager::getInstance()->delete($this->fileId, $this->chatId);
		}

		(new FileAnalytics(Chat::getInstance($this->chatId)))->addStartTranscript($result);

		return $result;
	}

	public function handleTranscriptionResponse(TranscribeResult $transcribeResult): void
	{
		$transcribeFileItem = $transcribeResult->getFileItem();

		Application::getConnection()->lock($this->getLockName(), self::LOCK_TIMEOUT);

		try
		{
			if ($transcribeFileItem->status === Status::Success)
			{
				$this->saveFileTranscription($transcribeFileItem);
			}

			$queueManager = TranscribeQueueManager::getInstance();
			$chatIds = $queueManager->fetchChatIds($transcribeFileItem->fileId);
			$queueManager->delete($transcribeFileItem->fileId);
		}
		catch (\Exception $exception)
		{
			$this->sendTranscribeEvent($this->createErrorFileItem());
			throw $exception;
		}
		finally
		{
			Application::getConnection()->unlock($this->getLockName());
		}

		$this->sendTranscribeEvent($transcribeFileItem, null, $chatIds);
		(new FileAnalytics(Chat::getInstance($this->chatId)))->addFinishTranscript($transcribeResult);
	}

	/**
	 * @param FileItem[] $fileItems
	 * @return TranscribeFileItem[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public static function getCompletedTranscriptions(array $fileItems): array
	{
		$fileIds = [];
		$fileItemsByOriginalId = [];

		foreach ($fileItems as $fileItem)
		{
			if ($fileItem->isTranscribable())
			{
				$originalFileId = $fileItem->getOriginalFileId();
				$fileIds[] = $originalFileId;
				$fileItemsByOriginalId[$originalFileId] = $fileItem;
			}
		}

		if (empty($fileIds))
		{
			return [];
		}

		$query = FileTranscriptionTable::query()
			->setSelect(['FILE_ID', 'TEXT'])
			->whereIn('FILE_ID', $fileIds)
			->fetchAll()
		;

		$transcriptionMap = [];
		foreach ($query as $row)
		{
			if ((string)$row['TEXT'] === '')
			{
				continue;
			}
			$fileId = (int)$row['FILE_ID'];
			$fileItem = $fileItemsByOriginalId[$fileId] ?? null;
			if (!$fileItem)
			{
				continue;
			}
			$transcriptionMap[$fileId] = TranscribeFileItem::create(
				$fileId,
				$fileItem->getDiskFileId(),
				$fileItem->getChatId() ?? 0,
				Status::Success,
				(string)$row['TEXT']
			);
		}

		return $transcriptionMap;
	}

	public function getFileTranscription(): ?TranscribeFileItem
	{
		$query = FileTranscriptionTable::query()
			->setSelect(['TEXT'])
			->where('FILE_ID', $this->fileId)
			->fetch()
		;

		if ($query && !empty($query['TEXT']))
		{
			return $this->createFileItem(Status::Success, $query['TEXT']);
		}

		return null;
	}

	protected function saveFileTranscription(TranscribeFileItem $transcribeFileItem): void
	{
		$fields = [[
			'FILE_ID' => $transcribeFileItem->fileId,
			'TEXT' => $transcribeFileItem->transcriptText
		]];

		FileTranscriptionTable::multiplyInsertWithoutDuplicate(
			$fields,
			['DEADLOCK_SAFE' => true, 'UNIQUE_FIELDS' => ['FILE_ID']]
		);
	}

	protected function hasPending(): bool
	{
		return TranscribeQueueManager::getInstance()->inQueue($this->fileId, $this->chatId);
	}

	protected function sendAiQuery(): TranscribeResult
	{
		$result = new TranscribeResult($this->createErrorFileItem());

		$contextParams = [
			'chatId' => $this->chatId,
			'fileId' => $this->fileId,
			'diskFileId' => $this->diskFileId,
			'messageId' => $this->messageId,
		];

		$fileId = $this->fileId;
		$file = \CFile::GetFileArray($fileId);

		if (empty($file['SRC']))
		{
			return $result;
		}

		$fileUri = new Uri($file['SRC']);
		$fileType = $file['CONTENT_TYPE'] ?? '';

		if (empty($fileUri->getHost()))
		{
			$host = Config::getValue('public_url') ?: UrlManager::getInstance()->getHostUrl();
			$fileUri = (new Uri($host))->setPath($file['SRC']);
		}

		$context = new Context(self::MODULE_ID, self::CONTEXT_ID);
		$context->setParameters($contextParams);

		$engine = $this->getEngine($context);
		if ($engine)
		{
			$markers = ['type' => $fileType];
			if (
				Features::isTranscriptionEmotionsAvailable()
				&& (new Restriction())->isTranscriptionEmotionsActive()
			)
			{
				$markers['detectEmotions'] = true;
			}

			$engine
				->setPayload((new Audio($fileUri->getUri()))->setMarkers($markers))
				->onError(function (Error $processingError) use(&$result) {
					if ($processingError->getCode() === CopilotError::LIMIT_IS_EXCEEDED_BAAS)
					{
						$result->addError(new CopilotError(CopilotError::LIMIT_IS_EXCEEDED_BAAS));
					}
					else
					{
						$result->addError(new CopilotError(CopilotError::TRANSCRIPTION_SERVICE_ERROR));
					}
				})
				->completionsInQueue()
			;
		}

		return $result;
	}

	protected function getEngine(Context $context): ?Engine
	{
		$engineName = (new Manager())->getItem(Restriction::SETTING_TRANSCRIPTION_PROVIDER)?->getValue();
		if (empty($engineName))
		{
			return null;
		}

		return Engine::getByCode((string)$engineName, $context);
	}

	protected function sendTranscribeEvent(
		TranscribeFileItem $transcribeFileItem,
		?int $userId = null,
		?array $chatIds = null
	): void
	{
		if (empty($chatIds))
		{
			$chatIds = [$transcribeFileItem->chatId];
		}

		foreach ($chatIds as $chatId)
		{
			$event = new FileTranscriptionEvent(Chat::getInstance($chatId), $transcribeFileItem, $userId);
			$event->send();
		}
	}

	public function createErrorFileItem(): TranscribeFileItem
	{
		return TranscribeFileItem::createByError(
			$this->fileId,
			$this->diskFileId,
			$this->chatId
		);
	}

	public function createPendingFileItem(): TranscribeFileItem
	{
		return TranscribeFileItem::createByPending(
			$this->fileId,
			$this->diskFileId,
			$this->chatId
		);
	}

	public function createFileItem(Status $status, string $transcriptText): TranscribeFileItem
	{
		return TranscribeFileItem::create(
			$this->fileId,
			$this->diskFileId,
			$this->chatId,
			$status,
			$transcriptText
		);
	}

	protected function getLockName(): string
	{
		return "chat_file_transcribe_{$this->fileId}";
	}
}
