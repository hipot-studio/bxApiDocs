<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MigrationNotice;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Web\Json;

final class MigrationEntryToken
{
	public const TEXT_VERSION = '2026-10-transfer';
	public const ERROR_INVALID = 'invalid_migration_token';
	public const ERROR_EXPIRED = 'migration_token_expired';

	private const TOKEN_VERSION = 1;
	private const TOKEN_SALT = 'mail.migration_notice.entry';
	private const TOKEN_TTL = 86400;
	private const MAX_TOKEN_LENGTH = 2048;

	public function __construct(private readonly Signer $signer = new Signer())
	{
	}

	public function issue(
		int $userId,
		int $mailboxId,
		string $domain,
		NoticeSurface $surface,
		NoticeCode $code,
	): string
	{
		return $this->signer->sign(Json::encode([
			'v' => self::TOKEN_VERSION,
			'actor' => $userId,
			'mailbox' => $mailboxId,
			'domain' => $domain,
			'surface' => $surface->value,
			'notice' => $code->value,
			'textVersion' => self::TEXT_VERSION,
			'expiresAt' => time() + self::TOKEN_TTL,
		]), self::TOKEN_SALT);
	}

	/** @return Result Data: actor, mailboxId, domain, surface, code, textVersion. */
	public function verify(string $token, int $userId): Result
	{
		if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH)
		{
			return $this->invalid();
		}

		try
		{
			$data = Json::decode($this->signer->unsign($token, self::TOKEN_SALT));
		}
		catch (\Throwable)
		{
			return $this->invalid();
		}

		if (!is_array($data))
		{
			return $this->invalid();
		}

		$surface = NoticeSurface::tryFrom((string)($data['surface'] ?? ''));
		$code = NoticeCode::tryFrom((string)($data['notice'] ?? ''));
		if (
			(int)($data['v'] ?? 0) !== self::TOKEN_VERSION
			|| (int)($data['actor'] ?? 0) !== $userId
			|| $userId <= 0
			|| (int)($data['mailbox'] ?? 0) <= 0
			|| (string)($data['domain'] ?? '') === ''
			|| (string)($data['textVersion'] ?? '') !== self::TEXT_VERSION
			|| $surface !== NoticeSurface::MailboxSettings
			|| $code !== NoticeCode::MigrationVariantA
		)
		{
			return $this->invalid();
		}

		if ((int)($data['expiresAt'] ?? 0) <= time())
		{
			return (new Result())->addError(new Error('Migration token has expired.', self::ERROR_EXPIRED));
		}

		return (new Result())->setData([
			'actor' => $userId,
			'mailboxId' => (int)$data['mailbox'],
			'domain' => (string)$data['domain'],
			'surface' => $surface,
			'code' => $code,
			'textVersion' => (string)$data['textVersion'],
		]);
	}

	private function invalid(): Result
	{
		return (new Result())->addError(new Error('Migration token is invalid.', self::ERROR_INVALID));
	}
}
