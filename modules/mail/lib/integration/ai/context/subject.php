<?php

namespace Bitrix\Mail\Integration\AI\Context;

use Bitrix\Mail\MessageAccess;
use Bitrix\Mail\Storage\Message as MessageStorage;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;

Loc::loadMessages(__FILE__);

/**
 * AI context and prompt owner for "generate a short e-mail subject from the body".
 *
 * The mail module owns both the context ids (which the ai Engine uses to pull
 * context messages through the `ai:onContextGetMessages` event) and the prompt
 * text. The body being composed comes from the client (the message is not saved
 * yet) and is the primary source for the subject; for reply/forward the saved
 * original message, when accessible, is supplied as an additional reference.
 *
 * This class is the single owner of the content limits. A request producer must
 * apply them through the public normalizers BEFORE the values reach an ai
 * Context: Context::pack() serializes its parameters into the queue job, so
 * limiting only here would leave the stored job unbounded. Re-applying them at
 * message assembly is intentional defence in depth.
 */
final class Subject
{
	/**
	 * Context is deliberately bounded at its final assembly point. Limits are in
	 * Unicode characters (not bytes): 500 is ample for a user-entered subject,
	 * while 12,000 characters per body retain useful correspondence context
	 * without allowing a compose request to produce an unbounded AI payload.
	 */
	public const CURRENT_SUBJECT_MAX_LENGTH = 500;
	public const COMPOSED_BODY_MAX_LENGTH = 12000;
	public const ORIGINAL_BODY_MAX_LENGTH = 12000;

	public const CONTEXT_MESSAGE = 'mail_subject_message';
	public const CONTEXT_REPLY = 'mail_subject_reply';
	public const CONTEXT_CRM_MESSAGE = 'crm_mail_subject_message';
	public const CONTEXT_CRM_REPLY = 'crm_mail_subject_reply';

	/**
	 * Maps a composition to its context id. Reply and forward both reference an
	 * existing message (whose body is supplied as an additional context), so they
	 * share the reply-like context; a brand-new message uses the plain one.
	 */
	public static function resolveContextId(bool $referencesOriginalMessage, bool $isCrm): string
	{
		if ($isCrm)
		{
			return $referencesOriginalMessage ? self::CONTEXT_CRM_REPLY : self::CONTEXT_CRM_MESSAGE;
		}

		return $referencesOriginalMessage ? self::CONTEXT_REPLY : self::CONTEXT_MESSAGE;
	}

	/**
	 * Whether the given context id belongs to subject generation.
	 */
	public static function isSubjectContext(string $contextId): bool
	{
		return in_array(
			$contextId,
			[
				self::CONTEXT_MESSAGE,
				self::CONTEXT_REPLY,
				self::CONTEXT_CRM_MESSAGE,
				self::CONTEXT_CRM_REPLY,
			],
			true,
		);
	}

	/**
	 * Bounds the body being composed. Producers of a subject-generation request
	 * must call this before putting the value into an ai Context.
	 */
	public static function normalizeComposedBody(mixed $body): string
	{
		return self::normalizeContent($body, self::COMPOSED_BODY_MAX_LENGTH);
	}

	/**
	 * Bounds the current subject the user wants improved. Producers of a
	 * subject-generation request must call this before putting the value into an
	 * ai Context.
	 */
	public static function normalizeCurrentSubject(mixed $subject): string
	{
		return self::normalizeContent($subject, self::CURRENT_SUBJECT_MAX_LENGTH);
	}

	/**
	 * Instruction sent to the model. The subject language is derived from the
	 * body content by the model itself.
	 */
	public static function getPromptText(): string
	{
		return (string)Loc::getMessage('MAIL_INTEGRATION_AI_CONTEXT_SUBJECT_PROMPT');
	}

	/**
	 * Builds the context messages for a subject-generation request.
	 *
	 * @param string $contextId One of the subject context ids.
	 * @param array $params Context parameters (`body`, optional `currentSubject` and `originalMessageId`).
	 * @param int $userId Current user id (used to check access to the original message).
	 * @return array{messages: array<int, array{content: string, is_original_message?: bool}>}
	 */
	public static function buildMessages(string $contextId, array $params, int $userId): array
	{
		if (!self::isSubjectContext($contextId))
		{
			return ['messages' => []];
		}

		$body = self::normalizeComposedBody($params['body'] ?? null);
		if ($body === '')
		{
			return ['messages' => []];
		}

		$messages = [];

		$currentSubject = self::normalizeCurrentSubject($params['currentSubject'] ?? null);
		if ($currentSubject !== '')
		{
			// Keep user-provided material in context rather than interpolating it
			// into the instruction prompt.
			$messages[] = [
				'content' => (string)Loc::getMessage(
					'MAIL_INTEGRATION_AI_CONTEXT_CURRENT_SUBJECT',
					['#SUBJECT#' => $currentSubject],
				),
			];
		}

		$originalMessageId = isset($params['originalMessageId']) ? (int)$params['originalMessageId'] : 0;
		if ($originalMessageId > 0)
		{
			$originalBody = self::normalizeContent(
				self::loadOriginalMessageBody($originalMessageId, $userId),
				self::ORIGINAL_BODY_MAX_LENGTH,
			);
			if ($originalBody !== '')
			{
				$messages[] = [
					'content' => $originalBody,
					'is_original_message' => true,
				];
			}
		}

		$messages[] = [
			'content' => $body,
		];

		return ['messages' => $messages];
	}

	/**
	 * Loads the body of the saved original message if the user may access it.
	 */
	private static function loadOriginalMessageBody(int $messageId, int $userId): string
	{
		try
		{
			$message = (new MessageStorage())->getMessage($messageId);
		}
		catch (SystemException)
		{
			return '';
		}

		if (!MessageAccess::createForMessage($message, $userId)->isOwner())
		{
			return '';
		}

		return $message->getBody();
	}

	/**
	 * Normalizes user/message content and truncates it without splitting a
	 * multibyte character. All supported Bitrix installations expose mbstring.
	 */
	private static function normalizeContent(mixed $content, int $maxLength): string
	{
		if (!is_string($content))
		{
			return '';
		}

		$content = trim($content);

		return mb_strlen($content) > $maxLength
			? mb_substr($content, 0, $maxLength)
			: $content;
	}
}
