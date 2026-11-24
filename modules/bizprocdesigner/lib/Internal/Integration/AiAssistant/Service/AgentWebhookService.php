<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\BizprocDesigner\Internal\Config\Feature;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Rest\Enum\APAuth\PasswordType;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;
use Bitrix\Rest\Internal\Repository\IncomingWebhookRepository;
use Bitrix\Rest\Public\Command\IncomingWebhook\CreateSystemIncomingWebhookCommand;
use Bitrix\Rest\Public\Command\IncomingWebhook\DeactivateIncomingWebhookCommand;
use Bitrix\Rest\Public\Provider\Dto\IncomingWebhookDto;
use Bitrix\Rest\Public\Provider\IncomingWebhookProvider;
use Bitrix\Rest\Public\Provider\Params\IncomingWebhookFilter;
use Bitrix\Rest\Public\Provider\Params\IncomingWebhookParams;
use Closure;
use Exception;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

final class AgentWebhookService
{
	private const SCOPE = 'bizprocdesigner';
	private const TITLE = 'AI agent (bizproc designer)';

	private const ATTR_OWNER = 'bizprocdesigner';
	private const ATTR_TEMPLATE_ID = 'template_id';
	private const ATTR_EXPIRE_AT = 'expire_at';

	private const FIND_LIMIT = 50;

	private const LOCK_TIMEOUT = 10;

	private const LOGGER_ID = 'bizprocdesigner.aiassistant.agent_webhook';

	private ?LoggerInterface $logger = null;

	/**
	 * @var Closure(int $userId, int $passwordId): void runs the deactivation of a single token;
	 *      defaults to the rest command and can be overridden with an alternative strategy
	 */
	private readonly Closure $deactivateToken;

	public function __construct(?Closure $deactivateToken = null)
	{
		$this->deactivateToken = $deactivateToken ?? static function (int $userId, int $passwordId): void {
			(new DeactivateIncomingWebhookCommand(
				userId: $userId,
				passwordId: $passwordId,
			))->run();
		};
	}

	/**
	 * Ensures a single active token for the (user, templateId) pair and returns its ready-to-use URL
	 * together with the connection status (connected + expiresAt), so the connect endpoint does not
	 * need a second status lookup.
	 *
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws Exception
	 */
	public function ensureWebhookUrl(int $userId, int $templateId): Result
	{
		$result = new Result();

		if (!Loader::includeModule('rest'))
		{
			return $result->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_WEBHOOK_ERR_MODULE_NOT_INSTALLED'), 'REST_NOT_INSTALLED'));
		}

		$existing = $this->findLiveTokens($userId, $templateId)[0] ?? null;
		if ($existing !== null)
		{
			return $this->answerWith($existing->getWebhookUrl(), $existing->getId(), $result);
		}

		// Past this point a token is about to be minted, and the read above is what decides that. Two calls
		// of the same pair that both read "none" both mint one, and the pair ends up with two live secrets
		// where the contract promises one - the same reason regenerate() holds this very lock.
		$connection = Application::getConnection();
		$lockName = self::pairLockName($userId, $templateId);
		$acquired = $connection->lock($lockName, self::LOCK_TIMEOUT);

		try
		{
			if (!$acquired)
			{
				return $result->addError(
					new Error(Loc::getMessage('BIZPROCDESIGNER_WEBHOOK_ERR_LOCK_NOT_ACQUIRED'), 'LOCK_NOT_ACQUIRED')
				);
			}

			// Read again, now that nobody else can be minting: the call that held the lock before this one
			// has already created the token this call was about to duplicate.
			$existing = $this->findLiveTokens($userId, $templateId)[0] ?? null;
			if ($existing !== null)
			{
				return $this->answerWith($existing->getWebhookUrl(), $existing->getId(), $result);
			}

			$webhook = $this->create($userId, $templateId, $result);
			if ($webhook === null)
			{
				return $result;
			}

			return $this->answerWith(
				\CRestUtil::getWebhookEndpoint($webhook->getPassword(), $userId),
				$webhook->getId(),
				$result,
			);
		}
		finally
		{
			if ($acquired)
			{
				$connection->unlock($lockName);
			}
		}
	}

	private function answerWith(string $url, int $passwordId, Result $result): Result
	{
		return $result->setData([
			'url' => $url,
			'connected' => true,
			'expiresAt' => $this->getTokenExpireAt($passwordId),
		]);
	}

