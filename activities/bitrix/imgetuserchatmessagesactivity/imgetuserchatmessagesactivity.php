<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc;
use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main;

use Bitrix\Im\V2\Chat;
use Bitrix\Main\Web\Json;

class CBPImGetUserChatMessagesActivity extends BaseActivity implements IBPConfigurableActivity
{
	protected static $requiredModules = ['im'];

	private const DEFAULT_CHAT_ACTIVITY_BOUNDARY_DAYS = 2;
	private const DEFAULT_MESSAGE_ACTIVITY_BOUNDARY_DAYS = 5;

	private const MAX_MESSAGE_COUNT_LIMIT = 100;
	private const MAX_CHAT_COUNT_LIMIT = 50;

	private const PARAM_TARGET_USER = 'TargetUser';
	private const PARAM_RECENT_CHAT_SELECTION_FROM_DATE = 'RecentChatSelectionFromDate';
	private const PARAM_CHAT_MESSAGE_SELECTION_FROM_DATE = 'ChatMessageSelectionFromDate';
	private const PARAM_CHAT_COUNT_LIMIT = 'ChatCountLimit';
	private const PARAM_MESSAGE_COUNT_LIMIT = 'MessageCountLimit';

	private const RETURN_PARAM_MESSAGE_COLLECTION_JSON = 'MessageCollectionJson';
	private const RETURN_PARAM_CHAT_COUNT = 'ChatCount';
	private const RETURN_PARAM_ERROR_MESSAGE = 'ErrorMessage';

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			self::PARAM_TARGET_USER => '',
			self::PARAM_RECENT_CHAT_SELECTION_FROM_DATE => '',
			self::PARAM_CHAT_COUNT_LIMIT => '',
			self::PARAM_CHAT_MESSAGE_SELECTION_FROM_DATE => '',
			self::PARAM_MESSAGE_COUNT_LIMIT => '',

