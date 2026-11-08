<?php

namespace Bitrix\Mail\Integration\AI;

use Bitrix\AI\Container;
use Bitrix\AI\Context;
use Bitrix\AI\Engine;
use Bitrix\AI\Prompt\Role;
use Bitrix\AI\Result as AiResult;
use Bitrix\AI\Role\RoleManager;
use Bitrix\AI\Services\PromptService;
use Bitrix\Mail\Integration\AI\Context\Subject;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * Dispatches an AI request that generates a short e-mail subject from the body
 * the user is composing.
 *
 * The whole mail <-> ai integration lives here so that the controller stays
 * thin. Region / tariff / agreement / limit checks are NOT duplicated: the ai
 * Engine performs them synchronously and reports the matching error codes
 * (MUST_AGREE_WITH_AGREEMENT / SERVICE_IS_NOT_AVAILABLE_BY_TARIFF /
 * LIMIT_IS_EXCEEDED) before the request is queued.
 *
 * Availability and diagnostics are owned here as well: SubjectAvailability is the
 * one predicate the button of a form is drawn by and the generation is let through
 * by, and SubjectGenerationLog decides what a refusal is worth in the log. Both
 * consumers of the service - the web compose form and the mobile application -
 * therefore behave and are diagnosed alike.
 */
class SubjectGenerator
{
	public const MODE_NEW = 'new';
	public const MODE_REPLY = 'reply';
	public const MODE_FORWARD = 'forward';

	public const ERROR_EMPTY_BODY = 'EMPTY_BODY';
	public const ERROR_INVALID_MODE = 'INVALID_MODE';
	public const ERROR_SUBJECT_GENERATION_DISABLED = 'SUBJECT_GENERATION_DISABLED';
	public const ERROR_ENGINE_NOT_FOUND = 'AI_ENGINE_NOT_FOUND';
	public const ERROR_PROMPT_NOT_FOUND = 'AI_PROMPT_NOT_FOUND';
	public const ERROR_QUEUE_HASH_EMPTY = 'AI_QUEUE_HASH_EMPTY';
	public const ERROR_EMPTY_SUBJECT = 'EMPTY_SUBJECT';

	/** Raised by the consumers of the service when generate() throws, not by the service itself. */
	public const ERROR_GENERATION_FAILED = 'GENERATION_FAILED';

	/**
	 * @param string $body Body being composed (primary source of the subject).
	 * @param string $mode One of MODE_NEW / MODE_REPLY / MODE_FORWARD.
	 * @param int|null $originalMessageId Saved original message id for reply/forward.
	 * @param string $currentSubject Current subject to improve; empty for generation from scratch.
	 * @param string $ownerType CRM entity type (id or symbolic name) the message is composed for.
	 * @param int $ownerId CRM entity id; 0 for regular mail composition.
	 * @return Result Success carries ['queueHash' => string] for async (queue) engines,
	 *               or ['subject' => string] when a synchronous engine already produced it.
	 */
	public function generate(
		string $body,
		string $mode,
		?int $originalMessageId = null,
		string $currentSubject = '',
		string $ownerType = '',
		int $ownerId = 0,
	): Result
	{
		$result = $this->runGeneration($body, $mode, $originalMessageId, $currentSubject, $ownerType, $ownerId);

		if (!$result->isSuccess())
		{
			$this->getLog()->logRefusal($result->getErrors());
		}

		return $result;
	}

	protected function getLog(): SubjectGenerationLog
	{
		return new SubjectGenerationLog();
	}

	protected function runGeneration(
		string $body,
		string $mode,
		?int $originalMessageId,
		string $currentSubject,
		string $ownerType,
		int $ownerId,
	): Result
	{
		$result = new Result();

		/* An unknown mode is a broken client rather than a new letter: a silent fall back to the
		regular context would break a reply or a forward (AC-013, AC-014) with nothing to diagnose it
		by. */
		if (!in_array($mode, [self::MODE_NEW, self::MODE_REPLY, self::MODE_FORWARD], true))
		{
			return $result->addError(new Error('Unknown composition mode', self::ERROR_INVALID_MODE));
		}

		/* The limits belong to Subject and must be applied here: the values go into
		an ai Context, whose pack() persists them into the queue job. */
		$body = Subject::normalizeComposedBody($body);
		$currentSubject = Subject::normalizeCurrentSubject($currentSubject);

		if ($body === '')
		{
			return $result->addError(new Error('Message body is empty', self::ERROR_EMPTY_BODY));
		}

		/* The mode is derived from verifiable data (a CRM entity plus the read
		permission on it), not from a client flag, so a client cannot pick which
		administrative switcher applies. A supplied entity that fails the check is
		never downgraded to the regular mode: that fallback would be exactly the
		bypass of a disabled CRM switcher. Note the scope: this protects the
		switcher policy, not CRM data - the compose screen itself lives on the
		client and the endpoint returns no CRM data. */
		$isCrm = ($ownerId > 0);
		$isAvailable = $isCrm
			? $this->isAvailableForCrmEntity($ownerType, $ownerId)
			: $this->isAvailable(false)
		;

		if (!$isAvailable)
		{
			return $result->addError(
				new Error('AI subject generation is disabled', self::ERROR_SUBJECT_GENERATION_DISABLED),
			);
		}

		$contextId = $this->resolveContextId($mode, $isCrm);

		return $this->runCompletion($contextId, $body, $originalMessageId, $currentSubject);
	}