	/**
	 * Deactivates every active System webhook token bound to the (user, templateId) pair. Instant
	 * revocation is enforced by the fresh per-call status check (AgentTokenGuard), not by the
	 * deactivation itself. Ownership is enforced by the rest command: only our tokens are touched.
	 *
	 * A single token that could not be deactivated leaves the pair still reachable until its TTL, so
	 * the result reports an error instead of a false "disconnected": the user can retry rather than
	 * be misled into thinking the access is already gone.
	 *
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function revoke(int $userId, int $templateId): Result
	{
		$result = new Result();

		if (!Loader::includeModule('rest'))
		{
			return $result->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_WEBHOOK_ERR_MODULE_NOT_INSTALLED'), 'REST_NOT_INSTALLED'));
		}

		$allDeactivated = true;
		foreach ($this->findActiveTokens($userId, $templateId) as $dto)
		{
			if (!$this->deactivate($userId, $templateId, $dto->getId()))
			{
				$allDeactivated = false;
			}
		}

		if (!$allDeactivated)
		{
			return $result->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_WEBHOOK_ERR_REVOKE_FAILED'), 'WEBHOOK_REVOKE_FAILED'));
		}

		return $result->setData(['connected' => false, 'expiresAt' => null]);
	}

	/**
	 * Re-issues the pair token under a portal-level lock keyed on (userId, templateId): creates the
	 * new token FIRST, then deactivates every previous active token of the pair (excluding the new
	 * one). The order is strict: it guarantees no downtime window and exactly one active token per
	 * pair. A failure to deactivate a single previous token does not roll back the re-issue
	 * (fail-safe towards availability): the failure is logged for audit and the leftover token is
	 * cleaned up by the gardener/TTL.
	 *
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 * @throws Exception
	 */
	public function regenerate(int $userId, int $templateId): Result
	{
		$result = new Result();

		if (!Loader::includeModule('rest'))
		{
			return $result->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_WEBHOOK_ERR_MODULE_NOT_INSTALLED'), 'REST_NOT_INSTALLED'));
		}

		$connection = Application::getConnection();
		$lockName = self::pairLockName($userId, $templateId);
		$acquired = $connection->lock($lockName, self::LOCK_TIMEOUT);

		try
		{
			if (!$acquired)
			{
				return $result->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_WEBHOOK_ERR_LOCK_NOT_ACQUIRED'), 'LOCK_NOT_ACQUIRED'));
			}

			$webhook = $this->create($userId, $templateId, $result);

			if ($webhook === null)
			{
				return $result;
			}

			$newPasswordId = $webhook->getId();

			foreach ($this->findActiveTokens($userId, $templateId) as $dto)
			{
				if ($dto->getId() === $newPasswordId)
				{
					continue;
				}

				$this->deactivate($userId, $templateId, $dto->getId());
			}

			$url = \CRestUtil::getWebhookEndpoint($webhook->getPassword(), $userId);
			$expiresAt = $this->getTokenExpireAt($newPasswordId);

			return $result->setData([
				'url' => $url,
				'connected' => true,
				'expiresAt' => $expiresAt,
			]);
		}
		finally
		{
			if ($acquired)
			{
				$connection->unlock($lockName);
			}
		}
	}

	/**
	 * Single source of the token connection status for the connect/regenerate/status endpoints.
	 *
	 * @return array{connected: bool, expiresAt: int|null}
	 *
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getStatus(int $userId, int $templateId): array
	{
		if (!Loader::includeModule('rest'))
		{
			return ['connected' => false, 'expiresAt' => null];
		}

		foreach ($this->findLiveTokens($userId, $templateId) as $dto)
		{
			return [
				'connected' => true,
				'expiresAt' => $this->getTokenExpireAt($dto->getId()),
			];
		}

		return ['connected' => false, 'expiresAt' => null];
	}

	/**
	 * All active System tokens of the (user, template_id) pair. Single source of the pair's token
	 * lookup for ensureWebhookUrl/revoke/regenerate/getStatus (DRY). The DTO carries the ready-to-use
	 * webhook URL verbatim (already produced by \CRestUtil::getWebhookEndpoint), so callers must not
	 * rebuild it from the password and never double the /rest/api/ prefix.
	 *
	 * @return IncomingWebhookDto[]
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	/**
	 * The lock of one pair, held by everything that mints a token for it. One spelling, because two calls
	 * naming the pair differently would not exclude each other at all.
	 */
	private static function pairLockName(int $userId, int $templateId): string
	{
		return "bizprocdesigner:agent_token:{$userId}:{$templateId}";
	}

