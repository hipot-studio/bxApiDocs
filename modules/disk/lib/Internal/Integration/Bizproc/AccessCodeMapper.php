<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Integration\Bizproc;

use Bitrix\HumanResources\Public\Service\Container as HumanResourcesContainer;
use Bitrix\Main\Loader;

/**
 * The recipients of the activity in the access codes disk writes rights in.
 *
 * A property of the type user answers in the codes of bizproc (user_1, group_D5, group_HR7, a bare number
 * for a group of the portal), and a right of disk is written on the codes of the portal (U1, DR5, G1).
 * Disk knows a department only recursively, so both kinds of a department code end at DR; a value written
 * under such a wider code is named in {@see AccessCodeMapperResult::$widened}, because the right then
 * reaches more people than the template chose. A code nothing here parses is handed to bizproc itself -
 * a group of the document, "the author" for one, is legal and only it can unfold them - and dropped with
 * a reason when that fails too, never silently.
 */
final class AccessCodeMapper
{
	public const SKIP_REASON_UNKNOWN_CODE = 'unknown_code';
	public const SKIP_REASON_EMPTY_HR_NODE = 'empty_hr_node';
	public const SKIP_REASON_TOO_MANY_USERS = 'too_many_users';
	public const SKIP_REASON_TOO_MANY_CODES = 'too_many_codes';

	private const USER_PREFIX = 'user_';
	private const GROUP_PREFIX = 'group_';

	/**
	 * The roles of a workgroup a right of disk is written on, from the highest to the lowest: owner,
	 * moderator, member. A right is written on the very role a person holds in the group, so a group is
	 * only reached in full by naming the three of them.
	 */
	private const SOCNET_ROLES_FROM_HIGHEST = ['A', 'E', 'K'];

	private const DEFAULT_SOCNET_ROLE = 'K';

	/**
	 * A value that unfolds into people rather than into a code of its own - a node of the structure that
	 * is no department, a group of the document - is written as a row per person. Past this many rows the
	 * recipient is dropped with a reason instead: a command naming a whole branch of the company would
	 * otherwise rewrite the rights of the object and rebuild its subtree for hundreds of codes at once.
	 */
	private const MAX_EXPANDED_USERS = 300;

	/**
	 * The limit above holds one recipient alone, and the rights are written for all of them at once: a
	 * template naming enough teams of allowed size still ends in tens of thousands of codes, and every one
	 * of them costs a reading of the rights of the path and a place in the set disk deduplicates pairwise
	 * before the write, all of it under the lock of the object. Past this many codes in total the recipient
	 * that would cross the line is dropped with a reason, and the ones already gathered keep their right.
	 */
	private const MAX_TOTAL_CODES = 1000;

	/** @var array<string, string> */
	private array $skipped = [];

	/** @var array<string, string> */
	private array $widened = [];

	/** @var array<int, int> */
	private array $departmentIdByNodeId = [];

	/**
	 * @param array $documentId Identifier of the document of the process, needed to unfold its own groups.
	 */
	public function __construct(private readonly array $documentId = [])
	{
	}

	/**
	 * @param array $values Values of a property of the type user.
	 */
	public function map(array $values): AccessCodeMapperResult
	{
		$this->skipped = [];
		$this->widened = [];
		$this->departmentIdByNodeId = [];

		$codes = [];
		foreach ($this->normalizeValues($values) as $value)
		{
			$valueCodes = $this->mapValue($value);
			if ($valueCodes === [])
			{
				continue;
			}

			// One recipient is counted by what it adds and not by what it names: two teams sharing a person
			// cost one code, and the limit is only crossed by the codes nobody has brought yet.
			$grown = $codes;
			foreach ($valueCodes as $code)
			{
				$grown[$code] = $code;
			}

			if (count($grown) > self::MAX_TOTAL_CODES)
			{
				unset($this->widened[$value]);
				$this->skip($value, self::SKIP_REASON_TOO_MANY_CODES);

				continue;
			}

			$codes = $grown;
		}

		return new AccessCodeMapperResult(
			array_values($codes),
			$this->skipped,
			$this->widened,
		);
	}

