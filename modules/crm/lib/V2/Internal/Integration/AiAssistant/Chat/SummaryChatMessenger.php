<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Chat;

use Bitrix\AiAssistant\Core\Im\ImBot;
use Bitrix\AiAssistant\Core\Message\DefaultMessage;
use Bitrix\AiAssistant\Core\Message\ErrorMessage;
use Bitrix\AiAssistant\Core\Message\LimitErrorMessage;
use Bitrix\AiAssistant\Core\Message\StatusMessage;
use Bitrix\AiAssistant\Core\Service\Bot\MessageSender;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\ClientSummaryData;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\V2\Internal\Integration\Im\Chat\MessageLengthLimit;
use Bitrix\Crm\V2\Internal\Integration\Imbot\Bot\CopilotBotRegistry;
use Bitrix\Crm\V2\Internal\Service\AiSummary\SummaryMessageRenderer;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Everything the client summary writes into the copilot chat: the stub the person sees while the
 * summary is being collected, the summary itself and the two refusals - the generation failed and the
 * limit of the portal is spent.
 *
 * The whole of the conversation with `aiassistant` lives here, so the rest of CRM does not know the
 * sender of the bot. The two questions that belong to other modules are asked of their own
 * integrations - the identifier of the copilot bot of {@see CopilotBotRegistry} and the length of a
 * message of {@see MessageLengthLimit} - because one class of the integration layer speaks to one
 * module. The renderer of the message ({@see SummaryMessageRenderer}) stays outside as well: it is a
 * service of CRM and knows nothing of the chat, so the text and the buttons are asked of it here and
 * turned into a message of the bot.
 *
 * Nothing is thrown out of this class. Every method answers whether the message reached the chat and
 * writes the reason of a failure to the log: the callers are the launch of the scenario and the
 * callback of the queue, and for neither of them is an undelivered message a reason to break off what
 * is already done - the summary is saved in the job either way.
 *
 * All four messages are sent with the notification off (AC-006): the person is looking at the chat
 * they have just opened by their own click, and an alert about an answer to their own action is noise.
 *
 * The two messages the callback of the queue sends - the summary and the refusal - are written in the
 * language the caller names, because by then there is no authorized user and the current language is
 * the default of the portal. An empty language is no language: it means the current one.
 *
 * @internal
 */
final class SummaryChatMessenger
{
	private const LOGGER_ID = 'crm.aiSummary.chat';

	private const DIALOG_ID_PREFIX = 'chat';

	private const ERROR_CODE_FAILED = 'CRM_CLIENT_SUMMARY_FAILED';
	private const ERROR_CODE_LIMIT_EXCEEDED = 'CRM_CLIENT_SUMMARY_LIMIT_EXCEEDED';

	public function __construct(
		private ?MessageSender $sender = null,
		private ?LoggerInterface $logger = null,
		private ?CopilotBotRegistry $bots = null,
		private ?MessageLengthLimit $messageLengthLimit = null,
	)
	{
	}

	/**
	 * The stub of the opened chat: a short line of the bot saying the summary is being collected, and
	 * the standard waiting indicator of the copilot beside it (AC-003).
	 *
	 * It is sent as a status message of the copilot on purpose: this is the very line the copilot writes
	 * about itself while it works, and it raises no unread counter of the chat
	 * ({@see \Bitrix\AiAssistant\Core\Im\ImBot::addMessage()} sends it with SKIP_COUNTER_INCREMENTS).
	 * The stub does stay in the chat as an ordinary row afterwards - a message of a group chat is kept
	 * whether it is marked system or not - which is why the text of it is one short line and not an
	 * answer of its own.
	 *
	 * The indicator is a transient event and lives only for the clients connected at that moment, which
	 * is why the visible line is sent as well: a person opening the chat later sees the stub and not an
	 * empty window.
	 */
	public function sendPending(int $chatId): bool
	{
		return $this->deliver($chatId, 'pending', function (int $botId) use ($chatId): bool {
			$message = (new StatusMessage((string)Loc::getMessage('CRM_CLIENT_SUMMARY_CHAT_PENDING')))
				->setNotify(false)
			;

			if ($this->getSender()->sendMessage($chatId, $message, $botId) <= 0)
			{
				return false;
			}

			ImBot::startWriting(self::DIALOG_ID_PREFIX . $chatId, $botId);

			return true;
		});
	}

