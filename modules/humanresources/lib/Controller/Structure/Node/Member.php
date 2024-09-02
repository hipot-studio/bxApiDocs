<?php

namespace Bitrix\HumanResources\Controller\Structure\Node;

use Bitrix\HumanResources\Engine\Controller;
use Bitrix\HumanResources\Contract\Repository\NodeMemberRepository;
use Bitrix\HumanResources\Exception\CreationFailedException;
use Bitrix\HumanResources\Exception\UpdateFailedException;
use Bitrix\HumanResources\Item;
use Bitrix\HumanResources\Contract\Service\NodeMemberService;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\Main;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Request;
use Bitrix\Main\SystemException;

final class Member extends Controller
{
	private readonly NodeMemberRepository $nodeMemberRepository;
	private readonly NodeMemberService $nodeMemberService;

	public function __construct(Request $request = null)
	{
		$this->nodeMemberRepository = Container::getNodeMemberRepository();
		$this->nodeMemberService = Container::getNodeMemberService();

		parent::__construct($request);
	}

	/**
	 * @throws ArgumentException
	 * @throws SqlQueryException
	 * @throws SystemException
	 * @throws CreationFailedException
	 */
	public function addAction(
		Item\NodeMember $nodeMember,
		Item\Node $node,
	): array
	{
		$nodeMember->nodeId = $node->id;
		$this->nodeMemberRepository->create($nodeMember);

		return [];
	}

	public function moveAction(
		Item\NodeMember $nodeMember,
		Item\Node $node,
	):array
	{
		try
		{
			$this->nodeMemberService->moveMember($nodeMember, $node);
		}
		catch (UpdateFailedException $exception)
		{
			$this->addErrors($exception->getErrors()->toArray());
		}

		return [];
	}

	public function getUserMemberAction(
		Item\User $user,
		Item\Node $node,
	): array
	{
		$nodeMember = $this->nodeMemberRepository->findByEntityTypeAndEntityIdAndNodeId(
			entityType: MemberEntityType::USER,
			entityId: $user->id,
			nodeId: $node->id,
		);

		if (!$nodeMember)
		{
			$this->addError(new Main\Error('Member not found'));

			return [];
		}

		return [
			'id' => $nodeMember->id,
			'roles' => $nodeMember->roles,
		];
	}

	public function deleteAction(
		Item\NodeMember $member,
	): array
	{
		return [
			'success' => $this->nodeMemberRepository->remove($member),
		];
	}

	public function countAction(
		Item\Structure $structure,
	): array
	{
		return $this->nodeMemberRepository->countAllByStructureAndGroupByNode($structure);
	}
}