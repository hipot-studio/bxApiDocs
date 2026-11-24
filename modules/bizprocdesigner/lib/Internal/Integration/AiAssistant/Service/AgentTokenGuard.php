<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Install\LegacyAgentTokenMigratorAgent;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\WebhookType;
use Bitrix\Rest\Internal\Repository\IncomingWebhookRepository;

/**
 * Judges the authorizing agent webhook token anew on every REST call, past the day-long auth cache of rest.
 *
 * That cache holds token validity for up to a day, so a revoked or expired token can still reach the
 * controller. This guard reads the token straight from the rest Internal repository - the only source
 * carrying external attributes - and re-validates the (owner, template) binding on each invocation. It is
 * the single place in bizprocdesigner that reads that slice.
 *
 * Kept between judgments is the row, not the verdicts: one call judges the same token more than once while
 * a read of it costs three queries {@see IncomingWebhookRepository::getById()}. Status, binding, expiration
 * and scope are computed from the row every time, so the read lives one request and the verdicts none.
 */
final class AgentTokenGuard
{
	private const ATTR_OWNER = 'bizprocdesigner';
	private const ATTR_TEMPLATE_ID = 'template_id';
	private const ATTR_EXPIRE_AT = 'expire_at';

	private static ?self $currentRequestGuard = null;

	private readonly IncomingWebhookRepository $repository;

	/**
	 * Tokens read within this request, by password id. A null is a token that was read and found to be
	 * none of ours, which array_key_exists() tells apart from one that has not been read at all.
	 *
	 * @var array<string, ?IncomingWebhook>
	 */
	private array $readTokens = [];

	public function __construct(?IncomingWebhookRepository $repository = null)
	{
		$this->repository = $repository ?? new IncomingWebhookRepository();
	}

	/**
	 * The guard of the current request, shared by everything that judges the same token within it. A guard
	 * built directly keeps its own reads instead, which is what a caller acting on tokens it changes itself
	 * - a test - needs.
	 */
	public static function forCurrentRequest(): self
	{
		return self::$currentRequestGuard ??= new self();
	}

	/**
	 * Drops the shared guard and everything it has read. The product needs no such call - the request ends
	 * and the process forgets it - but a test outliving a request does.
	 */
	public static function resetCurrentRequest(): void
	{
		self::$currentRequestGuard = null;
	}

	/**
	 * Confirms that the token identified by $passwordId is our active System webhook whose
	 * external attributes bind it to $templateId and whose expiration is still in the future.
	 *
	 * Any failure (missing password id, unknown/inactive/non-System token, missing owner marker,
	 * mismatched or expired binding) means the caller must reject the request.
	 */
	public function isValidForTemplate(?string $passwordId, int $templateId): bool
	{
		$webhook = $this->resolveOwnedToken($passwordId);
		if ($webhook === null)
		{
			return false;
		}

		if ($webhook->getExternalAttribute(self::ATTR_TEMPLATE_ID)?->getValue() !== (string)$templateId)
		{
			return false;
		}

		return $this->isNotExpired($webhook);
	}

	/**
	 * Returns the template id the token identified by $passwordId is bound to, or null when the
	 * token is not our active, non-expired System webhook or carries no template binding.
	 *
	 * Used by endpoints that have no templateId of their own (e.g. template.list) to scope the
	 * response to exactly the bound template.
	 */
	public function getBoundTemplateId(?string $passwordId): ?int
	{
		$webhook = $this->resolveOwnedToken($passwordId);
		if ($webhook === null || !$this->isNotExpired($webhook))
		{
			return null;
		}

		$boundTemplateId = $webhook->getExternalAttribute(self::ATTR_TEMPLATE_ID)?->getValue();
		if ($boundTemplateId === null || $boundTemplateId === '')
		{
			return null;
		}

		return (int)$boundTemplateId;
	}

	/**
	 * Whether the credential is one of the contour at all - a System webhook carrying our owner marker -
	 * regardless of whether it is still active and still within its expiry.
	 *
	 * Blind to both on purpose. The entry refuses such a credential BECAUSE it is one: a token issued for
	 * template A must not reach the action that issues tokens, or it walks out with one for template B and
	 * the binding is undone. Judging it by validity would reopen that door the hour a token goes stale,
	 * and a stale token is exactly what a caller retries with.
	 */
	public function isContourCredential(?string $passwordId): bool
	{
		if ($passwordId === null || $passwordId === '')
		{
			return false;
		}

		$webhook = $this->readToken($passwordId);

		return $webhook !== null
			&& $webhook->getType() === WebhookType::System
			&& $webhook->getExternalAttribute(self::ATTR_OWNER)?->getValue() === 'Y'
		;
	}

	/**
	 * Whether the webhook has a row at all. rest has just authorized the call by it, so a missing row is a
	 * webhook deleted mid-request - and reading that as "no owner marker, so an application key" would strip
	 * a token of the contour of its binding in that window.
	 */
	public function isKnownCredential(?string $passwordId): bool
	{
		if ($passwordId === null || $passwordId === '')
		{
			return false;
		}

		return $this->readToken($passwordId) !== null;
	}

	/**
	 * Whether the webhook is a legacy token of the external agent
	 * {@see LegacyAgentTokenMigratorAgent::isLegacyToken()}: issued for one template, it carries no binding,
	 * and as an application key it would reach every template of its owner. Judged off the same row: the
	 * repository reads the scope together with it.
	 */
	public function isLegacyAgentToken(?string $passwordId): bool
	{
		if ($passwordId === null || $passwordId === '')
		{
			return false;
		}

		$webhook = $this->readToken($passwordId);

		return $webhook !== null && LegacyAgentTokenMigratorAgent::isLegacyToken($webhook);
	}

	/**
	 * Re-reads the token and confirms it is an active System webhook carrying our owner marker.
	 * Returns null when the token cannot be trusted as ours.
	 */
	private function resolveOwnedToken(?string $passwordId): ?IncomingWebhook
	{
		if ($passwordId === null || $passwordId === '')
		{
			return null;
		}

		$webhook = $this->readToken($passwordId);
		if (
			$webhook === null
			|| !$webhook->isActive()
			|| $webhook->getType() !== WebhookType::System
			|| $webhook->getExternalAttribute(self::ATTR_OWNER)?->getValue() !== 'Y'
		)
		{
			return null;
		}

		return $webhook;
	}

	private function readToken(string $passwordId): ?IncomingWebhook
	{
		if (!array_key_exists($passwordId, $this->readTokens))
		{
			$this->readTokens[$passwordId] = $this->repository->getById((int)$passwordId);
		}

		return $this->readTokens[$passwordId];
	}

	private function isNotExpired(IncomingWebhook $webhook): bool
	{
		$expireAt = $webhook->getExternalAttribute(self::ATTR_EXPIRE_AT)?->getValue();
		if ($expireAt === null || $expireAt === '')
		{
			return false;
		}

		return (int)$expireAt > time();
	}
}