	/**
	 * One recipient named twice is one recipient: every repetition would otherwise cost its own reading of
	 * the structure and its own unfolding into people.
	 *
	 * @return string[]
	 */
	private function normalizeValues(array $values): array
	{
		$normalized = [];
		foreach ($values as $value)
		{
			if (!is_string($value) && !is_int($value))
			{
				continue;
			}

			$value = trim((string)$value);
			if ($value !== '')
			{
				$normalized[$value] = $value;
			}
		}

		return array_values($normalized);
	}

	/** @return string[] */
	private function mapValue(string $value): array
	{
		if (preg_match('/^\d+$/', $value))
		{
			// A bare number in a field of the type user is a group of the portal
			// (@see \CBPHelper::convertToExtendedGroups), not a person.
			$groupId = (int)$value;

			return $groupId > 0 ? ['G' . $groupId] : $this->skip($value, self::SKIP_REASON_UNKNOWN_CODE);
		}

		$lowerValue = mb_strtolower($value);

		if (str_starts_with($lowerValue, self::USER_PREFIX))
		{
			$userId = (int)mb_substr($value, mb_strlen(self::USER_PREFIX));

			return $userId > 0 ? ['U' . $userId] : $this->skip($value, self::SKIP_REASON_UNKNOWN_CODE);
		}

		if (str_starts_with($lowerValue, self::GROUP_PREFIX))
		{
			return $this->mapGroupValue($value);
		}

		return $this->extractUsersOfDocumentGroup($value);
	}

	/** @return string[] */
	private function mapGroupValue(string $value): array
	{
		$code = mb_strtoupper(mb_substr($value, mb_strlen(self::GROUP_PREFIX)));

		if ($code === 'AU' || $code === 'UA')
		{
			return ['AU'];
		}

		// Three shapes of one and the same person: a user, a user of the intranet and a user of socnet.
		if (preg_match('/^(?:U|IU|SU)(\d+)$/', $code, $match))
		{
			$userId = (int)$match[1];

			return $userId > 0 ? ['U' . $userId] : $this->skip($value, self::SKIP_REASON_UNKNOWN_CODE);
		}

		if (preg_match('/^G(\d+)$/', $code, $match))
		{
			return ['G' . (int)$match[1]];
		}

		if (preg_match('/^(DR?)(\d+)$/', $code, $match))
		{
			return $match[1] === 'DR'
				? ['DR' . (int)$match[2]]
				: $this->widen($value, 'DR' . (int)$match[2])
			;
		}

		if (preg_match('/^SG(\d+)(?:_([A-Z]+))?$/', $code, $match))
		{
			return $this->mapSocnetGroup($value, (int)$match[1], $match[2] ?? self::DEFAULT_SOCNET_ROLE);
		}

		if (preg_match('/^(HRR?)(\d+)$/', $code, $match))
		{
			return $this->mapHrNode($value, (int)$match[2], $match[1] === 'HRR');
		}

		return $this->skip($value, self::SKIP_REASON_UNKNOWN_CODE);
	}

	/**
	 * A workgroup chosen in a template means everyone bizproc would hand the value to: the role named and
	 * every role above it, an owner and a moderator included. Disk writes a right on the very role, so the
	 * choice ends in a code per role and not in the one of the member alone. A role disk writes no right
	 * on - a ban, a request to join - is no recipient and is dropped with a reason.
	 *
	 * @return string[]
	 */
	private function mapSocnetGroup(string $value, int $groupId, string $role): array
	{
		$roleIndex = array_search($role, self::SOCNET_ROLES_FROM_HIGHEST, true);
		if ($roleIndex === false)
		{
			return $this->skip($value, self::SKIP_REASON_UNKNOWN_CODE);
		}

		return array_map(
			static fn(string $roleCode): string => 'SG' . $groupId . '_' . $roleCode,
			array_slice(self::SOCNET_ROLES_FROM_HIGHEST, 0, $roleIndex + 1),
		);
	}

