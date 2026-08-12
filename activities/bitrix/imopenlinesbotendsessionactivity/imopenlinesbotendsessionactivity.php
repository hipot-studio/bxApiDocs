<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Im\Dialog;
use Bitrix\ImOpenLines\Chat;
use Bitrix\ImOpenLines\Session;
use Bitrix\ImOpenLines\V2\Analytics\Bot\EndBotSessionEventContext;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

final class CBPImOpenLinesBotEndSessionActivity extends BaseActivity implements IBPConfigurableActivity
{
	private const PARAM_CHAT_ID = 'chatId';

	public function __construct(string $name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
			self::PARAM_CHAT_ID => null,
		];
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return self::getPropertiesMap([]);
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_CHAT_ID => [
				'Name' => Loc::getMessage('IMOL_BOT_END_SESSION_ACTIVITY_PARAM_CHAT_ID_NAME'),
				'Required' => true,
				'FieldName' => self::PARAM_CHAT_ID,
				'Type' => FieldType::STRING,
			],
		];
	}

	public function execute(): int
	{
		if (
			!$this->checkModuleIncluded('im')
			|| !$this->checkModuleIncluded('imbot')
			|| !$this->checkModuleIncluded('imopenlines')
		)
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$chat = $this->getChat();
		if ($chat === null)
		{
			$this->trackError(Loc::getMessage('IMOL_BOT_END_SESSION_ACTIVITY_ERROR_CHAT_NOT_EXISTS'));

			return CBPActivityExecutionStatus::Closed;
		}

		$context = (new EndBotSessionEventContext())
			->setMode(EndBotSessionEventContext::MODE_MANUAL)
		;

		$isEndSession = $chat->endBotSession($context);
		if (!$isEndSession)
		{
			$this->trackError(Loc::getMessage('IMOL_BOT_END_SESSION_ACTIVITY_ERROR_CANNOT_END_SESSION'));
		}

		$this->syncCrmBadges($chat);

		return CBPActivityExecutionStatus::Closed;
	}

	private function syncCrmBadges(Chat $chat): void
	{
		if (!Loader::includeModule('crm'))
		{
			return;
		}

		if (!method_exists(OpenLine::class, 'syncBadges'))
		{
			return;
		}

		$session = new Session();
		$session->setChat($chat);

		$resultLoadSession = $session->load([
			'USER_CODE' => $chat->getData('ENTITY_ID'),
		]);

		if (!$resultLoadSession)
		{
			return;
		}

		$activityId = (int)($session->getData('CRM_ACTIVITY_ID'));
		if ($activityId <= 0)
		{
			return;
		}

		$activity = CCrmActivity::GetByID($activityId, false);
		if (!is_array($activity))
		{
			return;
		}

		$bindings = CCrmActivity::GetBindings($activityId);
		if (empty($bindings))
		{
			return;
		}

		OpenLine::syncBadges($activityId, $activity, $bindings);
	}

	private function getChat(): ?Chat
	{
		$chatId = (int)Dialog::getChatId((string)$this->{self::PARAM_CHAT_ID});
		if ($chatId <= 0)
		{
			return null;
		}

		$chat = new Chat($chatId);
		if (!$chat->isDataLoaded())
		{
			return null;
		}

		return $chat;
	}

	private function checkModuleIncluded(string $module): bool
	{
		if (!Loader::includeModule($module))
		{
			$errorMessage = Loc::getMessage(
				'IMOL_BOT_END_SESSION_ACTIVITY_ERROR_MODULE_NOT_INCLUDED',
				[
					'#MODULE#' => $module,
				],
			);

			$this->trackError($errorMessage);

			return false;
		}

		return true;
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}
}
