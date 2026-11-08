<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Service;

use Bitrix\Bizproc\Api\Data\TemplateFragmentService\Fragment;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Api\Request\TemplateFragmentService\CreateFragmentRequest;
use Bitrix\Bizproc\Api\Request\TemplateFragmentService\DeleteFragmentRequest;
use Bitrix\Bizproc\Api\Request\TemplateFragmentService\GetFragmentsRequest;
use Bitrix\Bizproc\Api\Request\TemplateFragmentService\PrepareInsertRequest;
use Bitrix\Bizproc\Api\Request\TemplateFragmentService\UpdateFragmentRequest;
use Bitrix\Bizproc\Api\Response\TemplateFragmentService\CreateFragmentResponse;
use Bitrix\Bizproc\Api\Response\TemplateFragmentService\GetFragmentsResponse;
use Bitrix\Bizproc\Api\Response\TemplateFragmentService\PrepareInsertResponse;
use Bitrix\Bizproc\Api\Response\TemplateFragmentService\UpdateFragmentResponse;
use Bitrix\Bizproc\Result;
use Bitrix\Bizproc\Workflow\Template\Fragment\ApplicabilityFilter;
use Bitrix\Bizproc\Workflow\Template\Fragment\Entity\TemplateFragmentTable;
use Bitrix\Bizproc\Workflow\Template\Fragment\Enum\SkipReason;
use Bitrix\Bizproc\Workflow\Template\Fragment\FragmentAccess;
use Bitrix\Bizproc\Workflow\Template\Fragment\FragmentCodec;
use Bitrix\Bizproc\Workflow\Template\Fragment\FragmentGraph;
use Bitrix\Bizproc\Workflow\Template\Fragment\Limits;
use Bitrix\Bizproc\Workflow\Template\Fragment\NodeTitleResolver;
use Bitrix\Bizproc\Workflow\Template\Fragment\ReferenceRewriter;
use Bitrix\Bizproc\Workflow\Template\Fragment\SettingsBarrier;
use Bitrix\Main\Type\DateTime;

/**
 * The library of the saved fragments: the only path to the store of them, reading and writing alike. Every
 * rule of the rights is asked of {@see FragmentAccess}, so the condition of the select of the list and the
 * check of an operation by id are one and the same rule.
 *
 * Two refusals of the whole surface are deliberately indistinguishable: a fragment nobody may reach and a
 * fragment that is not there both answer ACCESS_DENIED. Telling them apart would turn the code of the
 * answer into a way of learning that the personal fragment of somebody else exists.
 *
 * The envelope of a fragment never leaves here: the list and the operations on the metadata select the
 * columns they need and not the content, and the only way out for the content is the prepared insertion.
 */
class TemplateFragmentService
{
	private const METADATA_SELECT = ['ID', 'NAME', 'AUTHOR_ID', 'IS_SHARED', 'NODE_COUNT', 'CREATED'];

	private FragmentAccess $access;

	private ?NodeTitleResolver $nodeTitles = null;

	public function __construct(?FragmentAccess $access = null)
	{
		$this->access = $access ?? new FragmentAccess();
	}

	/**
	 * One page of the library, the newest fragments first. Whether there is more is the answer of a select
	 * of one row over the page instead of a count of its own.
	 */
	public function getFragments(GetFragmentsRequest $request): GetFragmentsResponse
	{
		$response = new GetFragmentsResponse();

		if (!$this->access->canUseLibrary($request->userId))
		{
			$this->denyAccess($response);

			return $response;
		}

		// Both ceilings are held here as well as by the form of the request: the caller answers for what was
		// asked for, the service for the size of the answer and the length of the scan behind it.
		if ($request->offset > Limits::MAX_OFFSET)
		{
			return $response->addError(ErrorMessage::INVALID_PARAM_ARG->getCodedError([
				'#PARAM#' => 'offset',
				'#VALUE#' => (string)$request->offset,
			]));
		}

		$limit = max(1, min($request->limit, Limits::MAX_PAGE_SIZE));

		$rows = TemplateFragmentTable::query()
			->setSelect(array_merge(self::METADATA_SELECT, FragmentAccess::selectOfAuthorActivity()))
			->where($this->access->visibilityCondition($request->userId))
			->setOrder(['CREATED' => 'DESC', 'ID' => 'DESC'])
			->setOffset(max(0, $request->offset))
			->setLimit($limit + 1)
			->fetchAll()
		;

		$hasMore = count($rows) > $limit;

		return $response
			->setFragments(array_map(
				fn(array $row): Fragment => $this->toFragment($row, $request->userId),
				array_slice($rows, 0, $limit),
			))
			->setHasMore($hasMore)
		;
	}