	/**
	 * A node of the structure is a department of the portal as long as it carries the code of the old one;
	 * a node that carries none - a team, for one - is handed over as the people in it.
	 *
	 * @return string[]
	 */
	private function mapHrNode(string $value, int $nodeId, bool $isRecursive): array
	{
		$departmentId = $this->findDepartmentIdByNode($nodeId);
		if ($departmentId > 0)
		{
			return $isRecursive
				? ['DR' . $departmentId]
				: $this->widen($value, 'DR' . $departmentId)
			;
		}

		$userIds = Loader::includeModule('bizproc')
			? \CBPHelper::extractUsersFromExtendedGroup($value)
			: false
		;
		if (!is_array($userIds) || $userIds === [])
		{
			return $this->skip($value, self::SKIP_REASON_EMPTY_HR_NODE);
		}

		return $this->mapUserIds($value, $userIds, self::SKIP_REASON_EMPTY_HR_NODE);
	}

	/**
	 * A group of the document - "the author", "the one responsible" - is a legal value of a field of the
	 * type user, and only the document service behind bizproc can say who stands in it.
	 *
	 * @return string[]
	 */
	private function extractUsersOfDocumentGroup(string $value): array
	{
		if (!$this->documentId || !Loader::includeModule('bizproc'))
		{
			return $this->skip($value, self::SKIP_REASON_UNKNOWN_CODE);
		}

		try
		{
			// A value of no group at all reaches the document service as one, and the service of a document
			// answers a value out of shape by raising: to the recipients of a template that is one more
			// value nobody could use, not a reason to leave the rest of them without their right.
			$userIds = \CBPHelper::extractUsers($value, $this->documentId);
		}
		catch (\Throwable)
		{
			$userIds = [];
		}

		if (!is_array($userIds) || $userIds === [])
		{
			return $this->skip($value, self::SKIP_REASON_UNKNOWN_CODE);
		}

		return $this->mapUserIds($value, $userIds, self::SKIP_REASON_UNKNOWN_CODE);
	}

	/**
	 * @param int[]|string[] $userIds
	 * @return string[]
	 */
	private function mapUserIds(string $value, array $userIds, string $emptyReason): array
	{
		if (count($userIds) > self::MAX_EXPANDED_USERS)
		{
			return $this->skip($value, self::SKIP_REASON_TOO_MANY_USERS);
		}

		$codes = [];
		foreach ($userIds as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0)
			{
				$codes[] = 'U' . $userId;
			}
		}

		return $codes ?: $this->skip($value, $emptyReason);
	}

	private function findDepartmentIdByNode(int $nodeId): int
	{
		if ($nodeId <= 0 || !Loader::includeModule('humanresources'))
		{
			return 0;
		}

		if (isset($this->departmentIdByNodeId[$nodeId]))
		{
			return $this->departmentIdByNodeId[$nodeId];
		}

		$accessCode = (string)(HumanResourcesContainer::getNodeService()->getById($nodeId)?->accessCode ?? '');
		// The code of a department of the old structure reads D<id>; the prefix is taken apart here, the
		// way the codes of the portal are taken apart above, so that nothing private of humanresources is
		// reached for one integer.
		$departmentId = preg_match('/^D(\d+)$/', $accessCode, $match) ? (int)$match[1] : 0;

		$this->departmentIdByNodeId[$nodeId] = $departmentId;

		return $departmentId;
	}

	/** @return string[] */
	private function widen(string $value, string $code): array
	{
		$this->widened[$value] = $code;

		return [$code];
	}

	/** @return string[] */
	private function skip(string $value, string $reason): array
	{
		$this->skipped[$value] = $reason;

		return [];
	}
}