			self::RETURN_PARAM_MESSAGE_COLLECTION_JSON => '',
			self::RETURN_PARAM_CHAT_COUNT => '',
			self::RETURN_PARAM_ERROR_MESSAGE => '',
		];

		$this->setPropertiesTypes([
			self::RETURN_PARAM_MESSAGE_COLLECTION_JSON => [
				'Type' => Bizproc\FieldType::JSON,
			],
			self::RETURN_PARAM_CHAT_COUNT => [
				'Type' => Bizproc\FieldType::INT,
			],
			self::RETURN_PARAM_ERROR_MESSAGE => [
				'Type' => Bizproc\FieldType::STRING,
			],
		]);
	}

	public function internalExecute(): Main\ErrorCollection
	{
		$userId = CBPHelper::extractFirstUser($this->{self::PARAM_TARGET_USER}, $this->getDocumentId());
		if (!$userId)
		{
			return self::makeCommonErrorCollection();
		}

		$recentChatsSince = CBPHelper::makeTimestamp($this->{self::PARAM_RECENT_CHAT_SELECTION_FROM_DATE});
		$messagesSince = CBPHelper::makeTimestamp($this->{self::PARAM_CHAT_MESSAGE_SELECTION_FROM_DATE});
		if (!$recentChatsSince || !$messagesSince)
		{
			return self::makeCommonErrorCollection();
		}

		$recentChatsSince = DateTime::createFromTimestamp($recentChatsSince);
		$messagesSince = DateTime::createFromTimestamp($messagesSince);

		[
			'chats' => $chatMessageItems,
			'authorIds' => $authorIdsSet,
			'chatIds' => $chatIdsSet,
		] = $this->getUserRelatedChatMessages(
			$userId,
			$recentChatsSince,
			$messagesSince,
		);

		$chatMessageItems = $this->hydrateChatMessageItemsWithUserData($chatMessageItems, $authorIdsSet);
		$chatMessageItems = $this->hydrateChatMessageItemsWithChatData($chatMessageItems, $chatIdsSet);

		try
		{
			$this->setProperty(
				self::RETURN_PARAM_MESSAGE_COLLECTION_JSON,
				$this->prepareResult($userId, $recentChatsSince, $chatMessageItems),
			);
			$this->setProperty(
				self::RETURN_PARAM_CHAT_COUNT,
				count($chatIdsSet),
			);
		}
		catch (\Exception $e)
		{
			return new Main\ErrorCollection([
				new Main\Error(
					Loc::getMessage('IM_ACTIVITIES_GET_USER_MESSAGES_ACTIVITY_ERROR_MESSAGE_ACQUIRING'),
				),
			]);
		}

		return new Main\ErrorCollection();
	}

	protected function prepareProperties(): void
	{
		parent::prepareProperties();

		if (is_int($this->{self::PARAM_MESSAGE_COUNT_LIMIT}))
		{
			$messageCountLimit = (int)$this->{self::PARAM_MESSAGE_COUNT_LIMIT};

			$this->setProperty(
				self::PARAM_MESSAGE_COUNT_LIMIT,
				max(1, min($messageCountLimit, self::MAX_MESSAGE_COUNT_LIMIT)),
			);
		}

		if (is_int($this->{self::PARAM_CHAT_COUNT_LIMIT}))
		{
			$chatCountLimit = (int)$this->{self::PARAM_CHAT_COUNT_LIMIT};

			$this->setProperty(
				self::PARAM_CHAT_COUNT_LIMIT,
				max(1, min($chatCountLimit, self::MAX_CHAT_COUNT_LIMIT)),
			);
		}

		$this->setProperty(
			self::PARAM_CHAT_MESSAGE_SELECTION_FROM_DATE,
			CBPHelper::makeTimestamp($this->{self::PARAM_CHAT_MESSAGE_SELECTION_FROM_DATE}),
		);
		$this->setProperty(
			self::PARAM_RECENT_CHAT_SELECTION_FROM_DATE,
			CBPHelper::makeTimestamp($this->{self::PARAM_RECENT_CHAT_SELECTION_FROM_DATE}),
		);
	}

	protected function checkProperties(): Main\ErrorCollection
	{
		$errorCollection = parent::checkProperties();

		if (!is_int($this->{self::PARAM_MESSAGE_COUNT_LIMIT}))
		{
			$errorCollection->setError(
				self::makePropertyInvalidValueError(self::PARAM_MESSAGE_COUNT_LIMIT),
			);
		}

		if (!is_int($this->{self::PARAM_CHAT_COUNT_LIMIT}))
		{
			$errorCollection->setError(
				self::makePropertyInvalidValueError(self::PARAM_CHAT_COUNT_LIMIT),
			);
		}

		if (!$this->{self::PARAM_RECENT_CHAT_SELECTION_FROM_DATE})
		{
			$errorCollection->setError(
				self::makePropertyInvalidValueError(self::PARAM_RECENT_CHAT_SELECTION_FROM_DATE),
			);
		}

		if (!$this->{self::PARAM_CHAT_MESSAGE_SELECTION_FROM_DATE})
		{
			$errorCollection->setError(
				self::makePropertyInvalidValueError(self::PARAM_CHAT_MESSAGE_SELECTION_FROM_DATE),
			);
		}

		return $errorCollection;
	}

	/**
	 * @return list<string>
	 */
	private static function getDefaultChatTypeWhitelist(): array
	{
		return [
			Chat::IM_TYPE_CHAT,
			Chat::IM_TYPE_OPEN,
			Chat::IM_TYPE_COLLAB,
		];
	}

	private function prepareResult(
		int $userId,
		DateTime $since,
		array $chatItems,
	)
	{
		return Json::encode(
			[
				'userId' => $userId,
				'since' => $since->format('c'),
				'chats' => $chatItems,
			],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
		);
	}

	/**
	 * @param int $userId
	 * @param Main\Type\DateTime $chatsSince
	 * @param Main\Type\DateTime $messagesSince
	 * @return array{
	 *     chats: array,
	 *     authorIds: list<int>,
	 *     chatIds: list<int>,
	 * }
	 */
	private function getUserRelatedChatMessages(
		int $userId,
		Main\Type\DateTime $chatsSince,
		Main\Type\DateTime $messagesSince,
	): array
	{
		$chats = [];
		/** @var array{int: true} $authorIdsSet */
		$authorIdsSet = [];

		/** @var list<int> $chatIdsSet */
		$chatIdsSet = [];

		$recentChatsForUserRows = \Bitrix\Im\Model\RecentTable::query()
			->setSelect(['ITEM_ID'])
			->where('USER_ID', $userId)
			->whereIn('ITEM_TYPE', self::getDefaultChatTypeWhitelist())
			->where('DATE_UPDATE', '>=', $chatsSince)
			->setOrder(['DATE_UPDATE' => 'DESC'])
			->setLimit($this->{self::PARAM_CHAT_COUNT_LIMIT})
			->fetchAll()
		;

		foreach ($recentChatsForUserRows as $chatRow)
		{
			$chatId = (int)($chatRow['ITEM_ID'] ?? null);
			if ($chatId <= 0)
			{
				continue;
			}

			$chatIdsSet[] = $chatId;
		}

		foreach ($chatIdsSet as $chatId)
		{
			[
				'authorIds' => $messageAuthorIds,
				'messages' => $chatMessages,
			] = $this->fetchMessagesByChatId(
				$chatId,
				$messagesSince,
				$this->{self::PARAM_MESSAGE_COUNT_LIMIT},
			);

			if (empty($chatMessages))
			{
				continue;
			}

			$chats[$chatId] = [
				'chatId' => $chatId,
				'messages' => $chatMessages,
			];

			$authorIdsSet += $messageAuthorIds;
		}

		return [
			'chats' => array_values($chats),
			'authorIds' => array_keys($authorIdsSet),
			'chatIds' => array_keys($chats),
		];
	}

	private function fetchMessagesByChatId(
		int $chatId,
		Main\Type\DateTime $messagesSince,
		int $limit,
	): array
	{
		$messagesRow = \Bitrix\Im\Model\MessageTable::query()
			->setSelect(['CHAT_ID', 'AUTHOR_ID', 'MESSAGE', 'DATE_CREATE'])
			->where('DATE_CREATE', '>=', $messagesSince)
			->where('CHAT_ID', $chatId)
			->setOrder(['DATE_CREATE' => 'DESC'])
			->setLimit($limit)
			->fetchAll()
		;

		$messagesRow = array_reverse($messagesRow);

		$result = [];
		$authorIdsSet = [];
		foreach ($messagesRow as $messageRow)
		{
			if (empty($messageRow['MESSAGE']))
			{
				continue;
			}

			$authorId = (int)$messageRow['AUTHOR_ID'];
			if ($authorId >= 0)
			{
				$authorIdsSet[$authorId] = true;
			}

			$result[] = [
				'authorId' => $authorId,
				'message' => $messageRow['MESSAGE'],
				'date' => $messageRow['DATE_CREATE']?->format('c'),
			];
		}

		return [
			'authorIds' => $authorIdsSet,
			'messages' => $result,
		];
	}

	/**
	 * @param array $chatMessageItems
	 * @param list<int> $authorIdsSet
	 * @return array
	 */
	private function hydrateChatMessageItemsWithUserData(array $chatMessageItems, array $authorIdsSet): array
	{
		if (empty($authorIdsSet))
		{
			return $chatMessageItems;
		}

		$userCollection = \Bitrix\Main\UserTable::query()
			->setSelect([
				'ID',
				'NAME',
				'LAST_NAME',
				'LOGIN',
			])
			->whereIn('ID', $authorIdsSet)
			->exec()
			->fetchCollection()
		;

		$userNamesByIdMap = [];
		foreach ($userCollection as $userModel)
		{
			$userName = \CUser::formatName(
				\CSite::GetNameFormat(),
				[
					'NAME' => $userModel->getName(),
					'LAST_NAME' => $userModel->getLastName(),
					'LOGIN' => $userModel->getLogin(),
				],
			);

			$userId = $userModel->getId();
			$userNamesByIdMap[$userId] = \Bitrix\Im\V2\Message\Text\BbCode\User::build(
				$userId,
				$userName,
			)->compile();
		}

		$result = [];
		foreach ($chatMessageItems as $chatMessageItem)
		{
			$chatMessages = $chatMessageItem['messages'] ?? null;
			if (!is_array($chatMessages))
			{
				$result[] = $chatMessageItem;

				continue;
			}

			$hydratedMessages = [];
			foreach ($chatMessages as $chatMessage)
			{
				$authorId = (int)$chatMessage['authorId'];
				if ($userNamesByIdMap[$authorId] ?? false)
				{
					$chatMessage['author'] = $userNamesByIdMap[$authorId];
				}

				unset(
					$chatMessage['authorId'],
				);

				$hydratedMessages[] = $chatMessage;
			}

			$chatMessageItem['messages'] = $hydratedMessages;

			$result[] = $chatMessageItem;
		}

		return $result;
	}

	/**
	 * @param array $chatMessageItems
	 * @param list<int> $chatIdsSet
	 * @return array
	 */
	private function hydrateChatMessageItemsWithChatData(array $chatMessageItems, array $chatIdsSet): array
	{
		if (empty($chatIdsSet))
		{
			return $chatMessageItems;
		}

		$result = [];

		$chatCollection = \Bitrix\Im\Model\ChatTable::query()
			->setSelect(['ID', 'TITLE'])
			->whereIn('ID', $chatIdsSet)
			->exec()
			->fetchCollection()
		;

		foreach ($chatMessageItems as $chatMessageItem)
		{
			$chatId = (int)($chatMessageItem['chatId'] ?? null);
			if ($chatId < 0)
			{
				unset($chatMessageItem['chatId']);
				$result[] = $chatMessageItem;

				continue;
			}

			$chatModel = $chatCollection->getByPrimary($chatId);
			if ($chatModel)
			{
				$chatMessageItem['chat'] = $chatModel->getTitle();
			}

			unset($chatMessageItem['chatId']);
			$result[] = $chatMessageItem;
		}

		return $result;
	}

	protected function reInitialize()
	{
		parent::reInitialize();

		$this->setProperty(self::RETURN_PARAM_MESSAGE_COLLECTION_JSON);
		$this->setProperty(self::RETURN_PARAM_CHAT_COUNT);
		$this->setProperty(self::RETURN_PARAM_ERROR_MESSAGE);
	}

	protected function logError(string|null $message = '', int $userId = 0): void
	{
		if (!$message)
		{
			return;
		}

		parent::logError($message, $userId);
		$this->setProperty(self::RETURN_PARAM_ERROR_MESSAGE, $message);
	}

	protected static function makeCommonErrorCollection(): Main\ErrorCollection
	{
		return new Main\ErrorCollection([
			new Main\Error(
				Loc::getMessage('IM_ACTIVITIES_GET_USER_MESSAGES_ACTIVITY_ERROR_MESSAGE_ACQUIRING'),
			),
		]);
	}

	private static function makePropertyInvalidValueError(string $propertyName): Main\Error
	{
		return new Main\Error(
			Loc::getMessage(
				'IM_ACTIVITIES_GET_USER_MESSAGES_ACTIVITY_ERROR_INVALID_VALUE',
				['#PROPERTY_NAME#' => self::getPropertyNameFromMap($propertyName) ?? ''],
			),
		);
	}

	protected static function getPropertyNameFromMap(string $propertyName): ?string
	{
		return self::getPropertiesMap([])[$propertyName]['Name'] ?? null;
	}

	public static function getPropertiesDialogMap(?\Bitrix\Bizproc\Activity\PropertiesDialog $dialog = null): array
	{
		return self::getPropertiesMap([]);
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		$defaultChatActivityBoundaryDays = self::DEFAULT_CHAT_ACTIVITY_BOUNDARY_DAYS;
		$defaultMessageActivityBoundaryDays = self::DEFAULT_MESSAGE_ACTIVITY_BOUNDARY_DAYS;

		return [
			self::PARAM_TARGET_USER => [
				'Name' => Loc::getMessage('IM_ACTIVITIES_GET_USER_MESSAGES_ACTIVITY_PROPERTY_TARGET_USER'),
				'FieldName' => 'target_user',
				'Type' => Bizproc\FieldType::USER,
				'Required' => true,
			],
			self::PARAM_RECENT_CHAT_SELECTION_FROM_DATE => [
				'Name' => Loc::getMessage('IM_ACTIVITIES_GET_USER_MESSAGES_ACTIVITY_PROPERTY_RECENT_CHAT_SELECTION_FROM_DATE'),
				'FieldName' => 'recent_chat_selection_from_date',
				'Type' => Bizproc\FieldType::DATE,
				'Required' => true,
				'Default' => "{{=dateadd({=System:Date}, '-${defaultChatActivityBoundaryDays}d')}}",
			],
			self::PARAM_CHAT_COUNT_LIMIT => [
				'Name' => Loc::getMessage('IM_ACTIVITIES_GET_USER_MESSAGES_ACTIVITY_PROPERTY_CHAT_COUNT_LIMIT'),
				'FieldName' => 'chat_count_limit',
				'Type' => Bizproc\FieldType::INT,
				'Required' => true,
				'Default' => 20,
				'AllowSelection' => false,
			],
			self::PARAM_CHAT_MESSAGE_SELECTION_FROM_DATE => [
				'Name' => Loc::getMessage('IM_ACTIVITIES_GET_USER_MESSAGES_ACTIVITY_PROPERTY_CHAT_MESSAGE_SELECTION_FROM_DATE'),
				'FieldName' => 'chat_message_selection_from_date',
				'Type' => Bizproc\FieldType::DATE,
				'Required' => true,
				'Default' => "{{=dateadd({=System:Date}, '-${defaultMessageActivityBoundaryDays}d}')}}",
			],
			self::PARAM_MESSAGE_COUNT_LIMIT => [
				'Name' => Loc::getMessage('IM_ACTIVITIES_GET_USER_MESSAGES_ACTIVITY_PROPERTY_MESSAGE_COUNT_LIMIT'),
				'FieldName' => 'message_count_limit',
				'Type' => Bizproc\FieldType::INT,
				'Required' => true,
				'Default' => 50,
				'AllowSelection' => false,
			],
		];
	}

	public static function validateProperties($testProperties = [], ?\CBPWorkflowTemplateUser $user = null)
	{
		$errors = [];

		$propertiesMap = self::getPropertiesDialogMap();
		if (is_int($testProperties[self::PARAM_CHAT_COUNT_LIMIT] ?? null))
		{
			$chatCountLimit = (int)$testProperties[self::PARAM_CHAT_COUNT_LIMIT];
			if ($chatCountLimit <= 0 || $chatCountLimit > self::MAX_CHAT_COUNT_LIMIT)
			{
				$errors[] = [
					'code' => 'InvalidValue',
					'parameter' => self::PARAM_CHAT_COUNT_LIMIT,
					'message' => Loc::getMessage(
						'IM_ACTIVITIES_GET_USER_MESSAGES_ACTIVITY_ERROR_INVALID_VALUE_IN_RANGE',
						[
							'#PROPERTY_NAME#' => $propertiesMap[self::PARAM_CHAT_COUNT_LIMIT]['Name'] ?? '',
							'#MIN_VALUE#' => 1,
							'#MAX_VALUE#' => self::MAX_CHAT_COUNT_LIMIT,
						],
					),
				];
			}
		}

		if (is_int($testProperties[self::PARAM_CHAT_MESSAGE_SELECTION_FROM_DATE] ?? null))
		{
			$messageCountLimit = (int)$testProperties[self::PARAM_MESSAGE_COUNT_LIMIT];
			if ($messageCountLimit <= 0 || $messageCountLimit > self::MAX_MESSAGE_COUNT_LIMIT)
			{
				$errors[] = [
					'code' => 'InvalidValue',
					'parameter' => self::PARAM_MESSAGE_COUNT_LIMIT,
					'message' => Loc::getMessage(
						'IM_ACTIVITIES_GET_USER_MESSAGES_ACTIVITY_ERROR_INVALID_VALUE_IN_RANGE',
						[
							'#PROPERTY_NAME#' => $propertiesMap[self::PARAM_MESSAGE_COUNT_LIMIT]['Name'] ?? '',
							'#MIN_VALUE#' => 1,
							'#MAX_VALUE#' => self::MAX_MESSAGE_COUNT_LIMIT,
						],
					),
				];
			}
		}

		return array_merge($errors, parent::ValidateProperties($testProperties, $user));
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}
}
