<?php

namespace Bitrix\AI\ShareRole\Components\Grid\Row\Field\Assembler;

use Bitrix\AI\Facade\User;
use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Text\HtmlFilter;
use CUtil;

class ShareRoleIsActiveFieldAssembler extends FieldAssembler
{
	protected function prepareColumn($value): string
	{
		$isActive = $value['isActive'];
		$roleCode = $value['roleCode'];
		$roleName = CUtil::JSEscape($value['roleName']);
		$isEditable = $value['isEditable'];
		$onclickAttribute = '';

		if ($isEditable && $isActive)
		{
			$onclick = HtmlFilter::encode(
				"BX.AI.ShareRole.Library.Controller.handleClickOnDeactivateRoleMenuItem(event, '$roleCode', '$roleName')",
			);
			$onclickAttribute = ' onclick="' . $onclick . '"';
		}
		elseif ($isEditable)
		{
			$onclick = HtmlFilter::encode(
				"BX.AI.ShareRole.Library.Controller.handleClickOnActivateRoleMenuItem(event, '$roleCode', '$roleName')",
			);
			$onclickAttribute = ' onclick="' . $onclick . '"';
		}

		$className = 'ui-switcher ui-switcher-color-green';

		if ($isActive === false)
		{
			$className .= ' ui-switcher-off';
		}
		if (!$isEditable)
		{
			$className .= ' ui-switcher-lock';
		}

		return '
			<div class="' . $className . '"' . $onclickAttribute . '>
				<span class="ui-switcher-cursor"></span>
				<span class="ui-switcher-disabled"></span>
				<span class="ui-switcher-enabled"></span>
			</div>
		';
	}

	/**
	 * @param array $row
	 *
	 * @return array
	 */
	protected function prepareRow(array $row): array
	{
		if (empty($this->getColumnIds()))
		{
			return $row;
		}

		$row['columns'] ??= [];

		foreach ($this->getColumnIds() as $columnId)
		{
			$data = [
				'isActive' => $row['data'][$columnId] ?? null,
				'roleCode' => $row['data']['ID'],
				'roleName' => $row['data']['DATA']['NAME'],
				'isEditable' => (int)($row['data']['AUTHOR_ID'] ?? 0) === $this->getCurrentUserId(),
			];

			$row['columns'][$columnId] = $this->prepareColumn($data);
		}

		return $row;
	}

	protected function getCurrentUserId(): int
	{
		return User::getCurrentUserId();
	}
}
