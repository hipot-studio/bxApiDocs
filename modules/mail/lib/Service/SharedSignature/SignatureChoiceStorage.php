<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\SharedSignature;

/**
 * Remembers which signature a person picked for a given sender (ALG-01).
 *
 * The store is a platform user option, category 'mail', name 'signature_choice': a map of
 * "sender key" → "<signatureId>:<timestamp>". The map shape is the one CUserOptions merges key
 * by key (CUserOptions::SetOptionsFromArray), so the composer writes the very same option from
 * the client through BX.userOptions.save and no controller of its own is needed.
 *
 * A remembered choice is a preference, not a source of truth: a reference to a signature that
 * is gone is treated as no choice at all and the resolver falls back to the general order.
 */
class SignatureChoiceStorage
{
	public const OPTION_CATEGORY = 'mail';
	public const OPTION_NAME = 'signature_choice';

	/**
	 * @return array{id: int, time: int}|null
	 */
	public function get(int $userId, string $senderKey): ?array
	{
		if ($userId <= 0)
		{
			return null;
		}

		$stored = $this->readAll($userId);
		$raw = $stored[$senderKey] ?? null;

		return is_scalar($raw) ? self::decode((string)$raw) : null;
	}

	/**
	 * Every choice of the user, in the very shape the option stores it: sender key →
	 * "<signatureId>:<timestamp>". Serves a consumer that resolves the default for a sender it only
	 * learns on the client — the composer, which is given the choices of all senders at once and
	 * must not read the option itself.
	 *
	 * @return array<string, string>
	 */
	public function getAllChoices(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$choices = [];
		foreach ($this->readAll($userId) as $senderKey => $raw)
		{
			if (is_scalar($raw))
			{
				$choices[(string)$senderKey] = (string)$raw;
			}
		}

		return $choices;
	}

	/**
	 * @param int|null $time Moment of the choice; the current one by default.
	 */
	public function set(int $userId, string $senderKey, int $signatureId, ?int $time = null): void
	{
		if ($userId <= 0 || $signatureId <= 0)
		{
			return;
		}

		$stored = $this->readAll($userId);
		$stored[$senderKey] = self::encode($signatureId, $time ?? time());

		$this->writeAll($userId, $stored);
	}

	/**
	 * Rewrites the choices of the user that point at one of the given identifiers. Serves the
	 * migration: a choice stores an identifier, and before the migration that identifier belongs
	 * to the legacy table, after it — to the unified model.
	 *
	 * The moment of the choice is kept as it was: nothing about the choice itself has changed,
	 * only the identifier of the signature it points at.
	 *
	 * @param array<int, int> $map Former identifier → the current one.
	 * @return int Number of choices rewritten.
	 */
	public function remapIds(int $userId, array $map): int
	{
		if ($userId <= 0 || empty($map))
		{
			return 0;
		}

		$stored = $this->readAll($userId);

		$rewritten = 0;
		foreach ($stored as $senderKey => $raw)
		{
			$choice = is_scalar($raw) ? self::decode((string)$raw) : null;
			if ($choice === null || !isset($map[$choice['id']]))
			{
				continue;
			}

			$stored[$senderKey] = self::encode($map[$choice['id']], $choice['time']);
			++$rewritten;
		}

		if ($rewritten > 0)
		{
			$this->writeAll($userId, $stored);
		}

		return $rewritten;
	}

	public function remove(int $userId, string $senderKey): void
	{
		if ($userId <= 0)
		{
			return;
		}

		$stored = $this->readAll($userId);
		if (!array_key_exists($senderKey, $stored))
		{
			return;
		}

		unset($stored[$senderKey]);

		$this->writeAll($userId, $stored);
	}

	/**
	 * The single rule for turning a sender into a storage key. Normalization is the module-wide
	 * one, see AssignmentResolver::normalizeSenderKey(); the client keys its choices the same way.
	 */
	public static function buildSenderKey(string $email, string $name = ''): string
	{
		$email = trim($email);
		$name = trim($name);

		$sender = ($name !== '' && $email !== '') ? $name . ' <' . $email . '>' : $email;

		return AssignmentResolver::normalizeSenderKey($sender);
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function readAll(int $userId): array
	{
		$value = \CUserOptions::GetOption(self::OPTION_CATEGORY, self::OPTION_NAME, [], $userId);

		return is_array($value) ? $value : [];
	}

	/**
	 * @param array<string, mixed> $value
	 */
	protected function writeAll(int $userId, array $value): void
	{
		\CUserOptions::SetOption(self::OPTION_CATEGORY, self::OPTION_NAME, $value, false, $userId);
	}

	private static function encode(int $signatureId, int $time): string
	{
		return $signatureId . ':' . $time;
	}

	/**
	 * @return array{id: int, time: int}|null
	 */
	private static function decode(string $raw): ?array
	{
		[$id, $time] = array_pad(explode(':', $raw, 2), 2, '0');

		$id = (int)$id;

		return $id > 0 ? ['id' => $id, 'time' => (int)$time] : null;
	}
}
