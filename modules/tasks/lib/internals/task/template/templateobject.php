<?php

namespace Bitrix\Tasks\Internals\Task\Template;

use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\Tasks\Access\Role\RoleDictionary;
use Bitrix\Tasks\Internals\CacheTrait;
use Bitrix\Tasks\Internals\Log\LogFacade;
use Bitrix\Tasks\Internals\MemberTrait;
use Bitrix\Tasks\Internals\Task\EO_Template;
use Bitrix\Tasks\Internals\Task\TemplateTable;
use Bitrix\Tasks\Internals\WakeUpTrait;
use Bitrix\Tasks\Member\AbstractMemberService;
use Bitrix\Tasks\Member\Service\TemplateMemberService;
use Bitrix\Tasks\Util\Type\DateTime;
use Bitrix\Main\ORM\Fields;
use CTaskTemplates;

class TemplateObject extends EO_Template implements Arrayable
{
	use CacheTrait;
	use MemberTrait;
	use WakeUpTrait;

	public function toArray(): array
	{
		try
		{
			$fields = TemplateTable::getEntity()->getFields();
		}
		catch (SystemException $exception)
		{
			LogFacade::logThrowable($exception);
			return [];
		}

		$data = [];
		foreach ($fields as $fieldName => $field)
		{
			if (
				$field instanceof Fields\Relations\Reference
				|| $field instanceof Fields\Relations\OneToMany
				|| $field instanceof Fields\Relations\ManyToMany
				|| $field instanceof Fields\ExpressionField
			)
			{
				continue;
			}

			$data[$fieldName] = $this->get($fieldName);

			if ($data[$fieldName] instanceof DateTime)
			{
				$data[$fieldName] = $data[$fieldName]->getTimestamp();
			}
		}
		return $data;
	}

	public function getChildren(): array
	{
		$result = [];
		$res = CTaskTemplates::getList(
			['BASE_TEMPLATE_ID' => 'asc'],
			['BASE_TEMPLATE_ID' => $this->getId()],
			false,
			['INCLUDE_TEMPLATE_SUBTREE' => true],
			['*', 'UF_*', 'BASE_TEMPLATE_ID']
		);
		while ($item = $res->fetch())
		{
			if ((int)$item['ID'] === $this->getId())
			{
				continue;
			}
			$result[(int)$item['ID']] = $item;
		}

		return $result;
	}

	public function getResponsibleMemberId(): array
	{
		return $this->getMembersIdsByRole(RoleDictionary::ROLE_RESPONSIBLE);
	}

	public function getSubTemplatesArray(): array
	{
		return array_map(
			fn($item) => [
				'TEMPLATE_ID' => $item->getTemplateId(),
				'PARENT_TEMPLATE_ID' => $item->getParentTemplateId(),
				'DIRECT' => $item->getDirect(),
			],
			$this->getSubTemplates()?->getAll() ?? [],
		);
	}

	protected function getMemberService(): AbstractMemberService
	{
		return new TemplateMemberService($this->getId());
	}

	public function getTags(): array
	{
		return $this->getTagList()?->map(
			fn ($tag) => ['ID' => $tag->getId(), 'NAME' => $tag->getName()]
		) ?? [];
	}

	public function getBaseTemplateId(): ?int
	{
		$baseTemplate = $this->get('DIRECT_PARENT');
		return $baseTemplate ? $baseTemplate->getParentTemplateId() : null;
	}

	public function getDirectChildrenCount(): int
	{
		return count(
			array_filter(
				$this->getSubTemplatesArray(),
				fn($data) => $data['DIRECT'] ?? false,
			)
		);
	}
}
