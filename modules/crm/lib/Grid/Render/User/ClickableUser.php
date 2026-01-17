<?php

namespace Bitrix\Crm\Grid\Render\User;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\UuidGenerator;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;

class ClickableUser
{
	private static int $nodeId = 0;
	private const FILTER_COLUMNS_WITH_SINGLE_USER = [
		'ASSIGNED_BY_ID',
		'CREATED_BY_ID',
		'MODIFY_BY_ID',
		'MOVED_BY_ID',
		// Smart
		'CREATED_BY',
		'UPDATED_BY',
		'MOVED_BY',
	];

	/**
	 * @var array{
	 *      array{
	 *          ID: int,
	 *          LOGIN: string,
	 *          LAST_NAME: string,
	 *          PERSONAL_PHOTO: string,
	 *          WORK_POSITION: string,
	 *          SECOND_NAME: string,
	 *          TITLE: string,
	 *          IS_REAL_USER: string,
	 *          FORMATTED_NAME: string,
	 *          SHOW_URL: \Bitrix\Main\Web\Uri,
	 *          PHOTO_URL: string,
	 *      }
	 *  } $usersData
	 */
	public function __construct(
		private readonly array $usersData,
	)
	{
	}

	public static function createByUserIds(array $userIds): self
	{
		$userIds = array_unique($userIds);
		$userData = Container::getInstance()->getUserBroker()->getBunchByIds($userIds);

		return new self($userData);
	}

	public function render(
		int $userId,
		string $filterFieldId,
		string $gridId,
		array $filterFields,
	): string
	{
		if (!isset($this->usersData[$userId]))
		{
			return '';
		}

		$user = $this->usersData[$userId];

		$userRawName = $user['FORMATTED_NAME'];
		$user['FORMATTED_NAME'] = htmlspecialcharsbx($user['FORMATTED_NAME']);

		if ($user['PHOTO_URL'])
		{
			$avatarUrl = Uri::urnEncode($user['PHOTO_URL']);
		}

		$isSelected = 0;
		$filterField = $filterFields[$filterFieldId] ?? null;
		if (
			is_array($filterField)
			&& in_array($userId, $filterFields[$filterFieldId], false)
			&& count($filterField) === 1
		)
		{
			$isSelected = 1;
		}
		/*
		 * This is a crutch to handle filter by crm.entity.counter.panel, because
		 * \Bitrix\Crm\Filter\Activity\CounterFilter::extractUserFilterParamsFromFilter unsets original filter by user IDs
		 */
		else if (
			$filterFieldId === 'ASSIGNED_BY_ID'
			&& isset($filterFields['%ASSIGNED_BY_ID_label'])
			&& is_array($filterFields['%ASSIGNED_BY_ID_label'])
			&& count($filterFields['%ASSIGNED_BY_ID_label']) === 1
			&& $filterFields['%ASSIGNED_BY_ID_label'][0] === $userRawName
		)
		{
			$isSelected = 1;
		}

		\Bitrix\Main\UI\Extension::load('crm.grid.field.clickable-user');

		$rootNodeId = "clickable_user_{$userId}_" . self::$nodeId++ . UuidGenerator::generateV4();
		$isSingleUserColumn = in_array($filterFieldId, self::FILTER_COLUMNS_WITH_SINGLE_USER, true);

		$jsOptions = Json::encode([
			'id' => (string)$userId,
			'name' => $user['FORMATTED_NAME'],
			'photoUrl' => $avatarUrl ?? '',
			'isSelected' => $isSelected,
			'isSingleUserColumn' => $isSingleUserColumn,
			'filterFieldId' => $filterFieldId,
			'gridId' => $gridId,
			'rootNodeId' => $rootNodeId,
		]);

		$wrapperClass = $isSingleUserColumn ? 'crm-grid-user-wrapper' : 'crm-grid-user-multiple-wrapper';

		return <<<HEREDOC
			<div class="{$wrapperClass}" id="{$rootNodeId}" title=""></div>
			<script>
				BX.ready(function() {
					const user = new BX.Crm.Grid.Field.ClickableUser({$jsOptions});
					user.render();
				});
			</script>
		HEREDOC;
	}
}
