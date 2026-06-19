<?php

namespace Bitrix\ImBot\Bot;

use Bitrix\Bizproc\Public\Entity\Document\Workflow;
use Bitrix\Bizproc\Starter\Dto\DocumentDto;
use Bitrix\Bizproc\Starter\Enum\Scenario;
use Bitrix\Bizproc\Starter\Starter;
use Bitrix\Im\Bot;
use Bitrix\Im\V2\Message;
use Bitrix\ImBot\Integration\Im\Repository\OpenLinesBotRepository;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Random;

final class OpenLinesBizprocBot extends Base
{
	private static bool $isChatStarted = false;

	/**
	 * @param array{botName: string, botCode?: string} $params
	 * @return int|null
	 */
	public static function register(array $params = []): ?int
	{
		if (!Loader::includeModule('im'))
		{
			return null;
		}

		$botName = $params['botName'] ?? null;
		if (empty($botName))
		{
			return null;
		}

		$botCode = $params['botCode'] ?? null;
		$botCode = empty($botCode)
			? Random::getStringByAlphabet(16, Random::ALPHABET_ALPHALOWER)
			: $botCode;

		return Bot::register([
			'CODE' => $botCode,
			'TYPE' => Bot::TYPE_OPENLINE,
			'MODULE_ID' => 'imbot',
			'CLASS' => self::class,
			'METHOD_MESSAGE_ADD' => 'onMessageAdd',/** @see self::onMessageAdd */
			'METHOD_BOT_DELETE' => 'onBotDelete',/** @see self::onBotDelete */
			'METHOD_WELCOME_MESSAGE' => 'onChatStart',/** @see self::onChatStart */
			'PROPERTIES' => [
				'NAME' => $botName,
			],
		]);
	}

	public static function unRegister(int $botId = 0): bool
	{
		if ($botId <= 0 || !Loader::includeModule('im'))
		{
			return false;
		}

		return Bot::unRegister(
			bot: [
				'BOT_ID' => $botId,
			],
		);
	}

	public static function unRegisterAll(): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		$bots = self::getOpenLinesBotRepository()->getByClass(self::class)?->getAll() ?? [];
		foreach ($bots as $bot)
		{
			Bot::unRegister(
				bot: [
					'BOT_ID' => $bot->getBotId(),
				],
			);
		}
	}

	public static function registerOrUpdate(
		string $botCode,
		string $botName,
	): ?int
	{
		if (!Loader::includeModule('im'))
		{
			return null;
		}

		$bot = self::getOpenLinesBotRepository()->getByCode($botCode);
		if ($bot === null)
		{
			return self::register([
				'botName' => $botName,
				'botCode' => $botCode,
			]);
		}

		$isUpdated = Bot::update(
			bot: [
				'BOT_ID' => $bot->getBotId(),
			],
			updateFields: [
				'PROPERTIES' => [
					'NAME' => $botName,
				],
			],
		);

		if (!$isUpdated)
		{
			return null;
		}

		return $bot->getBotId();
	}

	/**
	 * @param int $messageId
	 * @param array $messageFields
	 *
	 * @return bool
	 * @throws LoaderException
	 */
	public static function onMessageAdd($messageId, $messageFields): bool
	{
		if (!Loader::includeModule('im'))
		{
			return false;
		}

		if (($messageFields['SYSTEM'] ?? null) === 'Y')
		{
			return false;
		}

		$messageFields = (array)$messageFields;
		$messageId = (int)$messageId;
		if ($messageId <= 0)
		{
			return false;
		}

		$botId = (int)($messageFields['BOT_ID'] ?? 0);
		if ($botId <= 0 || !self::getOpenLinesBotRepository()->isExists($botId))
		{
			return false;
		}

		$chatId = (int)($messageFields['TO_CHAT_ID'] ?? $messageFields['CHAT_ID'] ?? 0);
		if ($chatId <= 0)
		{
			return false;
		}

		$message = (new Message())
			->fill([
				'PARAMS' => $messageFields['PARAMS'] ?? [],
			]);

		$preparedMessageFields = [
			'ID' => $messageId,
			...$messageFields,
		];

		$message->load($preparedMessageFields);

		$author = $message->getAuthor();
		if ($author === null || $author->getId() === $botId || !$author->isConnector())
		{
			return false;
		}

		$preparedMessageFields['IS_CHAT_STARTED'] = self::$isChatStarted;

		return self::startNewMessageScenario((string)$messageId, $preparedMessageFields)
			->isSuccess()
		;
	}

	public static function onChatStart($dialogId, $joinFields): bool
	{
		self::$isChatStarted = true;

		return true;
	}

	private static function startNewMessageScenario(string $messageId, array $messageFields): Result
	{
		if (!Loader::includeModule('bizproc'))
		{
			$error = new Error('Module bizproc not installed');

			return (new Result())->addError($error);
		}

		$document = Workflow::getComplexId($messageId);
		$documentType = Workflow::getComplexType();

		$messageFields['DOCUMENT_ID'] = $document;

		return Starter::getByScenario(Scenario::onEvent)
			->addEvent(
				code: 'ImOpenLinesBotNewMessageTrigger',
				documents: [
					new DocumentDto($document, $documentType),
				],
				parameters: $messageFields,
			)
			->start()
		;
	}

	private static function getOpenLinesBotRepository(): OpenLinesBotRepository
	{
		return ServiceLocator::getInstance()->get(OpenLinesBotRepository::class);
	}
}
