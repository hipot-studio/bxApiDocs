<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Main\Security\Sign\TimeSigner;
use Bitrix\Main\UuidGenerator;
use Bitrix\Main\Web\Json;
use InvalidArgumentException;
use Throwable;

final class AuditPageInstanceTokenCodec
{
	public const PURPOSE = 'disk.audit.page.open.v1';
	public const TTL_SECONDS = 900;
	public const MAX_LENGTH = 1024;

	private const VERSION = 1;
	private const INSTANCE_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

	public function __construct(
		private readonly TimeSigner $signer = new TimeSigner(),
		private readonly mixed $clock = null,
	)
	{
	}

	public function issue(int $userId): string
	{
		if ($userId <= 0)
		{
			throw new InvalidArgumentException('Disk audit page instance user is invalid.');
		}

		$issuedAt = $this->getCurrentTimestamp();
		$payload = Json::encode([
			'v' => self::VERSION,
			'p' => self::PURPOSE,
			'userId' => $userId,
			'instanceId' => UuidGenerator::generateV4(),
			'issuedAt' => $issuedAt,
		]);
		$token = $this->signer->sign(
			$payload,
			$issuedAt + self::TTL_SECONDS,
			self::PURPOSE,
		);

		if (strlen($token) > self::MAX_LENGTH)
		{
			throw new InvalidArgumentException('Disk audit page instance token is too long.');
		}

		return $token;
	}

	/**
	 * @return array{instanceId: string, issuedAt: int}
	 */
	public function verify(string $token, int $userId): array
	{
		if ($token === '' || strlen($token) > self::MAX_LENGTH || $userId <= 0)
		{
			throw new InvalidArgumentException('Disk audit page instance token is invalid.');
		}

		try
		{
			$payload = Json::decode($this->signer->unsign($token, self::PURPOSE));
			if (!$this->isValidPayload($payload, $userId))
			{
				throw new InvalidArgumentException('Disk audit page instance payload is invalid.');
			}

			return [
				'instanceId' => $payload['instanceId'],
				'issuedAt' => $payload['issuedAt'],
			];
		}
		catch (Throwable $exception)
		{
			throw new InvalidArgumentException('Disk audit page instance token is invalid.', previous: $exception);
		}
	}

	private function isValidPayload(mixed $payload, int $userId): bool
	{
		if (
			!is_array($payload)
			|| !isset(
				$payload['v'],
				$payload['p'],
				$payload['userId'],
				$payload['instanceId'],
				$payload['issuedAt'],
			)
			|| !is_int($payload['v'])
			|| !is_string($payload['p'])
			|| !is_int($payload['userId'])
			|| !is_string($payload['instanceId'])
			|| !is_int($payload['issuedAt'])
		)
		{
			return false;
		}

		$now = $this->getCurrentTimestamp();

		return $payload['v'] === self::VERSION
			&& $payload['p'] === self::PURPOSE
			&& $payload['userId'] === $userId
			&& preg_match(self::INSTANCE_ID_PATTERN, $payload['instanceId']) === 1
			&& $payload['issuedAt'] <= $now
			&& $now <= $payload['issuedAt'] + self::TTL_SECONDS;
	}

	private function getCurrentTimestamp(): int
	{
		return is_callable($this->clock) ? (int)($this->clock)() : time();
	}
}
