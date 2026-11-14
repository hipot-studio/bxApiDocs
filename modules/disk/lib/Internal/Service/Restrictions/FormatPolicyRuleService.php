<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Restrictions;

use Bitrix\Disk\Internal\Entity\FormatPolicyRule;
use Bitrix\Disk\Internal\Interface\TeamDirectoryInterface;
use Bitrix\Disk\Internal\Repository\FormatPolicyRuleRepository;
use Bitrix\Disk\Ui\Avatar;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Result;
use Bitrix\Main\UserTable;
use Throwable;

/**
 * Saves and removes the private rules of the file format policy on behalf of an administrator.
 *
 * An addressee is an employee, a department of the intranet structure or a team of the default HR
 * structure, and it has one rule at most, whatever the scope of it.
 *
 * Every save and removal drops the rule index and the policies the resolver keeps, so the policy
 * read later in the same hit is built from the table again.
 */
class FormatPolicyRuleService
{
	public const RULES_LIMIT = 1000;

	public const ERROR_ALREADY_EXISTS = 'FORMAT_RULE_ALREADY_EXISTS';
	public const ERROR_EMPTY_APPEND = 'FORMAT_RULE_EMPTY_APPEND';
	public const ERROR_EMPTY_REPLACE_UNCONFIRMED = 'FORMAT_RULE_EMPTY_REPLACE_UNCONFIRMED';
	public const ERROR_LIMIT_EXCEEDED = 'FORMAT_RULE_LIMIT_EXCEEDED';
	public const ERROR_ADDRESSEE_INVALID = 'FORMAT_RULE_ADDRESSEE_INVALID';
	public const ERROR_NOT_FOUND = 'FORMAT_RULE_NOT_FOUND';
	public const ERROR_MODE_INVALID = 'FORMAT_RULE_MODE_INVALID';
	public const ERROR_INVALID_PRESETS = 'INVALID_PRESETS';
	public const ERROR_BUSY = 'FORMAT_RULE_BUSY';

	protected const ENTITY_TYPES = [
		FormatPolicyRule::ENTITY_TYPE_USER,
		FormatPolicyRule::ENTITY_TYPE_DEPARTMENT,
		FormatPolicyRule::ENTITY_TYPE_TEAM,
	];

	protected const MODES = [
		FormatPolicyRule::MODE_APPEND,
		FormatPolicyRule::MODE_REPLACE,
	];

	private ?bool $departmentsAvailable = null;

	/**
	 * @param EffectivePolicyResolver $policyResolver Resolver of the hit: a saved or removed rule resets it.
	 */
	public function __construct(
		protected readonly FormatPolicyRuleRepository $ruleRepository,
		protected readonly FormatPolicyRuleIndex $ruleIndex,
		protected readonly ExtensionListNormalizer $extensionListNormalizer,
		protected readonly FormatPresetMap $presetMap,
		protected readonly EffectivePolicyResolver $policyResolver,
		protected readonly FormatPolicyRuleCreationLock $creationLock,
		protected readonly TeamDirectoryInterface $teamDirectory,
	)
	{
	}

