<?php

namespace Bitrix\Tasks\Control;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Tasks\Control\Exception\TemplateNotFoundException;
use Bitrix\Tasks\Internals\Log\LogFacade;
use \Bitrix\Tasks\Internals\Task\Template\TemplateMemberTable;
use Bitrix\Tasks\Internals\Task\TemplateTable;
use Bitrix\Tasks\Util\User;

class TemplateMember
{
	private const MEMBERS_WIPED_LOG_MARKER = 'TASKS_TEMPLATE_MEMBERS_WIPED';

	public function __construct(
		private readonly int $templateId
	)
	{

	}

	public function add(array $data): void
	{
		$members = $this->prepareMembers($data);

		if (empty($members))
		{
			return;
		}

		TemplateMemberTable::addInsertIgnoreMulti($members, true);
	}

	public function set(array $data): void
	{
		$currentMembers = $this->getCurrentMembers();

		$members = $this->prepareMembers($data, $currentMembers);

		if (empty($members))
		{
			return;
		}

		$this->logWipedTypes($currentMembers, $members);

		$this->deleteByTemplateId();

		TemplateMemberTable::addInsertIgnoreMulti($members, true);
	}

	/**
	 * An incoming empty list wipes its member type, so a caller sending one instead of no field at all
	 * loses members silently. The wipe stays legal on purpose (a person may remove every accomplice), so it
	 * is traced rather than forbidden: the trace names such a caller without waiting for a client report.
	 */
	private function logWipedTypes(array $currentMembers, array $members): void
	{
		$incomingCountByType = array_count_values(array_column($members, 'TYPE'));

		foreach ($currentMembers as $type => $rows)
		{
			if (empty($rows) || isset($incomingCountByType[$type]))
			{
				continue;
			}

			$this->logWipedType((string)$type, count($rows));
		}
	}

	protected function logWipedType(string $type, int $wasCount): void
	{
		LogFacade::log(
			[
				'templateId' => $this->templateId,
				'type' => $type,
				'wasCount' => $wasCount,
				'updatedBy' => (int)User::getId(),
			],
			self::MEMBERS_WIPED_LOG_MARKER,
		);
	}

	private function getCurrentMembers(): array
	{
		$members = [];

		$template = TemplateTable::getByPrimary($this->templateId, ['select' => ['*', 'MEMBERS']])->fetchObject();
		if ($template === null)
		{
			return $members;
		}
		
		$memberList = $template->getMembers();
		foreach($memberList as $member)
		{
			$memberType = $member->getType();
			$members[$memberType][] = [
				'USER_ID' => $member->getUserId(),
				'TYPE' => $memberType,
				'TEMPLATE_ID' => $this->templateId,
			];
		}

		return $members;
	}

	private function prepareMembers(array $data, array $members = []): array
	{
		if (array_key_exists('CREATED_BY', $data))
		{
			$members[TemplateMemberTable::MEMBER_TYPE_ORIGINATOR] = [];
			$members[TemplateMemberTable::MEMBER_TYPE_ORIGINATOR][] = [
				'USER_ID' => $data['CREATED_BY'],
				'TYPE' => TemplateMemberTable::MEMBER_TYPE_ORIGINATOR,
				'TEMPLATE_ID' => $this->templateId,
			];
		}

		if (array_key_exists('RESPONSIBLES', $data))
		{
			$members[TemplateMemberTable::MEMBER_TYPE_RESPONSIBLE] = [];
			foreach ($data['RESPONSIBLES'] as $userId)
			{
				$members[TemplateMemberTable::MEMBER_TYPE_RESPONSIBLE][] = [
					'USER_ID' => $userId,
					'TYPE' => TemplateMemberTable::MEMBER_TYPE_RESPONSIBLE,
					'TEMPLATE_ID' => $this->templateId,
				];
			}
		}

		if (array_key_exists('ACCOMPLICES', $data))
		{
			$members[TemplateMemberTable::MEMBER_TYPE_ACCOMPLICE] = [];
			foreach ($data['ACCOMPLICES'] as $userId)
			{
				$members[TemplateMemberTable::MEMBER_TYPE_ACCOMPLICE][] = [
					'USER_ID' => $userId,
					'TYPE' => TemplateMemberTable::MEMBER_TYPE_ACCOMPLICE,
					'TEMPLATE_ID' => $this->templateId,
				];
			}
		}

		if (array_key_exists('AUDITORS', $data))
		{
			$members[TemplateMemberTable::MEMBER_TYPE_AUDITOR] = [];
			foreach ($data['AUDITORS'] as $userId)
			{
				$members[TemplateMemberTable::MEMBER_TYPE_AUDITOR][] = [
					'USER_ID' => $userId,
					'TYPE' => TemplateMemberTable::MEMBER_TYPE_AUDITOR,
					'TEMPLATE_ID' => $this->templateId,
				];
			}
		}

		return array_merge(...array_values($members));
	}
	
	private function deleteByTemplateId(): void
	{
		TemplateMemberTable::deleteList([
			'TEMPLATE_ID' => $this->templateId,
		]);
	}
}
