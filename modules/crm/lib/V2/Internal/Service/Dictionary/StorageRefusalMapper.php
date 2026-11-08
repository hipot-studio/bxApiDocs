<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Dictionary;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Entity\Dictionary\DictionaryError;
use Bitrix\Crm\V2\Internal\Repository\Dictionary\DictionaryValueRepositoryInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Psr\Log\LoggerInterface;

/**
 * Turns the refusal of the storage boundary of the dictionaries into a {@see DictionaryError}.
 *
 * The boundary refuses in one shape only - a failed {@see Result} carrying the text it wrote
 * ({@see DictionaryValueRepositoryInterface}) - and it carries no error code of its own, so the text is
 * all there is to recognize a refusal by. Recognition therefore goes by PHRASE KEY and never by a
 * hardcoded substring: the text is localized and rewritten by translators. The comparison pattern is
 * built from the current text of the key, with `#PLACEHOLDER#` matching any value.
 *
 * ONE PHRASE IS RECOGNIZED: an identifier already taken is the only refusal of the facade the scenario
 * does not prevent on its own. Everything else it checks before it writes, so a refusal that arrives
 * here unrecognized is one of three things - a gap of this class, a dictionary already poisoned by data
 * written past the facade (which refuses an ordinary value with no text at all), or a write of the same
 * value the boundary could not hold to one at a time
 * ({@see \Bitrix\Crm\V2\Internal\Repository\Dictionary\DictionaryValueLockInterface}). All three are
 * always logged, and only logged: the text of the boundary never reaches a caller, because it may name a
 * table, a class or the shape of the legacy implementation.
 *
 * Unlike the mapper of the funnel domain
 * ({@see \Bitrix\Crm\V2\Internal\Exception\Category\StorageBoundaryExceptionMapper}) this one is not
 * asked whether the boundary refused before it wrote anything: no operation of this domain undoes a write
 * of its own, and a refusal of this boundary has written nothing anyway
 * ({@see DictionaryValueRepositoryInterface}).
 *
 * @internal
 */
final class StorageRefusalMapper
{
	private const LOGGER_ID = 'Default';

	/**
	 * The refusal of the facade this domain can name: `\CCrmStatus::Add()` answers it to an identifier
	 * that is taken in the same dictionary already.
	 */
	private const PHRASE_KEY_ID_DUPLICATE = 'CRM_STATUS_ERR_DUPLICATE_STATUS_ID';

	/** @var array<string, string>|null */
	private ?array $boundaryPhrases = null;

	public function __construct(private readonly ?LoggerInterface $logger = null)
	{
	}

	/**
	 * A successful result passes through untouched, together with its data. A failed one is rebuilt from
	 * domain errors alone, each of them once, and carries no data at all.
	 */
	public function mapResult(Result $result): Result
	{
		if ($result->isSuccess())
		{
			return $result;
		}

		$mapped = new Result();
		$reported = [];
		foreach ($result->getErrors() as $boundaryError)
		{
			$domainError = $this->recognize(trim((string)$boundaryError->getMessage()));

			$code = (string)$domainError->getCode();
			if (!isset($reported[$code]))
			{
				$reported[$code] = true;
				$mapped->addError($domainError);
			}
		}

		return $mapped;
	}

	private function recognize(string $message): Error
	{
		$phrase = $this->boundaryPhrases()[self::PHRASE_KEY_ID_DUPLICATE] ?? '';
		if ($phrase !== '' && self::phraseMatches($phrase, $message))
		{
			return DictionaryError::VALUE_ID_DUPLICATE->toError();
		}

		$this->resolveLogger()->error(
			'CRM dictionary domain got a storage boundary refusal it cannot classify: {message}',
			['message' => $message],
		);

		return DictionaryError::DICTIONARY_OPERATION_FAILED->toError();
	}

	/**
	 * Whether $message is what $phrase reads like once the boundary has filled its placeholders in.
	 *
	 * A phrase whose text is nothing but placeholders is never a match: with no literal left to
	 * recognize it by, the pattern built from it would be `.*` and would hand its code to every refusal
	 * the domain cannot classify. The phrases are written by translators, so this is a shape the class
	 * has to survive rather than one it can rule out.
	 */
	private static function phraseMatches(string $phrase, string $message): bool
	{
		$literals = preg_split('/#[A-Z\d_]+#/u', $phrase) ?: [$phrase];
		if (trim(implode('', $literals)) === '')
		{
			return false;
		}

		$pattern = implode('.*', array_map(
			static fn (string $literal): string => preg_quote($literal, '/'),
			$literals,
		));

		return (bool)preg_match('/^' . $pattern . '$/su', $message);
	}

	/**
	 * The phrases of the boundary in the language of the request, taken from the language twin of the
	 * facade itself: the key is what recognition compares by, and its text is wherever the facade keeps
	 * it.
	 *
	 * @return array<string, string>
	 */
	private function boundaryPhrases(): array
	{
		if ($this->boundaryPhrases === null)
		{
			$file = (new \ReflectionClass(\CCrmStatus::class))->getFileName();
			$this->boundaryPhrases = $file === false ? [] : Loc::loadLanguageFile($file);
		}

		return $this->boundaryPhrases;
	}

	private function resolveLogger(): LoggerInterface
	{
		return $this->logger ?? Container::getInstance()->getLogger(self::LOGGER_ID);
	}
}
