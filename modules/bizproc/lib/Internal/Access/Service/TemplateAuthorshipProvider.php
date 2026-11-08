<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Access\Service;

use Bitrix\Bizproc\Api\Enum\Template\CreateSource;
use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;

/**
 * Answers the single question "is this template mine", with a batch warmup for callers that
 * hold a list of ids. It does not decide whether authorship applies to a right - that belongs to the
 * scope resolution in {@see \Bitrix\Bizproc\Internal\Access\Rule\BaseRule}.
 *
 * Memoization spans the instance, and the instance spans the request (registered as a service, see
 * .settings.php). The memo is never invalidated: CREATED_BY is immutable through the save path, so a
 * long-living process cannot see it change under itself, and an invalidation point would have to be
 * invented for a value that never moves.
 */
class TemplateAuthorshipProvider
{
	/**
	 * Ids per warmup query. The list comes from a caller holding a selection of its own (the grid accumulates
	 * pages behind "show more", so it is bounded by nothing this side can name), and an `IN` of that length is
	 * the one cost that grows with it. Chunking bounds the statement without refusing work the user may
	 * legitimately ask for.
	 */
	private const WARMUP_CHUNK_SIZE = 500;

	/** @var array<int, int|null> templateId => author id; null means "outside the own scope" */
	private array $memo = [];

	/**
	 * The own scope of a template row, defined once here and shared with the list/REST filter
	 * ({@see \Bitrix\Bizproc\Public\Provider\TemplateAccessProvider}). Two copies of it would let a
	 * template show up in the list while the action on it is refused.
	 *
	 * The author condition (CREATED_BY) is deliberately NOT part of it: here the author is compared
	 * after the select, in the list filter it sits inside a disjunction, and both consumers add it
	 * themselves. A NULL CREATE_SOURCE counts as user-made - rows predate the field, the same reading
	 * as in {@see \Bitrix\Bizproc\Starter\AbstractProcessStarter}.
	 */
	public static function ownScopeCondition(): ConditionTree
	{
		return Query::filter()
			->where('TYPE', WorkflowTemplateType::Nodes->value)
			->whereNull('SYSTEM_CODE')
			->where(
				Query::filter()
					->logic('or')
					->where('CREATE_SOURCE', CreateSource::User->value)
					->whereNull('CREATE_SOURCE')
			)
		;
	}

	/**
	 * Authorship is strictly a positive user id on both sides: an empty or zero CREATED_BY gives
	 * authorship to nobody, user 0 included.
	 */
	public function isOwn(int $templateId, int $userId): bool
	{
		if ($userId <= 0 || $templateId <= 0)
		{
			return false;
		}

		$this->warmup([$templateId]);

		return $this->memo[$templateId] === $userId;
	}

	/**
	 * @param array<int, int|string> $templateIds
	 */
	public function warmup(array $templateIds): void
	{
		$missing = [];
		foreach ($templateIds as $templateId)
		{
			$templateId = (int)$templateId;
			if ($templateId > 0 && !array_key_exists($templateId, $this->memo))
			{
				$missing[$templateId] = $templateId;
			}
		}

		if (!$missing)
		{
			return;
		}

		// A negative answer is memoized as well: a row outside the own scope, or one belonging to
		// someone else, is asked about exactly as often as an own one, and it is the denial path that
		// would otherwise re-query the same id on every check.
		foreach ($missing as $templateId)
		{
			$this->memo[$templateId] = null;
		}

		foreach (array_chunk(array_values($missing), self::WARMUP_CHUNK_SIZE) as $chunk)
		{
			$rows = WorkflowTemplateTable::query()
				->setSelect(['ID', 'CREATED_BY'])
				->whereIn('ID', $chunk)
				->where(self::ownScopeCondition())
				->fetchAll()
			;

			foreach ($rows as $row)
			{
				$authorId = (int)$row['CREATED_BY'];
				$this->memo[(int)$row['ID']] = $authorId > 0 ? $authorId : null;
			}
		}
	}
}