	/**
	 * Active rows of the pair whose expiry is still ahead - what a caller can actually authorize with.
	 *
	 * Kept apart from {@see self::findActiveTokens()} rather than replacing it, because the two readings
	 * serve opposite ends. Issuing and reporting must skip an expired row: it stays ACTIVE until the
	 * gardener comes, while the contour already refuses it {@see AgentTokenGuard}, so handing its url
	 * back hands back a session that is dead on arrival - and the entry offers no way to regenerate.
	 * Revoking and re-issuing must see that same row, or a credential that still looks live is left
	 * behind.
	 *
	 * A row carrying no expiry at all counts as expired, exactly as the contour reads it.
	 *
	 * @return IncomingWebhookDto[]
	 */
	private function findLiveTokens(int $userId, int $templateId): array
	{
		$now = time();
		$live = [];

		foreach ($this->findActiveTokens($userId, $templateId) as $dto)
		{
			$expireAt = $this->getTokenExpireAt($dto->getId());
			if ($expireAt !== null && $expireAt > $now)
			{
				$live[] = $dto;
			}
		}

		return $live;
	}

	private function findActiveTokens(int $userId, int $templateId): array
	{
		$params = new IncomingWebhookParams(
			webhookFilter: new IncomingWebhookFilter(
				userId: $userId,
				attributes: [
					self::ATTR_OWNER => 'Y',
					self::ATTR_TEMPLATE_ID => (string)$templateId,
				],
				type: PasswordType::System,
			),
			pager: new Pager(limit: self::FIND_LIMIT),
		);

		$active = [];
		foreach ((new IncomingWebhookProvider())->getList($params) as $webhook)
		{
			if ($webhook->isActive())
			{
				$active[] = $webhook;
			}
		}

		return $active;
	}

	/**
	 * Reads the expire_at external attribute of the token. The public provider does not carry
	 * attributes, so it is re-read from the rest Internal repository.
	 */
	private function getTokenExpireAt(int $passwordId): ?int
	{
		$webhook = (new IncomingWebhookRepository())->getById($passwordId);
		$expireAt = $webhook?->getExternalAttribute(self::ATTR_EXPIRE_AT)?->getValue();

		if ($expireAt === null || $expireAt === '')
		{
			return null;
		}

		return (int)$expireAt;
	}

	/**
	 * Deactivates a single token and reports whether it succeeded. The owner match and the token's
	 * existence are checked by the rest command; its run() throws exceptions (AccessDeniedException /
	 * IncomingWebhookNotFoundException / a transient persistence failure, wrapped in CommandException).
	 * A failure is logged for audit (without the secret or the URL) and reported back so the caller
	 * can decide: revoke surfaces an error, regenerate falls back to the gardener agent / TTL.
	 */
	private function deactivate(int $userId, int $templateId, int $passwordId): bool
	{
		try
		{
			($this->deactivateToken)($userId, $passwordId);

			return true;
		}
		catch (Throwable $e)
		{
			$this->getLogger()->error(
				'AI agent webhook: token deactivation failed',
				[
					'userId' => $userId,
					'templateId' => $templateId,
					'passwordId' => $passwordId,
					'exception' => $e,
				],
			);

			return false;
		}
	}

	private function getLogger(): LoggerInterface
	{
		if ($this->logger === null)
		{
			$this->logger = (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
		}

		return $this->logger;
	}

	/**
	 * @throws Exception
	 */
	private function create(int $userId, int $templateId, Result $result): ?IncomingWebhook
	{
		try
		{
			$createResult = (new CreateSystemIncomingWebhookCommand(
				userId: $userId,
				title: self::TITLE,
				scopes: [self::SCOPE],
				attributes: $this->buildAttributes($templateId),
			))->run();
		}
		catch (Exception)
		{
			$result->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_WEBHOOK_ERR_CREATE_FAILED'), 'WEBHOOK_CREATE_FAILED'));

			return null;
		}

		$data = $createResult->getData();
		$webhook = reset($data);

		if (
			!$createResult->isSuccess()
			|| !$webhook instanceof IncomingWebhook
			|| $webhook->getId() === null
			|| $webhook->getPassword() === ''
		)
		{
			$result->addError(new Error(Loc::getMessage('BIZPROCDESIGNER_WEBHOOK_ERR_CREATE_FAILED'), 'WEBHOOK_CREATE_FAILED'));

			return null;
		}

		return $webhook;
	}

	/**
	 * @return array<string, string>
	 */
	private function buildAttributes(int $templateId): array
	{
		return [
			self::ATTR_OWNER => 'Y',
			self::ATTR_TEMPLATE_ID => (string)$templateId,
			self::ATTR_EXPIRE_AT => (string)(time() + Feature::instance()->getAgentTokenTtl()),
		];
	}
}
