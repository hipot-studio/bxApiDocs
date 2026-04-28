<?php

declare(strict_types=1);

namespace Bitrix\Imbot\V2\Controller;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Entity\User\Data\BotData;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Rest\OutputFilter;
use Bitrix\Im\V2\Rest\RestConvertible;
use Bitrix\Im\V2\Service\Context;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Im\Dialog;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Error;

\Bitrix\Main\Loader::requireModule('im');

abstract class BotController extends BaseController
{
	protected int $botId = 0;
	protected array $botData = [];
	protected ?string $clientId = null;
	private ?Context $botContext = null;
	private ?Context $previousContext = null;

	protected function processBeforeAction(Action $action): bool
	{
		if (in_array($action->getName(), $this->getOwnershipExemptActions(), true))
		{
			return $this->processBeforeExemptAction($action);
		}

		$restServer = $this->resolveRestServer();
		if ($restServer === null)
		{
			$this->addError(new Error('REST server context not available', 'REST_SERVER_NOT_FOUND'));

			return false;
		}

		$this->clientId = $this->resolveClientId($restServer);
		if ($this->clientId === null)
		{
			$this->addError(new Error(
				'Bot token not specified (botToken is required for webhook auth)',
				'BOT_TOKEN_NOT_SPECIFIED',
			));

			return false;
		}

		$this->botId = $this->resolveBotOwnership($action);
		if ($this->botId === 0)
		{
			return false;
		}

		if ($this->shouldInjectBotContext($action))
		{
			$this->botContext = (new Context())->setUserId($this->botId);
			$this->previousContext = Locator::getContext();
			Locator::setContext($this->botContext);
		}

		return true;
	}

	/**
	 * Override to return false for controllers where all actions
	 * operate in application context (Bot CRUD, etc.).
	 */
	protected function shouldInjectBotContext(Action $action): bool
	{
		return true;
	}

	protected function processAfterAction(Action $action, $result): void
	{
		$this->restoreContext();
		parent::processAfterAction($action, $result);
	}

	/**
	 * processAfterAction() is NOT called on Throwable path (controller.php:452).
	 */
	protected function runProcessingThrowable(\Throwable $throwable): void
	{
		$this->restoreContext();
		parent::runProcessingThrowable($throwable);
	}

	private function restoreContext(): void
	{
		if ($this->previousContext !== null)
		{
			Locator::setContext($this->previousContext);
			$this->previousContext = null;
		}
	}

	protected function resolveBotOwnership(Action $action): int
	{
		return $this->resolveBotId($this->clientId);
	}

	protected function resolveBotId(string $clientId): int
	{
		$requestBotId = (int)($this->getRequestParamAny(['botId', 'BOT_ID']) ?? 0);

		if ($requestBotId <= 0)
		{
			$this->addError(new Error('botId is required', 'BOT_ID_REQUIRED'));

			return 0;
		}

		$botData = BotData::getInstance($requestBotId)->toArray();

		if (empty($botData))
		{
			$this->addError(new Error('Bot not found', 'BOT_NOT_FOUND'));

			return 0;
		}

		if ($botData['APP_ID'] !== $clientId)
		{
			$this->addError(new Error('Bot was installed by another rest application', 'BOT_OWNERSHIP_ERROR'));

			return 0;
		}

		$this->botData = $botData;

		return $requestBotId;
	}

	protected function getOwnershipExemptActions(): array
	{
		return [];
	}

	protected function processBeforeExemptAction(Action $action): bool
	{
		return true;
	}

	protected function resolveRestServer(): ?\CRestServer
	{
		foreach ($this->getSourceParametersList() as $list)
		{
			foreach ($list as $parameter)
			{
				if ($parameter instanceof \CRestServer)
				{
					return $parameter;
				}
			}
		}

		return null;
	}

	/**
	 * OAuth: clientId from server context.
	 * Webhook: botToken (primary) or CLIENT_ID (deprecated), prefixed with 'custom'.
	 * REST params are case-preserved — we check both camelCase and UPPER_CASE.
	 */
	protected function resolveClientId(\CRestServer $restServer): ?string
	{
		$clientId = $restServer->getClientId();
		if ($clientId !== null && $clientId !== '')
		{
			return $clientId;
		}

		$botToken = $this->getRequestParamAny(['botToken', 'BOT_TOKEN']);
		if ($botToken !== null && $botToken !== '')
		{
			return self::buildCustomClientId($botToken);
		}

		$requestClientId = $this->getRequestParamAny(['clientId', 'CLIENT_ID']);
		if ($requestClientId !== null && $requestClientId !== '')
		{
			return self::buildCustomClientId($requestClientId);
		}

		return null;
	}

	protected static function buildCustomClientId(string $token): string
	{
		return 'custom' . $token;
	}

	protected function getRequestParamAny(array $keys): ?string
	{
		$sourceList = $this->getSourceParametersList();
		$params = $sourceList[0] ?? [];
		foreach ($keys as $key)
		{
			if (isset($params[$key]) && $params[$key] !== '')
			{
				return (string)$params[$key];
			}
		}

		return null;
	}

	protected function getRequestParam(string $key): ?string
	{
		return $this->getRequestParamAny([$key, mb_strtoupper($key)]);
	}

	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(Chat::class, 'chat', function ($className, string $dialogId) {
				$chatId = Dialog::getChatId($dialogId, $this->botId);
				$chat = Chat::getInstance((int)$chatId);

				return ($this->botId > 0) ? $chat->withContextUser($this->botId) : $chat;
			}),
			new ExactParameter(Chat::class, 'chat', function ($className, int $chatId) {
				$chat = Chat::getInstance($chatId);

				return ($this->botId > 0) ? $chat->withContextUser($this->botId) : $chat;
			}),
			new ExactParameter(Message::class, 'message', function ($className, int $messageId) {
				$message = new Message($messageId);

				return ($this->botId > 0) ? $message->withContextUser($this->botId) : $message;
			}),
			new ExactParameter(MessageCollection::class, 'messages', function ($className, array $messageIds) {
				$collection = new MessageCollection($messageIds);
				if ($this->botId > 0)
				{
					foreach ($collection as $message)
					{
						$message->setContextUser($this->botId);
					}
				}

				return $collection;
			}),
		];
	}

	protected function toRestFormat(RestConvertible ...$entities): array
	{
		$result = parent::toRestFormat(...$entities);

		if ($this->clientId !== null)
		{
			return OutputFilter::filter($result);
		}

		return $result;
	}

	protected function filterOutput(array $output): array
	{
		if ($this->clientId !== null)
		{
			return OutputFilter::filter($output);
		}

		return $output;
	}

	protected static function normalizeBooleanVariable(mixed $value): bool
	{
		if (is_bool($value))
		{
			return $value;
		}

		return in_array(mb_strtoupper((string)$value), ['Y', '1', 'TRUE'], true);
	}

	protected static function normalizeColorCode(string $color): string
	{
		if ($color === '')
		{
			return '';
		}

		$code = mb_strtoupper(preg_replace('/([a-z])([A-Z])/', '$1_$2', $color));
		if (!\Bitrix\Im\Color::isSafeColor($code))
		{
			return '';
		}

		return $code;
	}

	public function getBotId(): int
	{
		return $this->botId;
	}

	protected function getBotUserId(): int
	{
		return $this->botId;
	}

	public function getClientId(): ?string
	{
		return $this->clientId;
	}

	public function getBotContext(): ?Context
	{
		return $this->botContext;
	}

	protected function getBotData(): array
	{
		return $this->botData;
	}
}
