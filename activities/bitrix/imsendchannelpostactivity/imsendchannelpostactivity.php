<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Im\Dialog;
use Bitrix\Im\Integration\Bizproc\Splitter;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChannelChat;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserType;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

class CBPImSendChannelPostActivity extends BaseActivity implements IBPConfigurableActivity
{
	protected static $requiredModules = ['im'];

	private const PARAM_AUTHOR_ID = 'AuthorId';
	private const PARAM_CHANNEL_ID = 'ChannelId';
	private const PARAM_POST_TEXT = 'PostText';
	private const PARAM_POST_FILES = 'PostFiles';

	private const RETURN_PARAM_POST_COLLECTION_JSON = 'PostCollectionJson';
	private const RETURN_PARAM_OPERATION_STATUS = 'OperationStatus';
	private const RETURN_PARAM_ERROR_TEXT = 'ErrorText';

	private const STATUS_SUCCESS = 'success';
	private const STATUS_PARTIAL = 'partial';
	private const STATUS_ERROR = 'error';

	private const POST_STATUS_PUBLISHED = 'published';
	private const EMPTY_POST_COLLECTION_JSON = '[]';
	private const MAX_FILE_COUNT = 100;
	private const MAX_TEXT_PART_COUNT = 20;

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			self::PARAM_AUTHOR_ID => '',
			self::PARAM_CHANNEL_ID => '',
			self::PARAM_POST_TEXT => '',
			self::PARAM_POST_FILES => [],

