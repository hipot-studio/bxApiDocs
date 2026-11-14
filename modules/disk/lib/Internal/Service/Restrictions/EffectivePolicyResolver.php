<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Restrictions;

use Bitrix\Disk\Internal\Entity\FormatPolicyRule;
use Bitrix\Disk\Internal\Repository\FormatPolicyRuleRepository;
use Bitrix\Main\Loader;
use CAccess;

/**
 * Works out the format policy of the user performing a write.
 *
 * The private rules of a user are the ones whose access code the user holds. Of them only the first
 * non-empty level counts - employee, then team, then department - and a level with a replacing rule
 * leaves the global rule out for the whole level. A portal without private rules pays nothing over
 * the global rule: the codes of the user are not even read.
 *
 * One resolver serves the whole hit and keeps the policy of every user it was asked about. A change
 * of the private rules in the hit asks for a reset explicitly; a save of the global rule is seen by
 * the resolver itself in the snapshot of the storage, whoever saved it.
 */
class EffectivePolicyResolver
{
	protected const LEVEL_ORDER = [
		AddresseeAccessCode::LEVEL_USER,
		AddresseeAccessCode::LEVEL_TEAM,
		AddresseeAccessCode::LEVEL_DEPARTMENT,
	];

	/** @var array<int, EffectivePolicy> */
	private array $policies = [];
	private ?EffectivePolicy $globalPolicy = null;
	private ?array $globalSnapshot = null;
	private ?bool $departmentsAvailable = null;
	private ?bool $teamsAvailable = null;

	public function __construct(
		protected readonly FormatPolicyRuleIndex $ruleIndex,
		protected readonly FormatPolicyStorage $policyStorage,
		protected readonly FormatPolicyRuleRepository $ruleRepository,
	)
	{
	}

	/**
	 * @param int|null $userId User performing the write; none or the system user get the global rule.
	 * @return EffectivePolicy
	 */
	public function resolve(?int $userId): EffectivePolicy
	{
		$this->forgetPoliciesOfChangedGlobalRule();

		$userId = (int)$userId;
		if ($userId <= 0 || $this->ruleIndex->isEmpty())
		{
			return $this->getGlobalPolicy();
		}

		return $this->policies[$userId] ??= $this->resolveForUser($userId);
	}

	/**
	 * Forgets the policies kept for the hit, so the next resolve reads the rules again.
	 */
	public function reset(): void
	{
		$this->policies = [];
		$this->globalPolicy = null;
		$this->globalSnapshot = null;
	}

	/**
	 * The storage keeps the parsed snapshot until the global rule is saved, so the same snapshot
	 * compares identical at no cost, and a saved one drops every policy built over the old one.
	 */
	private function forgetPoliciesOfChangedGlobalRule(): void
	{
		$snapshot = $this->policyStorage->read();
		if ($this->globalSnapshot !== null && $this->globalSnapshot !== $snapshot)
		{
			$this->reset();
		}

		$this->globalSnapshot = $snapshot;
	}

	protected function resolveForUser(int $userId): EffectivePolicy
	{
		$levelRules = $this->pickFirstLevel($this->groupAddresseeCodesByLevel($this->getUserAccessCodes($userId)));
		$rules = $levelRules === [] ? [] : $this->ruleRepository->findByIds(array_keys($levelRules));

		// An index behind the table may still name a removed rule; a rule that is gone restricts
		// nothing and replaces nothing.
		$levelRules = array_intersect_key($levelRules, $rules);
		if ($levelRules === [])
		{
			return $this->getGlobalPolicy();
		}

		$usesGlobal = true;
		$blockNoExtension = false;
		$changedAt = $this->ruleIndex->getRemovedAt();
		foreach ($levelRules as $rule)
		{
			$usesGlobal = $usesGlobal && $rule['mode'] !== FormatPolicyRule::MODE_REPLACE;
			$blockNoExtension = $blockNoExtension || $rule['blockNoExtension'];
			$changedAt = max($changedAt, $rule['savedAt']);
		}

		$extensionMap = [];
		if ($usesGlobal)
		{
			$global = $this->policyStorage->read();
			$extensionMap = array_fill_keys($global['extensions'], true);
			$blockNoExtension = $blockNoExtension || $global['blockNoExtension'];
			$changedAt = max($changedAt, $global['savedAt']);
		}

		$ruleIds = array_keys($levelRules);
		foreach ($rules as $rule)
		{
			$extensionMap += array_fill_keys($rule->getExtensions(), true);
		}

		return new EffectivePolicy(
			extensionMap: $extensionMap,
			blockNoExtension: $blockNoExtension,
			changedAt: $changedAt,
			level: current($levelRules)['level'],
			usesGlobal: $usesGlobal,
			ruleIds: $ruleIds,
		);
	}

	protected function getGlobalPolicy(): EffectivePolicy
	{
		if ($this->globalPolicy === null)
		{
			$global = $this->policyStorage->read();

			$this->globalPolicy = new EffectivePolicy(
				extensionMap: array_fill_keys($global['extensions'], true),
				blockNoExtension: $global['blockNoExtension'],
				changedAt: max($global['savedAt'], $this->ruleIndex->getRemovedAt()),
				level: EffectivePolicy::LEVEL_GLOBAL,
				usesGlobal: true,
				ruleIds: [],
			);
		}

		return $this->globalPolicy;
	}

	/**
	 * Groups the codes that may address a rule on this portal by the level their prefix gives.
	 *
	 * @param string[] $accessCodes
	 * @return array<string, string[]>
	 */
	protected function groupAddresseeCodesByLevel(array $accessCodes): array
	{
		$departmentsAvailable = $this->areDepartmentsAvailable();
		$teamsAvailable = $this->areTeamsAvailable();

		$codesByLevel = [];
		foreach ($accessCodes as $code)
		{
			$level = is_string($code) ? AddresseeAccessCode::levelOf($code, $departmentsAvailable, $teamsAvailable) : null;
			if ($level !== null)
			{
				$codesByLevel[$level][] = $code;
			}
		}

		return $codesByLevel;
	}

	/**
	 * @param array<string, string[]> $codesByLevel
	 * @return array<int, array{id: int, level: string, mode: string, blockNoExtension: bool, savedAt: int}>
	 */
	protected function pickFirstLevel(array $codesByLevel): array
	{
		foreach (static::LEVEL_ORDER as $level)
		{
			$levelRules = $this->ruleIndex->match($codesByLevel[$level] ?? []);
			if ($levelRules !== [])
			{
				ksort($levelRules);

				return array_map(static fn(array $rule): array => ['level' => $level] + $rule, $levelRules);
			}
		}

		return [];
	}

	protected function areDepartmentsAvailable(): bool
	{
		return $this->departmentsAvailable ??= $this->detectDepartmentsAvailability();
	}

	protected function detectDepartmentsAvailability(): bool
	{
		return Loader::includeModule('intranet');
	}

	/**
	 * Team codes are only trusted while the module is installed: rows of b_user_access outlive it.
	 */
	protected function areTeamsAvailable(): bool
	{
		return $this->teamsAvailable ??= $this->detectTeamsAvailability();
	}

	protected function detectTeamsAvailability(): bool
	{
		return Loader::includeModule('humanresources');
	}

	/**
	 * @return string[]
	 */
	protected function getUserAccessCodes(int $userId): array
	{
		return CAccess::GetUserCodesArray($userId);
	}
}