	/**
	 * A selection of the scheme becomes a fragment. Nothing of the template is read or written: the nodes
	 * and the links arrive with the request, and the template is named only to ask whether this person may
	 * cut a fragment out of it.
	 */
	public function createFragment(CreateFragmentRequest $request): CreateFragmentResponse
	{
		$response = new CreateFragmentResponse();

		if (
			!$this->access->canUseLibrary($request->userId)
			|| !$this->access->canCreateFrom($request->templateId, $request->userId, $request->documentType)
		)
		{
			$this->denyAccess($response);

			return $response;
		}

		$name = trim($request->name);
		if ($name === '')
		{
			return $response->addError(ErrorMessage::EMPTY_PROP->getCodedError(['#PROPERTY#' => 'NAME']));
		}

		$envelope = FragmentCodec::encode($request->blocks, $request->connections, $request->documentType);
		if (!$envelope->isSuccess())
		{
			$response->addErrors($envelope->getErrors());

			return $response;
		}

		$isShared = $request->isShared ? 'Y' : 'N';
		$created = new DateTime();

		$fragment = TemplateFragmentTable::createObject();
		$fragment
			->setName($name)
			->setAuthorId($request->userId)
			->setIsShared($isShared)
			// Every node of the fragment and not the blocks of its top level: the number is shown to the user
			// in the library, and the codec counts it over the whole tree the ceiling is measured on.
			->setNodeCount((int)$envelope->getData()['nodeCount'])
			->setFragment((string)$envelope->getData()['fragment'])
			->setCreated($created)
		;

		$saveResult = $fragment->save();
		if (!$saveResult->isSuccess())
		{
			return $response->addError(ErrorMessage::UNKNOWN_ERROR->getCodedError());
		}

		// The author is the one saving, so they are active by the very fact of the request: the verdicts of
		// a fresh fragment need no reading of the row back.
		return $response->setFragment(new Fragment(
			id: (int)$fragment->getId(),
			name: $name,
			isShared: $request->isShared,
			authorId: $request->userId,
			nodeCount: (int)$fragment->getNodeCount(),
			canEdit: true,
			canDelete: true,
			createdAt: $created->getTimestamp(),
		));
	}

	/**
	 * The name and the visibility of a fragment; its content is never rewritten. A field left out of the
	 * request stays as it is, and a request that changes nothing writes nothing - the claim of an abandoned
	 * fragment included, because it is part of a change and never a change of its own.
	 */
	public function updateFragment(UpdateFragmentRequest $request): UpdateFragmentResponse
	{
		$response = new UpdateFragmentResponse();

		$row = $this->findVisibleRow($request->fragmentId, $request->userId);
		if ($row === null || !$this->access->canEdit($row, $request->userId))
		{
			$this->denyAccess($response);

			return $response;
		}

		$fields = [];

		if ($request->name !== null)
		{
			$name = trim($request->name);
			if ($name === '')
			{
				return $response->addError(ErrorMessage::EMPTY_PROP->getCodedError(['#PROPERTY#' => 'NAME']));
			}

			$fields['NAME'] = $name;
		}

		if ($request->isShared !== null)
		{
			$fields['IS_SHARED'] = $request->isShared ? 'Y' : 'N';
		}

		if ($fields !== [])
		{
			// The claim rides the write it belongs to and is no action of its own (ALG-03, rule 4). It is
			// applied over the fields of the request, because disclosure is what makes the claim safe: a
			// fragment the reading rule had just opened to everyone must not narrow to a single
			// administrator by the very act of taking it over (AC-017). An administrator who really wants
			// it personal owns it after this write and says so with the next one.
			$fields = array_merge($fields, $this->claimOfAnAbandonedFragment($row, $request->userId));

			$fields['UPDATED_BY'] = $request->userId;
			$fields['MODIFIED'] = new DateTime();

			$updateResult = TemplateFragmentTable::update($request->fragmentId, $fields);
			if (!$updateResult->isSuccess())
			{
				return $response->addError(ErrorMessage::UNKNOWN_ERROR->getCodedError());
			}
		}

		return $response->setFragment($this->toFragment(array_merge($row, $fields), $request->userId));
	}

