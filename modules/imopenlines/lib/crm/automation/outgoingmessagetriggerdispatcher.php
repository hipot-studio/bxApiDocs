<?php
namespace Bitrix\ImOpenLines\Crm\Automation;

use Bitrix\Im\Text;
use Bitrix\Im\V2\Entity\User\User as UserV2;
use Bitrix\Main\Application;
use Bitrix\Main\Result;
use Bitrix\ImOpenLines\Crm;
use Bitrix\ImOpenLines\MessageParameter;
use Bitrix\ImOpenLines\Session;
use Bitrix\ImOpenLines\SilentMode\PersonalSilentMode;

/**
 * Runs the outgoing CRM trigger for an employee message once a transport has confirmed the delivery
 * to the client channel.
 *
 * The instance keeps the message as it was captured before OnImopenlineMessageSend, so the trigger
 * sees what the employee actually typed, without the operator signature and the transport markup.
 * Every transport branch reports its own success boundary; delivery, session updates and the CRM
 * document runtime stay with their current owners. Nothing here may turn a delivered message into a
 * delivery error, so the whole dispatch is guarded and only diagnosed.
 *
 * The CRM call itself goes through the session's CRM manager, which is injectable via
 * {@see Session::setCrmManager()}; besides the two public boundaries, the environment reads at the
 * bottom are the only overridable points, and the admission policy stays closed.
 */
class OutgoingMessageTriggerDispatcher
{
	private const PLAIN_TEXT_GUARD = 'x';

	public function __construct(
		private readonly int $messageId,
		private readonly array $messageFields,
	)
	{
	}

	/**
	 * Livechat mirrors the message into the client chat, so the mirror message id is the delivery
	 * proof of that branch.
	 */
	public function notifyLiveChatDelivered(int $mirrorMessageId, ?Session $session): void
	{
		if ($mirrorMessageId <= 0)
		{
			return;
		}

		$this->notify($session);
	}

	/**
	 * The external channel counts as delivered once the transport has synchronously succeeded; a
	 * later negative delivery status of the channel revokes nothing.
	 */
	public function notifyExternalDelivered(Result $transportResult, ?Session $session): void
	{
		if (!$transportResult->isSuccess())
		{
			return;
		}

		$this->notify($session);
	}

