<?php

namespace Bitrix\Bizproc\Integration\AiAssistant;

use Bitrix\Main\Diag\LoggerFactory;

/**
 * The condition kinds a block that holds a condition stores it under. Both the branch and the loop key the
 * condition by the code of the condition activity itself, so the installed codes ARE their setting names.
 *
 * Shared rather than answered twice: the two blocks ask the same question of the same catalog, and an answer
 * that drifted between them would let one of them author a condition the other refuses.
 */
final class ConditionSettingNames
{
	/** The AI contract of an activity reports into the channel of the AI description service. */
	private const AI_LOGGER_ID = 'bizproc.aiassistant.activity_description';

	/**
	 * Answers of {@see self::all()} per document type.
	 *
	 * @var array<string, list<string>>
	 */
	private static array $codes = [];

	/**
	 * Answers of {@see self::executable()} per document type. An answer a condition could not be asked for is
	 * absent from here on purpose - see the method.
	 *
	 * @var array<string, list<string>>
	 */
	private static array $executableCodes = [];

	/**
	 * Every installed condition kind, by property key. Taken from the same source as the graph.
	 *
	 * Memoised per document type: resolving them walks the whole activity catalog, which
	 * {@see \Bitrix\Bizproc\Runtime\ActivitySearcher\Searcher::searchByType()} does not memoise, while one
	 * reverse AI entry asks for them once per node that carries a condition.
	 *
	 * @return list<string>
	 */
	public static function all(array $documentType): array
	{
		return self::$codes[implode('|', $documentType)] ??= array_keys(
			\CBPRuntime::getRuntime()->searchActivitiesByType('condition', $documentType)
		);
	}

	/**
	 * Condition kinds whose payload is executable, so an AI agent may only pass an existing one through verbatim.
	 * Membership is asked of the condition class itself ({@see \CBPActivityCondition::requiresAdminToAuthor()}),
	 * not hard-coded: today only `codecondition` answers true, but a new executable condition joins the rule by
	 * declaring it. The EXCLUDED flag is deliberately not used - it is distribution-specific and marks a different
	 * property (whether the editor offers the condition at all).
	 *
	 * The codes come from {@see self::all()} rather than from a catalog walk of its own: this is a subset of that
	 * list, and both are asked for on the same reverse entry.
	 *
	 * A condition that could not be asked is answered as restricted: the barrier of ALG-04 lets an agent author
	 * every name absent from this set, so an unknown status has to refuse authorship instead of granting it. The
	 * published template still survives the round-trip - the barrier compares against a baseline built through
	 * this very method, so the same code is restricted on both sides and its unchanged value matches. Such an
	 * answer is not memoised either: the restriction belongs to the failed load, not to the condition, and must
	 * not settle the question for the rest of the process.
	 *
	 * @return list<string>
	 */
	public static function executable(array $documentType): array
	{
		$cacheKey = implode('|', $documentType);
		if (isset(self::$executableCodes[$cacheKey]))
		{
			return self::$executableCodes[$cacheKey];
		}

		$executable = [];
		$isComplete = true;

		foreach (self::all($documentType) as $code)
		{
			try
			{
				if (\CBPActivityCondition::CallStaticMethod($code, 'requiresAdminToAuthor') === true)
				{
					$executable[] = $code;
				}
			}
			catch (\Throwable $exception)
			{
				$executable[] = $code;
				$isComplete = false;
				(new LoggerFactory())->createById(self::AI_LOGGER_ID)?->error(
					'Condition {code} could not be asked whether authoring it needs admin rights and stays'
					. ' restricted until it can be: {error}',
					['code' => $code, 'error' => $exception->getMessage()],
				);
			}
		}

		if (!$isComplete)
		{
			return $executable;
		}

		return self::$executableCodes[$cacheKey] = $executable;
	}
}