	/**
	 * A fragment turned into something the canvas of the recipient can take (API-05). Nothing is written on
	 * the way - not the template, not its draft, not the library - so a repeat of the same request is safe
	 * and answers with the same fragment under other node names.
	 *
	 * The order of the chain is normative: the rights, then the envelope, then the applicability filter,
	 * then the barrier of the settings, then the issuing of the names with the rewriting of the references,
	 * and the shape of the canvas last. The barrier stands BEFORE the names are issued, so a node it skips
	 * reaches neither the map of the names nor the links.
	 *
	 * A fragment every node of which was skipped is a success with an empty fragment and the whole of it in
	 * the skipped list: the client shows a warning, and the canvas is left untouched.
	 */
	public function prepareInsert(PrepareInsertRequest $request): PrepareInsertResponse
	{
		$response = new PrepareInsertResponse();

		$row = $this->findVisibleRow($request->fragmentId, $request->userId, ['FRAGMENT']);
		if ($row === null)
		{
			$this->denyAccess($response);

			return $response;
		}

		$envelope = FragmentCodec::decode($row['FRAGMENT'] ?? null);
		if (!$envelope->isSuccess())
		{
			$response->addErrors($envelope->getErrors());

			return $response;
		}

		['documentType' => $sourceDocumentType, 'nodes' => $sourceNodes, 'links' => $links] = $envelope->getData();

		$filter = $this->applicabilityFilter();
		$applicable = $filter->apply($sourceNodes, $links, $request->targetDocumentType);
		$allowed = (new SettingsBarrier(storageContext: $request->storageContext))->apply(
			$applicable['nodes'],
			$applicable['links'],
			$request->targetDocumentType,
			$request->userId,
		);
		$rewritten = (new ReferenceRewriter())->rewrite($allowed['nodes'], $allowed['links']);

		[$blocks, $connections] = $rewritten['nodes'] === []
			? [[], []]
			: FragmentCodec::toNodes(
				$rewritten['nodes'],
				$rewritten['links'],
				$request->complexNodeConnectionsAvailable,
				// the catalog the filter has already walked out: the shape of the canvas is resolved from the
				// very same one the applicability of the nodes was judged by, and one request walks it once
				$filter->catalog($request->targetDocumentType),
			)
		;

		return $response
			->setBlocks($blocks)
			->setConnections($connections)
			->setSkipped($this->describeSkipped(
				array_merge($applicable['skipped'], $allowed['skipped']),
				$sourceNodes,
			))
			->setNeedsReview($this->describeReview($rewritten['unresolved'], $rewritten['nodes']))
			->setSourceDocumentType($sourceDocumentType)
		;
	}

	/**
	 * The transfer of the authorship (ALG-03, rule 4): the first change an administrator makes to the
	 * fragment of an author who is gone makes it theirs, and opens it while doing so. There is no handler of
	 * a dismissal behind this and no background pass over the table - the ownership moves at the moment
	 * somebody steps in, and never before.
	 *
	 * Removal claims nothing: rewriting the author of a row that is about to disappear buys nobody anything.
	 *
	 * The answer of the operation is built from the row as it now stands, and the authorship written here
	 * settles every verdict of it on its own: the writer is the author of the fragment from this moment.
	 *
	 * @return array<string, mixed> empty when the write changes no owner
	 */
	private function claimOfAnAbandonedFragment(array $row, int $userId): array
	{
		if (!$this->access->claimsOnWrite($row, $userId))
		{
			return [];
		}

		return ['AUTHOR_ID' => $userId, 'IS_SHARED' => 'Y'];
	}

	/**
	 * A fragment is removed by its author or by the administrator of the domain. The copies already
	 * inserted into schemes are not touched: a fragment names no template and no template names it.
	 */
	public function deleteFragment(DeleteFragmentRequest $request): Result
	{
		$response = new Result();

		$row = $this->findVisibleRow($request->fragmentId, $request->userId);
		if ($row === null || !$this->access->canDelete($row, $request->userId))
		{
			$this->denyAccess($response);

			return $response;
		}

		$deleteResult = TemplateFragmentTable::delete($request->fragmentId);
		if (!$deleteResult->isSuccess())
		{
			return $response->addError(ErrorMessage::UNKNOWN_ERROR->getCodedError());
		}

		return $response;
	}

	/**
	 * The row of a fragment as far as this person may see it at all. The visibility is a condition of the
	 * select and not a filter over what was read: a fragment out of reach is answered exactly as a missing
	 * one, and it must not be read in the first place.
	 */
	private function findVisibleRow(int $fragmentId, int $userId, array $alsoSelect = []): ?array
	{
		if ($fragmentId <= 0 || !$this->access->canUseLibrary($userId))
		{
			return null;
		}

		$row = TemplateFragmentTable::query()
			->setSelect(array_merge(self::METADATA_SELECT, FragmentAccess::selectOfAuthorActivity(), $alsoSelect))
			->where('ID', $fragmentId)
			->where($this->access->visibilityCondition($userId))
			->setLimit(1)
			->fetch()
		;

		return $row === false ? null : $row;
	}

