<?php

namespace Bitrix\MailMobile\Internal\Integration\Mail;

use Bitrix\Mail\Internals\UserSignatureTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

class SignatureProvider
{
	/** @var array<int, array<string, string>> [userId => [normalizedSender => signature]] */
	private array $signaturesByUser = [];

	/** @var array<int, true> */
	private array $warmedUsers = [];

	/**
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function getSignature(string $email, string $name, ?int $userId = null): string
	{
		$userId = $this->resolveUserId($userId);
		$this->warmUp($userId);

		$nameEmailKey = $this->normalizeSender(trim($name) . ' <' . trim($email) . '>');
		if (isset($this->signaturesByUser[$userId][$nameEmailKey]))
		{
			return $this->signaturesByUser[$userId][$nameEmailKey];
		}

		$emailKey = $this->normalizeSender(trim($email));
		if (isset($this->signaturesByUser[$userId][$emailKey]))
		{
			return $this->signaturesByUser[$userId][$emailKey];
		}

		return $this->getGeneralSignature($userId);
	}

	/**
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	public function getGeneralSignature(?int $userId = null): string
	{
		$userId = $this->resolveUserId($userId);
		$this->warmUp($userId);

		return $this->signaturesByUser[$userId][''] ?? '';
	}

	private function resolveUserId(?int $userId): int
	{
		if ($userId)
		{
			return $userId;
		}

		return (int)CurrentUser::get()->getId();
	}

	/**
	 * Mirrors how the old SQL filter '=SENDER' compared on MySQL: a *_ci
	 * collation is case-insensitive and VARCHAR PADSPACE semantics ignore
	 * trailing spaces. The key must be normalized identically on warm-up and on
	 * lookup; otherwise the strict isset() silently drops a personal signature
	 * whenever the stored SENDER differs from the recomputed key by case or
	 * trailing whitespace.
	 */
	private function normalizeSender(string $sender): string
	{
		return mb_strtolower(rtrim($sender, ' '));
	}

	/**
	 * @throws ArgumentException|ObjectPropertyException|SystemException
	 */
	private function warmUp(int $userId): void
	{
		if (isset($this->warmedUsers[$userId]))
		{
			return;
		}

		$this->signaturesByUser[$userId] = [];

		$rows = UserSignatureTable::getList([
			'select' => ['SENDER', 'SIGNATURE'],
			'filter' => ['=USER_ID' => $userId],
			'order' => ['ID' => 'ASC'],
		]);

		while ($row = $rows->fetch())
		{
			if ($row['SENDER'] === null || $row['SIGNATURE'] === null)
			{
				continue;
			}

			$this->signaturesByUser[$userId][$this->normalizeSender((string)$row['SENDER'])] = (string)$row['SIGNATURE'];
		}

		$this->warmedUsers[$userId] = true;
	}
}