	/**
	 * Creates a rule or changes an existing one. The addressee of an existing rule stays as it is:
	 * only the scope of a department or a team may change.
	 *
	 * @param array $draft Rule as the settings interface sends it: id?, addressee{type, id, includeNested},
	 *     mode, extensions[], presets[], blockNoExtension, confirmEmptyReplace.
	 * @param int $updatedBy Administrator saving the rule.
	 * @return Result Data 'rule' holds the saved FormatPolicyRule.
	 */
	public function save(array $draft, int $updatedBy): Result
	{
		$result = new Result();

		$id = (int)($draft['id'] ?? 0);
		$existingRule = null;
		if ($id > 0)
		{
			$existingRule = $this->ruleRepository->findById($id);
			if ($existingRule === null)
			{
				return $result->addError($this->createNotFoundError($id));
			}
		}

		$addressee = is_array($draft['addressee'] ?? null) ? $draft['addressee'] : [];
		$type = (string)($addressee['type'] ?? $existingRule?->getEntityType() ?? '');
		$entityId = (int)($addressee['id'] ?? $existingRule?->getEntityId() ?? 0);
		$includeNested = self::readFlag($addressee['includeNested'] ?? false);

		if (
			$existingRule !== null
			&& ($type !== $existingRule->getEntityType() || $entityId !== $existingRule->getEntityId())
		)
		{
			return $result->addError(new Error(
				'The addressee of a rule cannot be changed.',
				self::ERROR_ADDRESSEE_INVALID,
				['reason' => 'immutable'],
			));
		}

		$mode = (string)($draft['mode'] ?? FormatPolicyRule::MODE_APPEND);
		if (!in_array($mode, self::MODES, true))
		{
			return $result->addError(new Error('Unknown mode of the rule.', self::ERROR_MODE_INVALID));
		}

		$extensionsResult = $this->extensionListNormalizer->normalize(
			is_array($draft['extensions'] ?? null) ? $draft['extensions'] : [],
		);
		$presetsResult = $this->normalizePresets(is_array($draft['presets'] ?? null) ? $draft['presets'] : []);
		if (!$extensionsResult->isSuccess() || !$presetsResult->isSuccess())
		{
			return $result
				->addErrors($extensionsResult->getErrors())
				->addErrors($presetsResult->getErrors())
			;
		}

		$extensions = $extensionsResult->getData()['extensions'];
		$blockNoExtension = self::readFlag($draft['blockNoExtension'] ?? false);
		$isEmpty = $extensions === [] && !$blockNoExtension;

		if ($isEmpty && $mode === FormatPolicyRule::MODE_APPEND)
		{
			return $result->addError(new Error(
				'An appending rule must restrict something.',
				self::ERROR_EMPTY_APPEND,
			));
		}

		if ($isEmpty && !self::readFlag($draft['confirmEmptyReplace'] ?? false))
		{
			return $result->addError(new Error(
				'An empty replacing rule must be confirmed.',
				self::ERROR_EMPTY_REPLACE_UNCONFIRMED,
			));
		}

		$accessCodeResult = $this->buildAccessCode($type, $entityId, $includeNested);
		if (!$accessCodeResult->isSuccess())
		{
			return $result->addErrors($accessCodeResult->getErrors());
		}

		$hasScope = in_array($type, [FormatPolicyRule::ENTITY_TYPE_DEPARTMENT, FormatPolicyRule::ENTITY_TYPE_TEAM], true);
		$previousAccessCode = $existingRule?->getAccessCode();
		$rule = ($existingRule ?? new FormatPolicyRule())
			->setEntityType($type)
			->setEntityId($entityId)
			->setIncludeNested($hasScope && $includeNested)
			->setAccessCode($accessCodeResult->getData()['accessCode'])
			->setMode($mode)
			->setExtensions($extensions)
			->setPresets($presetsResult->getData()['presets'])
			->setBlockNoExtension($blockNoExtension)
			->setSavedAt($this->getCurrentTimestamp())
			->setUpdatedBy($updatedBy)
		;

		if ($existingRule !== null)
		{
			return $this->store($rule, $previousAccessCode);
		}

		// The limit is checked and the rule is inserted under one lock, so concurrent creations
		// cannot all pass the check.
		if (!$this->creationLock->acquire())
		{
			return $result->addError(new Error(
				'Another rule is being created. Try again in a few seconds.',
				self::ERROR_BUSY,
			));
		}

		try
		{
			$refusal = $this->findCreationRefusal($type, $entityId);
			if ($refusal !== null)
			{
				return $result->addError($refusal);
			}

			return $this->store($rule, null);
		}
		finally
		{
			$this->creationLock->release();
		}
	}

