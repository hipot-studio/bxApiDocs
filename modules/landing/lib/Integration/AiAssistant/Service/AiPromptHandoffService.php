<?php
declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

use Bitrix\Landing\Domain;
use Bitrix\Landing\Integration\AiAssistant\Exception\HandoffLimitException;
use Bitrix\Landing\Integration\AiAssistant\Model\AiPromptHandoffTable;
use Bitrix\Landing\Markup\MarkupLifecycleLog;
use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;

/**
 * One-time links carrying a request of the chat to /sites/ai/ or to the editor of a site.
 *
 * The token exists only in the link: the table keeps its sha256 hash, the owner, the target (and the site
 * for the editor), the text and the expiry. A token is redeemed once, by its owner, for its target and
 * site, before it expires; the text is erased on redemption. Neither the token nor the text reaches the
 * journal.
 *
 * Only the token of a failed creation (/sites/ai/ with the row of its chat) may carry no text: it returns
 * the author to the same chat, where nothing is sent on their behalf.
 */
class AiPromptHandoffService
{
	public const TARGET_SITES_AI = 'sites_ai';
	public const TARGET_EDITOR = 'editor';

	public const URL_PARAM = 'ai_handoff';
	public const SITES_AI_PATH = '/sites/ai/';

	public const TTL_SECONDS = 3600;
	public const MAX_PROMPT_LENGTH = 4000;
	public const ISSUE_LIMIT_PER_HOUR = 30;

	public const EVENT_ISSUED = 'handoff_issued';
	public const EVENT_REFUSED = 'handoff_refused';

	public const REASON_NOT_FOUND = 'not_found';
	public const REASON_EXPIRED = 'expired';
	public const REASON_USED = 'used';
	public const REASON_FOREIGN_USER = 'foreign_user';
	public const REASON_SITE_MISMATCH = 'site_mismatch';
	public const REASON_NO_ACCESS = 'no_access';
	public const REASON_LIMIT = 'limit';

	private const TOKEN_BYTES = 32;
	private const LOCK_TIMEOUT_SECONDS = 5;

	private readonly MarkupLifecycleLog $log;

	public function __construct(?MarkupLifecycleLog $log = null)
	{
		$this->log = $log ?? new MarkupLifecycleLog();
	}

	/**
	 * @param int $bindingId The chat row the /sites/ai/ link returns to; only a row of $userId may be passed.
	 *     Ownership is not checked here: with a row the link may carry no text, so the caller vouches for it,
	 *     and the redemption on /sites/ai/ checks the owner and the state of the row again.
	 * @return array{url: string, expiresAt: string}
	 * @throws HandoffLimitException When the user has issued ISSUE_LIMIT_PER_HOUR links within the last hour.
	 */
	public function issueLink(
		int $userId,
		string $target,
		string $prompt,
		int $siteId = 0,
		int $pageId = 0,
		int $bindingId = 0,
	): array
	{
		if ($userId <= 0)
		{
			throw new \InvalidArgumentException('userId must be positive.');
		}
		$this->assertTarget($target);
		$isEditor = $target === self::TARGET_EDITOR;
		if ($isEditor && ($siteId <= 0 || $pageId <= 0))
		{
			throw new \InvalidArgumentException('siteId and pageId are required for the editor target.');
		}
		$isCreationFailure = !$isEditor && $bindingId > 0;
		$prompt = mb_substr(trim($prompt), 0, self::MAX_PROMPT_LENGTH);
		if ($prompt === '' && !$isCreationFailure)
		{
			throw new \InvalidArgumentException('The request to carry must not be empty.');
		}

		$token = bin2hex(random_bytes(self::TOKEN_BYTES));
		$now = new DateTime();
		$expiresAt = DateTime::createFromTimestamp($now->getTimestamp() + self::TTL_SECONDS);

		$fields = [
			'TOKEN_HASH' => $this->hashToken($token),
			'USER_ID' => $userId,
			'TARGET' => $target,
			'SITE_ID' => $isEditor ? $siteId : null,
			'BINDING_ID' => !$isEditor && $bindingId > 0 ? $bindingId : null,
			'PROMPT' => $prompt !== '' ? $prompt : null,
			'DATE_CREATE' => $now,
			'DATE_EXPIRE' => $expiresAt,
			'DATE_USED' => null,
		];
		$this->withIssueLock($userId, function () use ($fields, $now): void
		{
			if ($this->countIssuedWithinHour($fields['USER_ID'], $now) >= self::ISSUE_LIMIT_PER_HOUR)
			{
				$this->logRefusal($fields['USER_ID'], $fields['TARGET'], self::REASON_LIMIT);

				throw new HandoffLimitException(self::ISSUE_LIMIT_PER_HOUR);
			}

			$result = AiPromptHandoffTable::add($fields);
			if (!$result->isSuccess())
			{
				throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
			}
		});

		$this->log->write(self::EVENT_ISSUED, [
			'bindingId' => !$isEditor && $bindingId > 0 ? $bindingId : null,
			'siteId' => $isEditor ? $siteId : null,
			'userId' => $userId,
			'target' => $target,
			'expiresAt' => $expiresAt->format(\DATE_ATOM),
		]);

		$path = $isEditor ? '/sites/site/' . $siteId . '/view/' . $pageId . '/' : self::SITES_AI_PATH;

		return [
			'url' => rtrim(Domain::getHostUrl(), '/') . $path . '?' . self::URL_PARAM . '=' . $token,
			'expiresAt' => $expiresAt->format(\DATE_ATOM),
		];
	}