	private function notify(?Session $session): void
	{
		if (!$session instanceof Session)
		{
			return;
		}

		try
		{
			// a refusal of the policy is its regular outcome, so it leaves without a diagnostic record
			if (!$this->isEligible($session))
			{
				return;
			}

			$this->dispatch($session);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
	}

	/**
	 * Fail-closed CRM context: without an activity of its own and without its bindings the event has
	 * no document to run on. The flags of the session are read before the CRM manager, because getting
	 * the manager loads the crm module by itself.
	 */
	private function dispatch(Session $session): void
	{
		$activityId = (int)$session->getData('CRM_ACTIVITY_ID');

		if ($session->getData('CRM') !== 'Y' || $activityId <= 0)
		{
			return;
		}

		$crmManager = $session->getCrmManager();
		if (!$crmManager->isLoaded())
		{
			return;
		}

		$bindings = $this->getActivityBindings($activityId);
		if (empty($bindings))
		{
			return;
		}

		$crmManager->executeAutomationOutgoingMessageTrigger($bindings, $this->buildInputData($session));
	}

	private function buildInputData(Session $session): array
	{
		$text = $this->getText();

		return [
			'CONFIG_ID' => (int)$session->getData('CONFIG_ID'),
			'CHAT_ID' => (int)$session->getData('CHAT_ID'),
			'USER_ID' => (int)$session->getData('USER_ID'),
			'OPERATOR_ID' => (int)$session->getData('OPERATOR_ID'),
			'initiatorUserId' => $this->getAuthorId(),
			'MESSAGE' => [
				'ID' => $this->messageId,
				'TEXT' => $text,
				'PLAIN_TEXT' => $this->getPlainText($text),
			],
		];
	}

	/**
	 * Text::removeBbCodes() answers with the "message deleted" phrase whenever the cleaned text comes
	 * out empty, and a message written entirely in markup cleans out exactly that way: an empty pair
	 * of tags, or a struck-through text the client does see. Such a phrase in the automation text
	 * would be an outright lie, so the guard keeps it out: appended past every code of the message,
	 * it cannot be reached by a pattern of the cleaner, and whatever the answer holds before it is
	 * the real plain text of the message.
	 */
	private function getPlainText(string $text): string
	{
		if ($text === '')
		{
			return '';
		}

		$guarded = (string)Text::removeBbCodes($text . self::PLAIN_TEXT_GUARD);
		if (!str_ends_with($guarded, self::PLAIN_TEXT_GUARD))
		{
			return '';
		}

		return trim(mb_substr($guarded, 0, -mb_strlen(self::PLAIN_TEXT_GUARD)));
	}

	/**
	 * The single admission policy of the new event. It never affects the delivery: an ineligible
	 * message still travels its usual way, it just produces no automation event.
	 */
	private function isEligible(Session $session): bool
	{
		$authorId = $this->getAuthorId();
		if ($authorId <= 0 || $this->isSessionClient($authorId, $session))
		{
			return false;
		}

		if ($this->isServiceMessage() || !$this->hasClientContent())
		{
			return false;
		}

		if (PersonalSilentMode::isEnabled($authorId, $this->getChatId()))
		{
			return false;
		}

		return !$this->isConnectorOrBotAuthor($authorId);
	}

	/**
	 * A portal employee writing to the line as a livechat visitor keeps their own id, so neither
	 * isConnector() nor isBot() tells that author apart from an operator; the client of the session
	 * never authors an outgoing message.
	 */
	private function isSessionClient(int $authorId, Session $session): bool
	{
		return $authorId === (int)$session->getData('USER_ID');
	}

	/**
	 * IMPORTANT_CONNECTOR keeps such a message on its way to the client, but never makes it an
	 * employee message for the automation.
	 *
	 * The "Message to the open line" robot sends twice, and the two sends are marked differently: its
	 * text comes with NO_SESSION_OL, while its attachments go through \CIMDisk::UploadFileFromDisk(),
	 * which builds the fields itself and cannot carry that flag, so they come with OL_ROBOT_SEND. Both
	 * marks have to be refused here: an attachment of the robot that started this very event would
	 * otherwise start it again.
	 */
	private function isServiceMessage(): bool
	{
		return $this->isFlagEnabled('SYSTEM')
			|| $this->isFlagEnabled('SILENT_CONNECTOR')
			|| $this->isFlagEnabled('SKIP_CONNECTOR')
			|| $this->isFlagEnabled('NO_SESSION_OL')
			|| $this->isRobotSend()
		;
	}

	private function isRobotSend(): bool
	{
		return ($this->getParams()[MessageParameter::OL_ROBOT_SEND] ?? null) === 'Y';
	}

	/**
	 * A message carrying only a file, an image, a sticker or a link has no text of its own, and an
	 * entry carrying nothing at all is not an employee message to the client. The link is content here
	 * because Connector::onMessageSend() counts it as one and delivers such a message to the client.
	 *
	 * Markup counts as text of its own, so the plain form of the message must not decide this: the
	 * client reads a struck-through [s]...[/s] message, while its plain form drops that content
	 * entirely and would leave the employee without an event.
	 */
	private function hasClientContent(): bool
	{
		if (trim($this->getText()) !== '')
		{
			return true;
		}

		$params = $this->getParams();

		return !empty($this->messageFields['ATTACH'])
			|| !empty($params['ATTACH'])
			|| !empty($this->messageFields['FILES'])
			|| !empty($params['FILE_ID'])
			|| !empty($params['STICKER_PARAMS'])
			|| !empty($params['url'])
		;
	}

	private function isFlagEnabled(string $field): bool
	{
		return ($this->messageFields[$field] ?? null) === 'Y';
	}

	private function getParams(): array
	{
		$params = $this->messageFields['PARAMS'] ?? null;

		return is_array($params) ? $params : [];
	}

	private function getText(): string
	{
		return (string)($this->messageFields['MESSAGE'] ?? '');
	}

	private function getAuthorId(): int
	{
		return (int)($this->messageFields['AUTHOR_ID'] ?? 0);
	}

	private function getChatId(): int
	{
		return (int)($this->messageFields['CHAT_ID'] ?? 0);
	}

	/**
	 * Author classification belongs to the IM user registry; the policy above only combines it. This
	 * is also what keeps AI out: an AI operator or an AI assistant answers under its own bot identity,
	 * and the bizproc robot marks its own sends. Message params tell nothing about AI authorship —
	 * AI_ASSISTANT_MCP_AUTH_ID travels in the message of a human asking the assistant and is set from
	 * the request, so trusting it would let a sender mute the trigger for their own message.
	 */
	protected function isConnectorOrBotAuthor(int $authorId): bool
	{
		$author = UserV2::getInstance($authorId);

		return $author->isConnector() || $author->isBot();
	}

	/**
	 * CRM bindings read, kept apart from the policy that decides whether to read them at all.
	 */
	protected function getActivityBindings(int $activityId): array
	{
		return Crm\Common::getActivityBindingsFormatted($activityId);
	}
}