	/**
	 * The summary itself: an ordinary message of the copilot bot with the three prompt buttons (AC-004).
	 *
	 * Ordinary is the point of it. Such a message lands in the history of the chat as any other, so the
	 * questions that follow are answered with the summary in context (AC-008), and the buttons send
	 * their intents as messages of the person.
	 */
	public function sendSummary(
		int $chatId,
		ItemIdentifier $target,
		ClientSummaryData $summary,
		?string $language = null,
	): bool
	{
		$language = $language ?: null;

		return $this->deliver($chatId, 'summary', function (int $botId) use ($chatId, $target, $summary, $language): bool {
			$message = (new DefaultMessage(
				SummaryMessageRenderer::render($summary, $this->resolveMessageLengthLimit(), $language),
			))->setNotify(false);

			$buttons = SummaryMessageRenderer::hintButtons(
				$target->getEntityTypeId(),
				$target->getEntityId(),
				$language,
			);

			foreach ($buttons as $button)
			{
				$message->addButton(title: $button['title'], text: $button['text'], link: null);
			}

			return $this->getSender()->sendMessage($chatId, $message, $botId) > 0;
		});
	}

	/**
	 * The summary did not come out: the generation failed, the model answered past the contract or the
	 * job never finished. The person is told so in words they can act on and is offered to ask again
	 * (AC-012); what exactly broke stays in the log, and no text of the model is shown (AC-020).
	 *
	 * The retry is offered as a phrase and not as a button: the repeat button of `aiassistant` resends a
	 * message of the user, and there is no message of the user behind a summary - the person asks for it
	 * again with the button of the card.
	 */
	public function sendFailure(int $chatId, ?string $language = null): bool
	{
		$language = $language ?: null;

		return $this->deliver($chatId, 'failure', function (int $botId) use ($chatId, $language): bool {
			$message = new ErrorMessage(
				(string)Loc::getMessage('CRM_CLIENT_SUMMARY_CHAT_ERROR', null, $language),
				self::ERROR_CODE_FAILED,
			);
			$message->setNotify(false);

			return $this->getSender()->sendError($chatId, $message, $botId) > 0;
		});
	}

	/**
	 * The limit of AI requests of the portal is spent (AC-016). It is told apart from the failure above
	 * because the person can do something about it, and what they do is not "try again".
	 *
	 * The support button of `aiassistant` is not put on the message: it opens a dialogue about the
	 * limits of `aiassistant`, while the limit spent here is the one of the AI operations of CRM.
	 */
	public function sendLimitExceeded(int $chatId): bool
	{
		return $this->deliver($chatId, 'limitExceeded', function (int $botId) use ($chatId): bool {
			$message = new LimitErrorMessage(
				(string)Loc::getMessage(
					'CRM_CLIENT_SUMMARY_CHAT_LIMIT_EXCEEDED',
					['#COPILOT_NAME#' => AIManager::getCopilotName()],
				),
				self::ERROR_CODE_LIMIT_EXCEEDED,
			);
			$message->setNotify(false);

			return $this->getSender()->sendError($chatId, $message, $botId) > 0;
		});
	}

	/**
	 * @param \Closure(int): bool $send the identifier of the copilot bot is given to it
	 */
	private function deliver(int $chatId, string $kind, \Closure $send): bool
	{
		if ($chatId <= 0)
		{
			$this->getLogger()->warning('summary chat message has no chat to go to', ['kind' => $kind]);

			return false;
		}

		try
		{
			$isSent = $send($this->getBots()->requireBotId());
		}
		catch (\Throwable $exception)
		{
			$this->getLogger()->error('summary chat message failed', [
				'kind' => $kind,
				'chatId' => $chatId,
				'exceptionClass' => $exception::class,
				'exceptionMessage' => $exception->getMessage(),
			]);

			return false;
		}

		if (!$isSent)
		{
			$this->getLogger()->warning('summary chat message was refused by the chat', [
				'kind' => $kind,
				'chatId' => $chatId,
			]);
		}

		return $isSent;
	}

	private function getBots(): CopilotBotRegistry
	{
		return $this->bots ??= new CopilotBotRegistry();
	}

	/**
	 * @throws \Bitrix\Main\LoaderException the chat engine is not available on this portal
	 */
	private function getSender(): MessageSender
	{
		Loader::requireModule('aiassistant');

		return $this->sender ??= ServiceLocator::getInstance()->get(MessageSender::class);
	}

	/**
	 * The live limit of one message of `im`, which the renderer cuts the summary by (AC-017). The
	 * constant of the renderer is what CRM states for itself, and it stands in for the live value where
	 * the module is not there.
	 */
	private function resolveMessageLengthLimit(): int
	{
		$messageLengthLimit = $this->messageLengthLimit ??= new MessageLengthLimit();

		return $messageLengthLimit->characters(SummaryMessageRenderer::MESSAGE_LENGTH_LIMIT);
	}

	private function getLogger(): LoggerInterface
	{
		return $this->logger ??= (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
	}
}