	/**
	 * Redeems the token for its owner, target and (for the editor) site. A refusal is logged with its reason.
	 *
	 * @return array{prompt: string, bindingId: int}|null Null when the token carries nothing for this call.
	 */
	public function redeem(string $token, int $userId, string $target, int $siteId = 0): ?array
	{
		$this->assertTarget($target);
		$isEditor = $target === self::TARGET_EDITOR;
		if (!preg_match('/^[0-9a-f]{' . (self::TOKEN_BYTES * 2) . '}$/D', $token) || $userId <= 0)
		{
			$this->logRefusal($userId, $target, self::REASON_NOT_FOUND, $isEditor ? $siteId : null);

			return null;
		}
		if ($isEditor && $siteId <= 0)
		{
			$this->logRefusal($userId, $target, self::REASON_SITE_MISMATCH, $siteId);

			return null;
		}

		// The redemption is one conditional UPDATE: the ORM updates by primary key only, and a token must
		// not be redeemed twice by concurrent requests. Values are bound as integers, the hash and the target
		// through SqlHelper::forSql(), dates through convertToDbDateTime().
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$hash = $this->hashToken($token);
		$now = $helper->convertToDbDateTime(new DateTime());
		$connection->queryExecute(sprintf(
			"UPDATE %s SET DATE_USED = %s WHERE TOKEN_HASH = '%s' AND USER_ID = %d AND TARGET = '%s' AND %s"
			. ' AND DATE_EXPIRE > %s AND DATE_USED IS NULL',
			AiPromptHandoffTable::getTableName(),
			$now,
			$helper->forSql($hash),
			$userId,
			$helper->forSql($target),
			$isEditor ? 'SITE_ID = ' . $siteId : 'SITE_ID IS NULL',
			$now,
		));
		$redeemed = (int)$connection->getAffectedRowsCount() === 1;

		$row = $this->findByHash($hash);
		if (!$redeemed || $row === null)
		{
			$this->logRefusal($userId, $target, $this->resolveRefusalReason($row, $userId), $isEditor ? $siteId : null);

			return null;
		}

		AiPromptHandoffTable::update((int)$row['ID'], ['PROMPT' => null]);

		return [
			'prompt' => (string)$row['PROMPT'],
			'bindingId' => (int)$row['BINDING_ID'],
		];
	}

	/**
	 * Logs a refusal decided by the caller, for example `no_access` of the editor.
	 */
	public function logRefusal(int $userId, string $target, string $reason, ?int $siteId = null): void
	{
		$this->log->write(self::EVENT_REFUSED, [
			'siteId' => $siteId,
			'userId' => $userId,
			'reason' => $reason,
			'target' => $target,
		]);
	}

	/**
	 * Removes expired tokens. A redeemed token stays until it expires: it is counted in the issue limit.
	 *
	 * @return int Number of removed tokens.
	 */
	public function clearStale(int $limit = 500): int
	{
		if ($limit <= 0)
		{
			return 0;
		}

		$ids = array_map(
			'intval',
			array_column(
				AiPromptHandoffTable::query()
					->setSelect(['ID'])
					->where('DATE_EXPIRE', '<', new DateTime())
					->setOrder(['ID' => 'ASC'])
					->setLimit($limit)
					->fetchAll(),
				'ID',
			),
		);
		if ($ids === [])
		{
			return 0;
		}

		// one set DELETE of the read ids: the ORM deletes by primary key only, one call per row is the
		// alternative; the ids are integers
		$connection = Application::getConnection();
		$connection->queryExecute(sprintf(
			'DELETE FROM %s WHERE ID IN (%s)',
			AiPromptHandoffTable::getTableName(),
			implode(', ', $ids),
		));

		return (int)$connection->getAffectedRowsCount();
	}

	private function resolveRefusalReason(?array $row, int $userId): string
	{
		if ($row === null)
		{
			return self::REASON_NOT_FOUND;
		}
		if ((int)$row['USER_ID'] !== $userId)
		{
			return self::REASON_FOREIGN_USER;
		}
		if ($row['DATE_USED'] !== null)
		{
			return self::REASON_USED;
		}
		if ($row['DATE_EXPIRE'] instanceof DateTime && $row['DATE_EXPIRE']->getTimestamp() <= time())
		{
			return self::REASON_EXPIRED;
		}

		return self::REASON_SITE_MISMATCH;
	}

	private function findByHash(string $hash): ?array
	{
		$row = AiPromptHandoffTable::query()
			->setSelect(['ID', 'USER_ID', 'BINDING_ID', 'PROMPT', 'DATE_EXPIRE', 'DATE_USED'])
			->where('TOKEN_HASH', '=', $hash)
			->setLimit(1)
			->fetch()
		;

		return is_array($row) ? $row : null;
	}

	private function countIssuedWithinHour(int $userId, DateTime $now): int
	{
		return (int)AiPromptHandoffTable::getCount([
			'=USER_ID' => $userId,
			'>DATE_CREATE' => DateTime::createFromTimestamp($now->getTimestamp() - 3600),
		]);
	}

	private function withIssueLock(int $userId, callable $callback): void
	{
		$connection = Application::getConnection();
		$lockName = 'landing_ai_handoff_issue_' . $userId;
		if (!$connection->lock($lockName, self::LOCK_TIMEOUT_SECONDS))
		{
			throw new \RuntimeException("Unable to acquire AI handoff lock: {$lockName}.");
		}

		try
		{
			$callback();
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	private function assertTarget(string $target): void
	{
		if ($target !== self::TARGET_SITES_AI && $target !== self::TARGET_EDITOR)
		{
			throw new \InvalidArgumentException("Unknown handoff target: {$target}.");
		}
	}

	private function hashToken(string $token): string
	{
		return hash('sha256', $token);
	}
}