	/**
	 * @param string|null $previousAccessCode Access code the rule had before, null for a new rule.
	 */
	private function store(FormatPolicyRule $rule, ?string $previousAccessCode): Result
	{
		$result = new Result();
		$isNew = $rule->getId() === null;

		if (!$this->isAddresseeKnown($rule->getEntityType(), $rule->getEntityId()))
		{
			return $result->addError(new Error(
				'The addressee of the rule does not exist.',
				self::ERROR_ADDRESSEE_INVALID,
				['reason' => 'missing'],
			));
		}

		try
		{
			$this->ruleRepository->save($rule);
			// the employees the rule stops covering lose it the way they lose a deleted rule
			if ($previousAccessCode !== null && $previousAccessCode !== $rule->getAccessCode())
			{
				$this->ruleIndex->markRemoved();
			}
		}
		catch (PersistenceException $exception)
		{
			// Two administrators creating a rule for one addressee at once meet at the unique key.
			$duplicate = $isNew ? $this->ruleRepository->findByAddressee($rule->getEntityType(), $rule->getEntityId()) : null;
			if ($duplicate !== null)
			{
				return $result->addError($this->createDuplicateError($duplicate));
			}

			throw $exception;
		}
		finally
		{
			$this->ruleIndex->invalidate();
			$this->policyResolver->reset();
		}

		return $result->setData(['rule' => $rule]);
	}

	/**
	 * Removes a rule for good: a removed rule is not restored and is created anew when needed.
	 */
	public function delete(int $id): Result
	{
		$result = new Result();

		$rule = $this->ruleRepository->findById($id);
		if ($rule === null)
		{
			return $result->addError($this->createNotFoundError($id));
		}

		$this->ruleRepository->delete($id);
		$this->ruleIndex->invalidate();
		$this->ruleIndex->markRemoved();
		$this->policyResolver->reset();

		return $result;
	}

	/**
	 * Keeps only the preset ids known to the module: an unknown preset is reported instead of being
	 * dropped silently, so the interface never saves a set it will not get back.
	 */
	public function normalizePresets(array $rawPresets): Result
	{
		$result = new Result();
		$knownIds = array_column($this->presetMap->getAll(), 'id');
		$presets = [];
		$unknownPresets = [];

		foreach ($rawPresets as $position => $rawPreset)
		{
			if (!is_scalar($rawPreset))
			{
				$rejected = ExtensionListNormalizer::describeRejectedItem($position, $rawPreset);
				$unknownPresets[$rejected] = $rejected;

				continue;
			}

			$preset = trim((string)$rawPreset);
			if ($preset === '')
			{
				continue;
			}

			if (in_array($preset, $knownIds, true))
			{
				$presets[$preset] = $preset;
			}
			else
			{
				$unknownPresets[$preset] = $preset;
			}
		}

		if (!empty($unknownPresets))
		{
			return $result->addError(new Error(
				'The list contains unknown presets.',
				self::ERROR_INVALID_PRESETS,
				['presets' => array_values($unknownPresets)],
			));
		}

		return $result->setData(['presets' => array_values($presets)]);
	}

	/**
	 * Describes the addressees of the rules for the settings list.
	 *
	 * An addressee is unavailable when it is gone, when the employee is deactivated, when the portal
	 * has no departments any more or when the team has no name in the default structure: such a rule
	 * applies to nobody. The names of all teams on the page are asked for at once.
	 *
	 * @param FormatPolicyRule[] $rules
	 * @return array<int, array{title: string, avatar: string|null, isAvailable: bool}> Keyed by the rule id.
	 */
	public function describeAddressees(array $rules): array
	{
		$idsByType = [];
		foreach ($rules as $rule)
		{
			$idsByType[$rule->getEntityType()][] = $rule->getEntityId();
		}

		$records = [];
		foreach ($idsByType as $type => $ids)
		{
			$records[$type] = $this->findAddresseeRecords((string)$type, $ids);
		}

		$descriptions = [];
		foreach ($rules as $rule)
		{
			$record = $records[$rule->getEntityType()][$rule->getEntityId()] ?? null;

			$descriptions[(int)$rule->getId()] = [
				'title' => (string)($record['title'] ?? ''),
				'avatar' => $record['avatar'] ?? null,
				'isAvailable' => $record !== null && $record['isAvailable'],
			];
		}

		return $descriptions;
	}

