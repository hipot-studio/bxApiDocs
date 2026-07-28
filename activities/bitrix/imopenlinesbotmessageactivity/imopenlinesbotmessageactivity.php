<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\AI\Services\MarkdownToBBCodeTranslationService;
use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Im\Bot;
use Bitrix\Im\Dialog;
use Bitrix\Im\V2\Chat;
use Bitrix\ImBot\Bot\OpenLinesBizprocBot;
use Bitrix\ImBot\Integration\Im\Repository\OpenLinesBotRepository;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

final class CBPImOpenLinesBotMessageActivity extends BaseActivity implements IBPConfigurableActivity
{
	private const PARAM_BOT_ID = 'botId';
	private const PARAM_BOT_CODE = 'botCode';
	private const PARAM_MESSAGE = 'message';
	private const PARAM_CHAT_ID = 'chatId';

	public function __construct(string $name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
			self::PARAM_BOT_ID => null,
			self::PARAM_MESSAGE => null,
			self::PARAM_BOT_CODE => null,
			self::PARAM_CHAT_ID => null,
		];
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return self::getPropertiesMap([]);
	}

	public static function validateProperties($testProperties = [], CBPWorkflowTemplateUser $user = null): array
	{
		$errors = [];

		if (empty($testProperties[self::PARAM_BOT_ID]) && empty($testProperties[self::PARAM_BOT_CODE]))
		{
			$errors[] = [
				'code' => 'NotExist',
				'message' => Loc::getMessage('IMOL_BOT_MESSAGE_ACTIVITY_ERROR_BOT_ID_AND_BOT_CODE_EMPTY'),
			];
		}

		return array_merge($errors, parent::validateProperties($testProperties, $user));
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_BOT_ID => [
				'Name' => Loc::getMessage('IMOL_BOT_MESSAGE_PARAM_BOT_ID_NAME'),
				'FieldName' => self::PARAM_BOT_ID,
				'Type' => FieldType::ENTITYSELECTOR,
				'Options' => self::getOpenlineBotOptions(),
			],
			self::PARAM_BOT_CODE => [
				'Name' => Loc::getMessage('IMOL_BOT_MESSAGE_PARAM_BOT_CODE_NAME'),
				'FieldName' => self::PARAM_BOT_CODE,
				'Type' => FieldType::STRING,
			],
			self::PARAM_CHAT_ID => [
				'Name' => Loc::getMessage('IMOL_BOT_MESSAGE_PARAM_CHAT_ID_NAME'),
				'FieldName' => self::PARAM_CHAT_ID,
				'Type' => FieldType::STRING,
				'Required' => true,
			],
			self::PARAM_MESSAGE => [
				'Name' => Loc::getMessage('IMOL_BOT_MESSAGE_PARAM_MESSAGE_NAME'),
				'FieldName' => self::PARAM_MESSAGE,
				'Type' => FieldType::STRING,
				'Required' => true,
			],
		];
	}

	public function execute(): int
	{
		if (
			!$this->checkModuleIncluded('im')
			|| !$this->checkModuleIncluded('imopenlines')
			|| !$this->checkModuleIncluded('imbot')
		)
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$message = $this->getMessage();
		if ($message === null)
		{
			$this->trackError(Loc::getMessage('IMOL_BOT_MESSAGE_ACTIVITY_ERROR_MESSAGE_EMPTY'));

			return CBPActivityExecutionStatus::Closed;
		}

		$chat = $this->getChat();
		if ($chat === null)
		{
			$this->trackError(Loc::getMessage('IMOL_BOT_MESSAGE_ACTIVITY_ERROR_CHAT_NOT_FOUND'));

			return CBPActivityExecutionStatus::Closed;
		}

		$botId = $this->getBotId() ?? $this->getBotIdByCode();
		if ($botId === null)
		{
			$this->trackError(Loc::getMessage('IMOL_BOT_MESSAGE_ACTIVITY_ERROR_BOT_NOT_FOUND'));

			return CBPActivityExecutionStatus::Closed;
		}

		$isSend = $this->sendMessage($botId, $chat->getId(), $message);
		if (!$isSend)
		{
			$this->trackError(Loc::getMessage('IMOL_BOT_MESSAGE_ACTIVITY_ERROR_CANNOT_SEND_MESSAGE'));
		}

		return CBPActivityExecutionStatus::Closed;
	}

	private function getMessage(): ?string
	{
		$message = (string)$this->{self::PARAM_MESSAGE};
		if (empty($message))
		{
			return null;
		}

		return $message;
	}

	private function getChat(): ?Chat
	{
		$chatId = (int)Dialog::getChatId((string)$this->{self::PARAM_CHAT_ID});
		if ($chatId <= 0)
		{
			return null;
		}

		$chat = Chat::getInstance($chatId);
		if (!$chat->isExist() || $chat->getType() !== Chat::IM_TYPE_OPEN_LINE)
		{
			return null;
		}

		return $chat;
	}

	private function getBotId(): ?int
	{
		$botId = $this->{self::PARAM_BOT_ID};
		if (!is_numeric($botId) || (int)$botId <= 0)
		{
			return null;
		}

		$isBotExists = self::getOpenLineBotRepository()?->isExists((int)$botId) ?? false;
		if (!$isBotExists)
		{
			return null;
		}

		return (int)$botId;
	}

	private function getBotIdByCode(): ?int
	{
		$botCode = (string)$this->{self::PARAM_BOT_CODE};
		if (empty($botCode))
		{
			return null;
		}

		return self::getOpenLineBotRepository()?->getByCode($botCode)?->getBotId();
	}

	private function sendMessage(int $botId, mixed $chatId, string $message): bool
	{
		$botIdentifier = [
			'BOT_ID' => $botId,
		];

		$messageFields = [
			'DIALOG_ID' => 'chat' . $chatId,
			'MESSAGE' => $this->formatMessage($message),
		];

		$messageId = Bot::addMessage($botIdentifier, $messageFields);

		return is_int($messageId);
	}

	private function checkModuleIncluded(string $moduleName): bool
	{
		if (!Loader::includeModule($moduleName))
		{
			$errorMessage = Loc::getMessage(
				'IMOL_BOT_MESSAGE_ACTIVITY_ERROR_MODULE_NOT_INCLUDED',
				[
					'#MODULE#' => $moduleName,
				],
			);

			$this->trackError($errorMessage);

			return false;
		}

		return true;
	}

	private static function getOpenlineBotOptions(): array
	{
		return self::getOpenLineBotRepository()?->getNamesByClassMappedById(OpenLinesBizprocBot::class) ?? [];
	}

	private static function getOpenLineBotRepository(): ?OpenLinesBotRepository
	{
		if (!Loader::includeModule('imbot'))
		{
			return null;
		}

		return ServiceLocator::getInstance()->get(OpenLinesBotRepository::class);
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}

	private function formatMessage(string $message): string
	{
		if (
			!Loader::includeModule('ai')
			|| !class_exists(MarkdownToBBCodeTranslationService::class)
		)
		{
			return $message;
		}

		return ServiceLocator::getInstance()
			->get(MarkdownToBBCodeTranslationService::class)
			?->convert($message)
		;
	}
}