	protected function isAvailable(bool $isCrm): bool
	{
		return (new SubjectAvailability())->isAvailable($isCrm);
	}

	protected function isAvailableForCrmEntity(string $ownerType, int $ownerId): bool
	{
		return (new SubjectAvailability())->isAvailableForCrmEntity($ownerType, $ownerId);
	}

	protected function resolveContextId(string $mode, bool $isCrm): string
	{
		// Reply and forward are both composed against an existing message, so they
		// route through the reply-like context that supplies the original message
		// as an additional reference (AC-012); a brand-new message has none.
		$referencesOriginalMessage = ($mode === self::MODE_REPLY || $mode === self::MODE_FORWARD);

		return Subject::resolveContextId($referencesOriginalMessage, $isCrm);
	}

	/**
	 * Builds and dispatches the completion request. The portal setting selects the
	 * requested mode, while the Engine facade chooses the actual transport based on
	 * the engine type. A queued request returns a hash; an immediate result returns
	 * the generated subject.
	 */
	protected function runCompletion(
		string $contextId,
		string $body,
		?int $originalMessageId,
		string $currentSubject = '',
	): Result
	{
		$result = new Result();

		if (!Loader::includeModule('ai'))
		{
			return $result->addError(new Error('AI module is not available', self::ERROR_ENGINE_NOT_FOUND));
		}

		$userId = (int)CurrentUser::get()->getId();

		$context = new Context('mail', $contextId, $userId);
		$context->setParameters([
			'body' => $body,
			'currentSubject' => $currentSubject,
			'originalMessageId' => $originalMessageId,
		]);

		$engine = Engine::getByCategory(Engine::CATEGORIES['text'], $context);
		if (!$engine)
		{
			return $result->addError(new Error('AI text engine is not available', self::ERROR_ENGINE_NOT_FOUND));
		}

		/** @var PromptService $promptService */
		$promptService = Container::init()->getItem(PromptService::class);
		$payload = $promptService->getPayloadForTextPrompt(Subject::getPromptText(), $userId, [], []);
		if (empty($payload))
		{
			return $result->addError(new Error('AI prompt payload is empty', self::ERROR_PROMPT_NOT_FOUND));
		}

		$roleCode = RoleManager::getUniversalRoleCode();
		$payload->setRole(Role::get($roleCode) ?: Role::getUniversalRole());

		$queueHash = null;
		$completionResult = null;
		$engine
			->setPayload($payload)
			// Without this flag the engine drops the context messages, and the body
			// being composed never reaches the model: the prompt text carries only
			// the instruction, so the model would answer that the body is missing.
			->setParameters(['collect_context' => true])
			->setHistoryState(false)
			->onSuccess(function (AiResult $completion, ?string $hash = null) use (&$queueHash, &$completionResult) {
				$queueHash = $hash;
				$completionResult = $completion;
			})
			->onError(function (Error $error) use ($result) {
				$result->addError($error);
			})
		;

		$this->dispatchCompletion($engine);

		if (!$result->isSuccess())
		{
			return $result;
		}

		// The context response format defaults to prettified text, the same value the
		// queue would push; no completion at all is told apart from an empty one.
		$subject = ($completionResult !== null) ? trim((string)$completionResult->getPrettifiedData()) : null;

		return $this->resolveCompletionResult($queueHash, $subject);
	}

	/**
	 * The answer of the engine as the two transports deliver it.
	 *
	 * @param string|null $queueHash Hash of an asynchronous engine; the subject arrives through the
	 *        queue later and is correlated by this hash on the client.
	 * @param string|null $subject Trimmed answer of a synchronous engine, null when the engine
	 *        delivered no completion at all.
	 */
	protected function resolveCompletionResult(?string $queueHash, ?string $subject): Result
	{
		$result = new Result();

		if ($queueHash !== null && $queueHash !== '')
		{
			return $result->setData(['queueHash' => $queueHash]);
		}

		// Neither a hash nor a completion: the queue accepted nothing and there is
		// nothing to correlate a later answer by. That is a broken transport.
		if ($subject === null)
		{
			return $result->addError(new Error('AI queue hash is empty', self::ERROR_QUEUE_HASH_EMPTY));
		}

		// A synchronous engine carries no hash: the completion is already available,
		// so deliver the subject straight away.
		if ($subject !== '')
		{
			return $result->setData(['subject' => $subject]);
		}

		// The engine answered, and the answer is empty: the model produced no subject.
		// A repeatable outcome of a working service rather than a breakdown of it.
		return $result->addError(new Error('AI answered with an empty subject', self::ERROR_EMPTY_SUBJECT));
	}

	protected function dispatchCompletion(Engine $engine): void
	{
		if ($this->shouldUseQueueMode())
		{
			$engine->completionsInQueue();
		}
		else
		{
			$engine->completions();
		}
	}

	protected function shouldUseQueueMode(): bool
	{
		return Option::get('ai', 'queue_mode', 'Y') === 'Y';
	}
}