	/**
	 * @return array{title: string, avatar: string|null, isAvailable: bool}|null The employee, null when there is none.
	 */
	public function describeUser(int $userId): ?array
	{
		return $this->findAddresseeRecords(FormatPolicyRule::ENTITY_TYPE_USER, [$userId])[$userId] ?? null;
	}

	/**
	 * Departments are the sections of the intranet structure iblock; a portal without it addresses
	 * employees only.
	 */
	public function areDepartmentsAvailable(): bool
	{
		return $this->departmentsAvailable ??= $this->detectDepartmentsAvailability();
	}

	/**
	 * Tells the settings page whether the default structure has teams to address.
	 */
	public function areTeamsAvailable(): bool
	{
		return $this->teamDirectory->hasTeams();
	}

	/**
	 * Reads a flag the way the interface may send it: a boolean, a number or a string of a form.
	 */
	public static function readFlag(mixed $value): bool
	{
		if (is_bool($value))
		{
			return $value;
		}

		if (is_int($value))
		{
			return $value === 1;
		}

		return is_string($value) && in_array(mb_strtolower(trim($value)), ['1', 'true', 'y', 'yes'], true);
	}

	protected function findCreationRefusal(string $type, int $entityId): ?Error
	{
		$duplicate = $this->ruleRepository->findByAddressee($type, $entityId);
		if ($duplicate !== null)
		{
			return $this->createDuplicateError($duplicate);
		}

		$limit = $this->getRulesLimit();
		// the rules of deactivated employees are not listed, so they do not take the limit either
		if ($this->ruleRepository->countListed() >= $limit)
		{
			return new Error(
				'The portal has as many private rules as it may have.',
				self::ERROR_LIMIT_EXCEEDED,
				['limit' => $limit],
			);
		}

		return null;
	}

	protected function createDuplicateError(FormatPolicyRule $duplicate): Error
	{
		return new Error(
			'The addressee already has a rule.',
			self::ERROR_ALREADY_EXISTS,
			['ruleId' => (int)$duplicate->getId()],
		);
	}

	protected function createNotFoundError(int $id): Error
	{
		return new Error('The rule is not found.', self::ERROR_NOT_FOUND, ['ruleId' => $id]);
	}

	protected function buildAccessCode(string $type, int $entityId, bool $includeNested): Result
	{
		$result = new Result();

		if (!in_array($type, self::ENTITY_TYPES, true))
		{
			return $result->addError(new Error(
				'Unknown type of the addressee.',
				self::ERROR_ADDRESSEE_INVALID,
				['reason' => 'type'],
			));
		}

		if ($type === FormatPolicyRule::ENTITY_TYPE_DEPARTMENT && !$this->areDepartmentsAvailable())
		{
			return $result->addError(new Error(
				'The portal has no departments to address.',
				self::ERROR_ADDRESSEE_INVALID,
				['reason' => 'unavailable'],
			));
		}

		if ($type === FormatPolicyRule::ENTITY_TYPE_TEAM)
		{
			if (!$this->teamDirectory->isModuleAvailable())
			{
				return $result->addError(new Error(
					'The portal has no teams to address.',
					self::ERROR_ADDRESSEE_INVALID,
					['reason' => 'unavailable'],
				));
			}

			if (!$this->teamDirectory->isTeamOfDefaultStructure($entityId))
			{
				return $result->addError(new Error(
					'The addressee of the rule does not exist.',
					self::ERROR_ADDRESSEE_INVALID,
					['reason' => 'missing'],
				));
			}
		}

		try
		{
			$accessCode = AddresseeAccessCode::build($type, $entityId, $includeNested);
		}
		catch (ArgumentException)
		{
			return $result->addError(new Error(
				'The addressee of the rule does not exist.',
				self::ERROR_ADDRESSEE_INVALID,
				['reason' => 'missing'],
			));
		}

		return $result->setData(['accessCode' => $accessCode]);
	}

