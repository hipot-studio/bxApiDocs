<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Restrictions;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Entity\FormatPolicyRule;
use Bitrix\Disk\Internal\Repository\FormatPolicyRuleRepository;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\ManagedCache;

/**
 * Compact index of the private format rules: access code of the addressee to the rule, without the
 * lists of extensions. It is asked on every write, so it lives in the managed cache and is kept for
 * the hit once read; a portal without rules caches the empty index too and pays no query for it.
 *
 * The portal stamp of rule removal lives next to it: a removed rule leaves nothing in the index to
 * take the time of the change from, and an editing session must still learn the policy changed.
 */
class FormatPolicyRuleIndex
{
	public const REMOVED_AT_OPTION_NAME = 'disk_restrictions_format_rules_removed_at';

	protected const CACHE_TTL = 86400;
	protected const CACHE_ID = 'disk_format_policy_rule_index_v1';
	protected const CACHE_TABLE_ID = 'b_disk_format_policy_rule';

	private ?array $index = null;

	public function __construct(
		protected readonly ManagedCache $managedCache,
		protected readonly FormatPolicyRuleRepository $ruleRepository,
	)
	{
	}

	/**
	 * Tells whether the portal has no private rules at all.
	 */
	public function isEmpty(): bool
	{
		return $this->getIndex() === [];
	}

	/**
	 * Returns the rules whose addressee is one of the access codes, keyed by the rule id.
	 *
	 * @param string[] $accessCodes
	 * @return array<int, array{id: int, level: string, mode: string, blockNoExtension: bool, savedAt: int}>
	 */
	public function match(array $accessCodes): array
	{
		$index = $this->getIndex();
		if ($index === [])
		{
			return [];
		}

		$matched = [];
		foreach ($accessCodes as $accessCode)
		{
			$row = $index[$accessCode] ?? null;
			if ($row !== null)
			{
				$matched[$row['id']] = $row;
			}
		}

		return $matched;
	}

	/**
	 * Drops the cached index: the next read builds it from the table again.
	 */
	public function invalidate(): void
	{
		$this->managedCache->clean(self::CACHE_ID, self::CACHE_TABLE_ID);
		$this->index = null;
	}

	/**
	 * Returns the moment the last private rule was removed, 0 when none ever was.
	 */
	public function getRemovedAt(): int
	{
		return (int)$this->readRemovedAtOption();
	}

	public function markRemoved(): void
	{
		$this->writeRemovedAtOption((string)$this->getCurrentTimestamp());
	}

	protected function getIndex(): array
	{
		return $this->index ??= $this->readIndex();
	}

	protected function readIndex(): array
	{
		if ($this->managedCache->read(self::CACHE_TTL, self::CACHE_ID, self::CACHE_TABLE_ID))
		{
			$cached = $this->managedCache->get(self::CACHE_ID);
			if (is_array($cached))
			{
				return $cached;
			}
		}

		$index = $this->buildIndex();
		$this->managedCache->setImmediate(self::CACHE_ID, $index);

		return $index;
	}

	protected function buildIndex(): array
	{
		$index = [];
		foreach ($this->ruleRepository->loadIndexRows() as $row)
		{
			$level = $this->resolveLevel($row['entityType']);
			if ($level === null)
			{
				continue;
			}

			$index[$row['accessCode']] = [
				'id' => $row['id'],
				'level' => $level,
				'mode' => $row['mode'],
				'blockNoExtension' => $row['blockNoExtension'],
				'savedAt' => $row['savedAt'],
			];
		}

		return $index;
	}

	protected function resolveLevel(string $entityType): ?string
	{
		return match ($entityType)
		{
			FormatPolicyRule::ENTITY_TYPE_USER => AddresseeAccessCode::LEVEL_USER,
			FormatPolicyRule::ENTITY_TYPE_TEAM => AddresseeAccessCode::LEVEL_TEAM,
			FormatPolicyRule::ENTITY_TYPE_DEPARTMENT => AddresseeAccessCode::LEVEL_DEPARTMENT,
			default => null,
		};
	}

	protected function getCurrentTimestamp(): int
	{
		return time();
	}

	protected function readRemovedAtOption(): string
	{
		return (string)Option::get(Driver::INTERNAL_MODULE_ID, self::REMOVED_AT_OPTION_NAME, '0');
	}

	protected function writeRemovedAtOption(string $value): void
	{
		Option::set(Driver::INTERNAL_MODULE_ID, self::REMOVED_AT_OPTION_NAME, $value);
	}
}
