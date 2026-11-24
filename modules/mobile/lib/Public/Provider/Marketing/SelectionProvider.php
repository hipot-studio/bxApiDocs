<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Public\Provider\Marketing;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Mobile\Internal\Entity\Marketing\EvaluationContext;
use Bitrix\Mobile\Internal\Entity\Marketing\SelectionResult;
use Bitrix\Mobile\Internal\Service\Marketing\Evaluator;
use Bitrix\Mobile\Internal\Service\Marketing\RegistryFactory;

/**
 * Selection of communications for other modules: which registered rules fit a user or a pack of users in a
 * channel and a placement at a moment. The portal is the one of the environment; the answer holds verdicts,
 * chosen alternatives and moments, never the facts behind them, and reading it writes nothing.
 *
 * select() trusts its caller with whom and when it asks about: a verdict tells about the roles and the
 * rights of the users asked, at any moment given. It is for server code that already decided the users;
 * whatever answers a request of a client asks with selectForCurrentUser(), which takes neither.
 */
final class SelectionProvider
{
	/** The key of the SelectionResult in the data of a successful Result. */
	public const DATA_SELECTION = Evaluator::DATA_SELECTION;

	public const ERROR_NO_CURRENT_USER = 'MARKETING_NO_CURRENT_USER';

	private readonly Evaluator $evaluator;

	/**
	 * @param Evaluator|null $evaluator the rules and facts the configuration of the module plugs in by default
	 */
	public function __construct(?Evaluator $evaluator = null)
	{
		$this->evaluator = $evaluator ?? new Evaluator((new RegistryFactory())->fromConfiguration());
	}

	/**
	 * @param string[] $ruleCodes rules to evaluate; empty - every rule of the channel and the placement
	 * @return Result with the SelectionResult under DATA_SELECTION; an unknown rule code, an empty pack, a
	 *     pack larger than Evaluator::MAX_PACK_SIZE, a non-positive user id, or a channel or a placement no
	 *     rule names fail the whole call
	 * @see SelectionResult
	 */
	public function select(EvaluationContext $context, array $ruleCodes = []): Result
	{
		return $this->evaluator->evaluate($context, $ruleCodes);
	}

	/**
	 * The rules for the signed-in user at the current moment.
	 *
	 * @param string[] $ruleCodes rules to evaluate; empty - every rule of the channel and the placement
	 * @return Result as select() gives it, with the user under his id; nobody signed in fails the whole call
	 */
	public function selectForCurrentUser(string $channel, ?string $placement = null, array $ruleCodes = []): Result
	{
		$userId = (int)CurrentUser::get()->getId();
		if ($userId <= 0)
		{
			return (new Result())->addError(new Error('Nobody is signed in', self::ERROR_NO_CURRENT_USER));
		}

		return $this->select(EvaluationContext::forUser($userId, $channel, $placement), $ruleCodes);
	}
}