			self::RETURN_PARAM_POST_COLLECTION_JSON => self::EMPTY_POST_COLLECTION_JSON,
			self::RETURN_PARAM_OPERATION_STATUS => self::STATUS_ERROR,
			self::RETURN_PARAM_ERROR_TEXT => '',
		];

		$this->setPropertiesTypes([
			self::RETURN_PARAM_POST_COLLECTION_JSON => [
				'Type' => FieldType::JSON,
			],
			self::RETURN_PARAM_OPERATION_STATUS => [
				'Type' => FieldType::STRING,
			],
			self::RETURN_PARAM_ERROR_TEXT => [
				'Type' => FieldType::STRING,
			],
		]);
	}

	protected function checkProperties(): ErrorCollection
	{
		$errorCollection = parent::checkProperties();

		if (\CBPHelper::isEmptyValue($this->{self::PARAM_AUTHOR_ID}))
		{
			$errorCollection->setError(self::makeEmptyPropertyError(self::PARAM_AUTHOR_ID));
		}

		if (\CBPHelper::isEmptyValue($this->{self::PARAM_CHANNEL_ID}))
		{
			$errorCollection->setError(self::makeEmptyPropertyError(self::PARAM_CHANNEL_ID));
		}

		if (\CBPHelper::isEmptyValue($this->getPostText()) && $this->getPostFileIds() === [])
		{
			$errorCollection->setError(
				new Error(Loc::getMessage('IM_ACTIVITIES_SEND_CHANNEL_POST_ERROR_EMPTY_CONTENT') ?? ''),
			);
		}

		if (count($this->getPostFileIds()) > self::MAX_FILE_COUNT)
		{
			$errorCollection->setError(
				new Error(
					Loc::getMessage(
						'IM_ACTIVITIES_SEND_CHANNEL_POST_ERROR_TOO_MANY_FILES',
						['#LIMIT#' => self::MAX_FILE_COUNT],
					) ?? '',
				),
			);
		}

		$maxTextLength = self::MAX_TEXT_PART_COUNT * Message::MESSAGE_MAX_LENGTH;
		if (mb_strlen($this->getPostText()) > $maxTextLength)
		{
			$errorCollection->setError(
				new Error(
					Loc::getMessage(
						'IM_ACTIVITIES_SEND_CHANNEL_POST_ERROR_TEXT_TOO_LONG',
						['#LIMIT#' => $maxTextLength],
					) ?? '',
				),
			);
		}

		if (!$errorCollection->isEmpty())
		{
			$this->setResultDefaults();
		}

		return $errorCollection;
	}

	private function setResultDefaults(): void
	{
		$this->setProperty(self::RETURN_PARAM_POST_COLLECTION_JSON, self::EMPTY_POST_COLLECTION_JSON);
		$this->setProperty(self::RETURN_PARAM_OPERATION_STATUS, self::STATUS_ERROR);
	}

	protected function internalExecute(): ErrorCollection
	{
		$errorCollection = new ErrorCollection();

		$this->setResultDefaults();
		$parts = Splitter::split($this->getPostText());
		if (count($parts) > self::MAX_TEXT_PART_COUNT)
		{
			$errorCollection->setError(
				new Error(
					Loc::getMessage(
						'IM_ACTIVITIES_SEND_CHANNEL_POST_ERROR_TOO_MANY_PARTS',
						['#LIMIT#' => self::MAX_TEXT_PART_COUNT],
					) ?? '',
				),
			);

			return $errorCollection;
		}

		$authorId = $this->resolveAuthorId($errorCollection);
		if ($authorId === null)
		{
			return $errorCollection;
		}

		$channel = $this->resolveChannel();
		if ($channel === null)
		{
			$errorCollection->setError(
				new Error(Loc::getMessage('IM_ACTIVITIES_SEND_CHANNEL_POST_ERROR_CHANNEL_NOT_FOUND') ?? ''),
			);

			return $errorCollection;
		}

		return $this->publishPost($authorId, $channel, $parts);
	}

	private function resolveAuthorId(ErrorCollection $errorCollection): ?int
	{
		$author = User::getInstance(
			\CBPHelper::extractFirstUser($this->{self::PARAM_AUTHOR_ID}, $this->getDocumentId()),
		);

		if (!$author->isExist())
		{
			$errorCollection->setError(
				new Error(Loc::getMessage('IM_ACTIVITIES_SEND_CHANNEL_POST_ERROR_AUTHOR_NOT_FOUND') ?? ''),
			);

			return null;
		}

		if (!Loader::includeModule('humanresources'))
		{
			$errorCollection->setError(
				new Error(Loc::getMessage('IM_ACTIVITIES_SEND_CHANNEL_POST_ERROR_HR_UNAVAILABLE') ?? ''),
			);

			return null;
		}

		if (
			!$author->isActive()
			|| $author->getType() !== UserType::USER
			|| !self::isEmployee((int)$author->getId())
		)
		{
			$errorCollection->setError(
				new Error(Loc::getMessage('IM_ACTIVITIES_SEND_CHANNEL_POST_ERROR_AUTHOR_NOT_ALLOWED') ?? ''),
			);

			return null;
		}

		return (int)$author->getId();
	}

	private static function isEmployee(int $userId): bool
	{
		try
		{
			return \Bitrix\HumanResources\Public\Service\Container::getUserDepartmentService()->isEmployee($userId);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	private function resolveChannel(): ?ChannelChat
	{
		$dialogId = $this->extractChannelDialogId();
		if ($dialogId === '')
		{
			return null;
		}

		$chatId = (int)$dialogId > 0 ? (int)$dialogId : (int)Dialog::getChatId($dialogId);
		if ($chatId <= 0)
		{
			return null;
		}

		$chat = Chat::getInstance($chatId);

		return $chat instanceof ChannelChat ? $chat : null;
	}

	private function extractChannelDialogId(): string
	{
		$value = $this->{self::PARAM_CHANNEL_ID};

		if (is_array($value))
		{
			$value = $value['id'] ?? reset($value);
		}

		if (is_array($value))
		{
			$value = $value['id'] ?? null;
		}

		return is_scalar($value) ? trim((string)$value) : '';
	}

	private function publishPost(int $authorId, ChannelChat $channel, array $parts): ErrorCollection
	{
		$errorCollection = new ErrorCollection();

		if (!$channel->withContextUser($authorId)->canDo(Action::Send))
		{
			$errorCollection->setError(
				new Error(Loc::getMessage('IM_ACTIVITIES_SEND_CHANNEL_POST_ERROR_NO_SEND_RIGHT') ?? ''),
			);

			return $errorCollection;
		}

		$chatId = (int)$channel->getId();
		$fileIds = $this->getPostFileIds();
		$partCount = count($parts);
		$automationMarkAttach = self::buildAutomationMarkAttach();

		$posts = [];
		$errorText = null;
		$startIndex = 0;

		if ($fileIds !== [])
		{
			$firstPartText = self::resolveTextPublishedWithFiles($parts[0] ?? '');
			$copiedFileIds = self::copyPostFiles($fileIds);
			$diskFileIds = [];

			if ($copiedFileIds !== [])
			{
				$uploadedFileIds = \CIMDisk::UploadFileFromMain($chatId, $copiedFileIds, $authorId);
				$diskFileIds = is_array($uploadedFileIds) ? $uploadedFileIds : [];
			}

			if (count($diskFileIds) !== count($fileIds))
			{
				$errorText = Loc::getMessage('IM_ACTIVITIES_SEND_CHANNEL_POST_ERROR_FILE_UPLOAD_FAILED') ?? '';
				self::discardUploadedFiles($diskFileIds, $copiedFileIds, $authorId);
			}
			else
			{
				self::resetLastException();

				$uploadOptions = ['USER_ID' => $authorId];
				if ($partCount <= 1)
				{
					$uploadOptions['ATTACH'] = $automationMarkAttach;
				}

				$uploadResult = \CIMDisk::UploadFileFromDisk(
					$chatId,
					array_map(static fn($diskFileId) => 'upload' . $diskFileId, $diskFileIds),
					$firstPartText,
					$uploadOptions,
				);

				$messageId = is_array($uploadResult) ? (int)($uploadResult['MESSAGE_ID'] ?? 0) : 0;
				if ($messageId <= 0)
				{
					$errorText = self::getSendErrorText();
					self::discardUploadedFiles($diskFileIds, $copiedFileIds, $authorId);
				}
				else
				{
					$attachedFileIds = self::resolveAttachedFileIds(
						$fileIds,
						$diskFileIds,
						$uploadResult['DISK_ID'] ?? [],
					);

					$posts[] = self::buildPost($messageId, $firstPartText, $attachedFileIds, $authorId, $channel);
					$startIndex = 1;

					if (count($attachedFileIds) !== count($fileIds))
					{
						$errorText =
							Loc::getMessage('IM_ACTIVITIES_SEND_CHANNEL_POST_ERROR_FILES_PARTIALLY_ATTACHED') ?? ''
						;
					}
				}
			}
		}

		if ($errorText === null)
		{
			for ($index = $startIndex; $index < $partCount; $index++)
			{
				self::resetLastException();

				$messageFields = [
					'TO_CHAT_ID' => $chatId,
					'FROM_USER_ID' => $authorId,
					'MESSAGE_TYPE' => $channel->getType(),
					'MESSAGE' => $parts[$index],
				];

				if ($index === $partCount - 1)
				{
					$messageFields['ATTACH'] = $automationMarkAttach;
				}

				$messageId = (int)\CIMMessenger::Add($messageFields);

				if ($messageId <= 0)
				{
					$errorText = self::getSendErrorText();

					break;
				}

				$posts[] = self::buildPost($messageId, $parts[$index], [], $authorId, $channel);
			}
		}

		$postCollectionJson = self::encodePosts($posts);
		if ($postCollectionJson === null)
		{
			$postCollectionJson = self::EMPTY_POST_COLLECTION_JSON;
			$errorText ??= Loc::getMessage('IM_ACTIVITIES_SEND_CHANNEL_POST_ERROR_RESULT_ENCODE_FAILED') ?? '';
		}

		$status = self::STATUS_SUCCESS;
		if ($errorText !== null)
		{
			$status = $posts === [] ? self::STATUS_ERROR : self::STATUS_PARTIAL;
		}

		$this->setProperty(self::RETURN_PARAM_POST_COLLECTION_JSON, $postCollectionJson);
		$this->setProperty(self::RETURN_PARAM_OPERATION_STATUS, $status);

		if ($status !== self::STATUS_ERROR)
		{
			$this->log(
				Loc::getMessage(
					$status === self::STATUS_SUCCESS
						? 'IM_ACTIVITIES_SEND_CHANNEL_POST_STATUS_SUCCESS'
						: 'IM_ACTIVITIES_SEND_CHANNEL_POST_STATUS_PARTIAL',
					['#COUNT#' => count($posts)],
				) ?? '',
				$authorId,
				\CBPTrackingType::Custom,
			);
		}

		if ($errorText !== null)
		{
			$errorCollection->setError(new Error($errorText));
		}

		return $errorCollection;
	}

	private static function resolveTextPublishedWithFiles(string $text): string
	{
		$text = trim($text);

		return $text === '0' ? '' : $text;
	}

	/**
	 * @param int[] $fileIds
	 * @return int[] empty when at least one file cannot be cloned
	 */
	private static function copyPostFiles(array $fileIds): array
	{
		$copiedFileIds = [];
		foreach ($fileIds as $fileId)
		{
			$copiedFileId = (int)\CFile::CloneFile((int)$fileId);
			if ($copiedFileId <= 0)
			{
				self::deleteFiles($copiedFileIds);

				return [];
			}

			$copiedFileIds[] = $copiedFileId;
		}

		return $copiedFileIds;
	}

	/**
	 * @param int[] $fileIds
	 * @param int[] $diskFileIds
	 * @param int[] $attachedDiskFileIds
	 * @return int[]
	 */
	private static function resolveAttachedFileIds(
		array $fileIds,
		array $diskFileIds,
		array $attachedDiskFileIds
	): array
	{
		$fileIdByDiskFileId = array_combine($diskFileIds, $fileIds);

		return array_values(array_intersect_key($fileIdByDiskFileId, array_flip($attachedDiskFileIds)));
	}

	private static function discardUploadedFiles(array $diskFileIds, array $copiedFileIds, int $authorId): void
	{
		foreach ($diskFileIds as $diskFileId)
		{
			\Bitrix\Disk\File::getById($diskFileId)?->delete($authorId);
		}

		self::deleteFiles($copiedFileIds);
	}

	/**
	 * @param int[] $fileIds
	 */
	private static function deleteFiles(array $fileIds): void
	{
		foreach ($fileIds as $fileId)
		{
			\CFile::Delete($fileId);
		}
	}

	private static function buildPost(
		int $messageId,
		string $text,
		array $fileIds,
		int $authorId,
		ChannelChat $channel
	): array
	{
		$chatId = (int)$channel->getId();

		return [
			'id' => $messageId,
			'url' => self::buildPostUrl($chatId, $messageId),
			'channelId' => $chatId,
			'authorId' => $authorId,
			'text' => $text,
			'files' => $fileIds,
			'createdAt' => (new DateTime())->format('c'),
			'status' => self::POST_STATUS_PUBLISHED,
		];
	}

	private static function buildPostUrl(int $chatId, int $messageId): string
	{
		return UrlManager::getInstance()->getHostUrl()
			. '/online/?IM_DIALOG=chat' . $chatId
			. '&IM_MESSAGE=' . $messageId
		;
	}

	private static function encodePosts(array $posts): ?string
	{
		try
		{
			return Json::encode($posts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private static function resetLastException(): void
	{
		$GLOBALS['APPLICATION']->ResetException();
	}

	private static function getSendErrorText(): string
	{
		$exception = $GLOBALS['APPLICATION']->GetException();
		$exceptionText = $exception ? trim((string)$exception->GetString()) : '';

		return $exceptionText !== ''
			? $exceptionText
			: (Loc::getMessage('IM_ACTIVITIES_SEND_CHANNEL_POST_ERROR_SEND_FAILED') ?? '')
		;
	}

	private static function buildAutomationMarkAttach(): ?\CIMMessageParamAttach
	{
		$markText = Loc::getMessage('IM_ACTIVITIES_SEND_CHANNEL_POST_AUTOMATION_MARK') ?? '';
		if ($markText === '')
		{
			return null;
		}

		$attach = new \CIMMessageParamAttach(null, \CIMMessageParamAttach::TRANSPARENT);
		$attach->AddMessage($markText);
		$attach->SetDescription(\CIMMessageParamAttach::SKIP_MESSAGE);

		return $attach;
	}

	private function getPostText(): string
	{
		$text = $this->{self::PARAM_POST_TEXT};

		return is_scalar($text) ? (string)$text : '';
	}

	private function getPostFileIds(): array
	{
		$fileIds = [];
		foreach (\CBPHelper::makeArrayFlat($this->{self::PARAM_POST_FILES}) as $value)
		{
			$fileId = (int)$value;
			if ($fileId > 0)
			{
				$fileIds[] = $fileId;
			}
		}

		return $fileIds;
	}

	protected function logError(string $message = '', int $userId = 0): void
	{
		parent::logError($message, $userId);

		if ($message !== '' && (string)$this->{self::RETURN_PARAM_ERROR_TEXT} === '')
		{
			$this->setProperty(self::RETURN_PARAM_ERROR_TEXT, $message);
		}
	}

	protected function reInitialize()
	{
		parent::reInitialize();

		$this->setResultDefaults();
		$this->setProperty(self::RETURN_PARAM_ERROR_TEXT, '');
	}

	private static function makeEmptyPropertyError(string $propertyKey): Error
	{
		return new Error(
			Loc::getMessage(
				'IM_ACTIVITIES_SEND_CHANNEL_POST_ERROR_EMPTY_PROPERTY',
				['#PROPERTY#' => self::getPropertiesMap([])[$propertyKey]['Name'] ?? ''],
			) ?? '',
		);
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return self::getPropertiesMap([]);
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_AUTHOR_ID => [
				'Name' => Loc::getMessage('IM_ACTIVITIES_SEND_CHANNEL_POST_FIELD_AUTHOR'),
				'FieldName' => 'author_id',
				'Type' => FieldType::USER,
				'Required' => true,
			],
			self::PARAM_CHANNEL_ID => [
				'Name' => Loc::getMessage('IM_ACTIVITIES_SEND_CHANNEL_POST_FIELD_CHANNEL'),
				'FieldName' => 'channel_id',
				'Type' => FieldType::ENTITYSELECTOR,
				'Required' => true,
				'Settings' => [
					'enableSearch' => true,
					'entity' => [
						'id' => 'im-recent-v2',
						'dynamicLoad' => true,
						'dynamicSearch' => true,
						'searchable' => true,
						'options' => [
							'fillDialogByRecent' => true,
							'parentId' => null,
							'includeOnly' => ['chats'],
							'excludeChatTypes' => [
								Chat::IM_TYPE_PRIVATE,
								Chat::IM_TYPE_CHAT,
								Chat::IM_TYPE_COMMENT,
								Chat::IM_TYPE_OPEN_LINE,
								Chat::IM_TYPE_SYSTEM,
								Chat::IM_TYPE_OPEN,
								Chat::IM_TYPE_COPILOT,
								Chat::IM_TYPE_COLLAB,
								Chat::IM_TYPE_OPEN_COLLAB,
							],
						],
					],
				],
			],
			self::PARAM_POST_TEXT => [
				'Name' => Loc::getMessage('IM_ACTIVITIES_SEND_CHANNEL_POST_FIELD_TEXT'),
				'FieldName' => 'post_text',
				'Type' => FieldType::TEXT,
				'Required' => false,
			],
			self::PARAM_POST_FILES => [
				'Name' => Loc::getMessage('IM_ACTIVITIES_SEND_CHANNEL_POST_FIELD_FILES'),
				'FieldName' => 'post_files',
				'Type' => FieldType::FILE,
				'Required' => false,
				'Multiple' => true,
			],
		];
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}
}