	protected function isAddresseeKnown(string $type, int $entityId): bool
	{
		// buildAccessCode has already checked the team with the adapter
		if ($type === FormatPolicyRule::ENTITY_TYPE_TEAM)
		{
			return true;
		}

		return isset($this->findAddresseeRecords($type, [$entityId])[$entityId]);
	}

	/**
	 * Finds the addressees of one type.
	 *
	 * @param string $type One of FormatPolicyRule::ENTITY_TYPE_* constants.
	 * @param int[] $ids
	 * @return array<int, array{title: string, avatar: string|null, isAvailable: bool}> Addressees found, keyed by id.
	 */
	protected function findAddresseeRecords(string $type, array $ids): array
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
		if ($ids === [])
		{
			return [];
		}

		try
		{
			return match ($type)
			{
				FormatPolicyRule::ENTITY_TYPE_USER => $this->findUsers($ids),
				FormatPolicyRule::ENTITY_TYPE_DEPARTMENT => $this->areDepartmentsAvailable() ? $this->findIblockSections($ids) : [],
				FormatPolicyRule::ENTITY_TYPE_TEAM => $this->findTeams($ids),
				default => [],
			};
		}
		catch (Throwable)
		{
			return [];
		}
	}

	protected function findUsers(array $ids): array
	{
		$rows = UserTable::query()
			->setSelect(['ID', 'ACTIVE', 'LOGIN', 'EMAIL', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'PERSONAL_PHOTO'])
			->whereIn('ID', $ids)
			->fetchAll()
		;

		Avatar::preload(array_column($rows, 'PERSONAL_PHOTO'));

		$records = [];
		foreach ($rows as $row)
		{
			$records[(int)$row['ID']] = [
				'title' => (string)\CUser::formatName(\CSite::getNameFormat(), $row, true, false),
				'avatar' => Avatar::getSrc((int)$row['PERSONAL_PHOTO']) ?: null,
				'isAvailable' => $row['ACTIVE'] === 'Y',
			];
		}

		return $records;
	}

	protected function findTeams(array $ids): array
	{
		$records = [];
		foreach ($this->teamDirectory->findTeamNames($ids) as $id => $name)
		{
			$records[(int)$id] = [
				'title' => (string)$name,
				'avatar' => null,
				'isAvailable' => true,
			];
		}

		return $records;
	}

	protected function findIblockSections(array $ids): array
	{
		$iblockId = $this->getStructureIblockId();
		if ($iblockId <= 0 || !Loader::includeModule('iblock'))
		{
			return [];
		}

		$rows = SectionTable::query()
			->setSelect(['ID', 'NAME', 'ACTIVE'])
			->where('IBLOCK_ID', $iblockId)
			->whereIn('ID', $ids)
			->fetchAll()
		;

		$records = [];
		foreach ($rows as $row)
		{
			$records[(int)$row['ID']] = [
				'title' => (string)$row['NAME'],
				'avatar' => null,
				'isAvailable' => $row['ACTIVE'] === 'Y',
			];
		}

		return $records;
	}

	/**
	 * The index of the rules is read on every write, so their number is bounded (ADR, question 3).
	 */
	public function getRulesLimit(): int
	{
		return static::RULES_LIMIT;
	}

	protected function detectDepartmentsAvailability(): bool
	{
		return Loader::includeModule('intranet') && $this->getStructureIblockId() > 0;
	}

	protected function getStructureIblockId(): int
	{
		return (int)Option::get('intranet', 'iblock_structure', 0);
	}

	protected function getCurrentTimestamp(): int
	{
		return time();
	}
}