	/**
	 * Both lists of the skipped - the one of the applicability filter and the one of the barrier - are simply
	 * added up, and every entry is given the title of its node: the node never reaches the canvas, so its
	 * name would tell the user nothing.
	 *
	 * The list is held to the ceiling of the nodes of a fragment. A node is named in it once, so a fragment
	 * within the ceiling never reaches it - this is the size of the answer being held whatever the row it was
	 * built from says, the way the page of the list is held to its own ceiling.
	 *
	 * @param list<array{nodeName: string, reason: SkipReason}> $skipped
	 * @param array $sourceNodes the nodes of the envelope, which still carry the names the entries name
	 *
	 * @return list<array{nodeName: string, title: string, reason: SkipReason}>
	 */
	private function describeSkipped(array $skipped, array $sourceNodes): array
	{
		if ($skipped === [])
		{
			return [];
		}

		$skipped = array_slice($skipped, 0, Limits::MAX_NODES);
		$titles = $this->nodeTitles()->titlesOf($sourceNodes);

		return array_map(
			static fn(array $entry): array => [
				'nodeName' => $entry['nodeName'],
				'title' => $titles[$entry['nodeName']] ?? '',
				'reason' => $entry['reason'],
			],
			$skipped,
		);
	}

	/**
	 * The places left to be tuned by hand, one entry per node of the canvas: the rewriting answers per
	 * reference, while the user walks the canvas node by node. The names here are the NEW ones, so the titles
	 * are read off the rewritten nodes. One entry per node means the ceiling of the nodes holds this list too.
	 *
	 * A reference found inside a nested node is reported under the node it stands in. ALG-01 records it under
	 * the name of the child - that is where the reference really lies - but only the activities of the top
	 * level become blocks of the canvas, so a child is an address the client cannot go to: it filters the list
	 * by the blocks it received and would drop such an entry while still counting it in the warning. The outer
	 * node is the very attribution the barrier gives a failing child, and it is the node the user can open.
	 *
	 * Several children of one node collapse into one entry for the same reason: what the client needs is the
	 * node to jump to, while the references themselves - the content of the hint - are all kept.
	 *
	 * @param list<array{nodeName: string, property: string, reference: string}> $unresolved
	 *
	 * @return list<array{nodeName: string, title: string, references: list<string>}>
	 */
	private function describeReview(array $unresolved, array $rewrittenNodes): array
	{
		if ($unresolved === [])
		{
			return [];
		}

		$outerNames = FragmentGraph::outerNodeNames($rewrittenNodes);
		$references = [];
		foreach ($unresolved as $entry)
		{
			$nodeName = (string)$entry['nodeName'];
			$references[$outerNames[$nodeName] ?? $nodeName][] = (string)$entry['reference'];
		}

		$references = array_slice($references, 0, Limits::MAX_NODES, true);
		$titles = $this->nodeTitles()->titlesOf($rewrittenNodes);
		$review = [];

		foreach ($references as $nodeName => $found)
		{
			$review[] = [
				'nodeName' => (string)$nodeName,
				'title' => $titles[$nodeName] ?? '',
				// one and the same reference may stand under two properties of one node, and the user is
				// shown what to fix, not how many times it occurs
				'references' => array_values(array_unique($found)),
			];
		}

		return $review;
	}

	/**
	 * The filter is reached through a seam of its own, unlike the rest of the chain: which node the portal
	 * still has is external state, and a test of the answer settles it instead of the catalog of a stand
	 * deciding what the answer is. The instance is fresh per request and memoises the catalog within one.
	 */
	protected function applicabilityFilter(): ApplicabilityFilter
	{
		return new ApplicabilityFilter();
	}

	private function nodeTitles(): NodeTitleResolver
	{
		return $this->nodeTitles ??= new NodeTitleResolver();
	}

	private function toFragment(array $row, int $userId): Fragment
	{
		$created = $row['CREATED'] ?? null;

		return new Fragment(
			id: (int)$row['ID'],
			name: (string)$row['NAME'],
			isShared: $this->access->isEffectivelyShared($row),
			authorId: (int)$row['AUTHOR_ID'],
			nodeCount: (int)$row['NODE_COUNT'],
			canEdit: $this->access->canEdit($row, $userId),
			canDelete: $this->access->canDelete($row, $userId),
			createdAt: $created instanceof DateTime ? $created->getTimestamp() : 0,
		);
	}

	private function denyAccess(Result $response): void
	{
		$response->addError(ErrorMessage::ACCESS_DENIED->getCodedError());
	}
}
