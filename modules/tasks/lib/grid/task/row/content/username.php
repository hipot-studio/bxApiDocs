<?php
namespace Bitrix\Tasks\Grid\Task\Row\Content;

use Bitrix\Main;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;
use Bitrix\Tasks\Grid\ControlButtonTrait;
use Bitrix\Tasks\Grid\Task\Row\Content;
use Bitrix\Tasks\UI;
use Bitrix\Tasks\Util\User;

/**
 * Class UserName
 *
 * @package Bitrix\Tasks\Grid\Task\Row\Content
 */
class UserName extends Content
{
	use ControlButtonTrait;

	/**
	 * @return string
	 * @throws Main\ArgumentException
	 */
	protected function prepareUserName(): string
	{
		$user = $this->resolveUser((int)$this->getRowDataByName(static::USER_ROLE));
		if ($user === null)
		{
			return '';
		}

		return $this->buildUserMarkup($user, static::USER_ROLE);
	}

	/**
	 * @return array|null
	 * @throws Main\ArgumentException
	 */
	protected function resolveUser(int $userId): ?array
	{
		$row = $this->getRowData();

		return $row['MEMBERS'][static::USER_ROLE] ?? static::getCachedUser($userId);
	}

	/**
	 * @return array|null
	 * @throws Main\ArgumentException
	 */
	protected static function getCachedUser(int $userId): ?array
	{
		static $cache = [];

		if (!array_key_exists($userId, $cache))
		{
			$cache[$userId] = static::loadUser($userId);
		}

		return $cache[$userId];
	}

	/**
	 * @return array|null
	 * @throws Main\ArgumentException
	 */
	protected static function loadUser(int $userId): ?array
	{
		$select = [
			'ID',
			'PERSONAL_PHOTO',
			'LOGIN',
			'NAME',
			'LAST_NAME',
			'SECOND_NAME',
			'TITLE',
		];
		$users = User::getData([$userId], $select);

		return $users[$userId] ?? null;
	}

	/**
	 * Builds the markup of a single user cell: avatar, name and the filter toggle
	 * button. The filter role is the grid filter field toggled on click
	 * (e.g. CREATED_BY, RESPONSIBLE_ID, ACCOMPLICE, AUDITOR).
	 */
	protected function buildUserMarkup(array $user, string $filterRole): string
	{
		$parameters = $this->getParameters();

		$userId = (int)$user['ID'];

		$user['IS_EXTERNAL'] = $user['IS_EXTRANET_USER'];
		$user['IS_CRM'] = $user['IS_CRM_EMAIL_USER'];

		$userIcon = $this->getUserIconModifier($user);

		$userName = htmlspecialcharsbx(User::formatName($user));
		$userNameElement = $this->buildAvatarMarkup($user)
			."<span class='tasks-grid-username-inner{$userIcon}'>{$userName}</span>"
			."<span class='tasks-grid-filter-remove'></span>";

		$encodedData = Json::encode([
			$filterRole => [$user['ID']],
			$filterRole.'_label' => [$userName],
		]);

		$testId = 'tasks-grid-user-' . mb_strtolower(str_replace('_', '-', $filterRole)) . '-' . $userId;

		$selected = 0;
		$selector = 'tasks-grid-username';
		if (
			isset($parameters['FILTER_FIELDS'][$filterRole])
			&& is_array($parameters['FILTER_FIELDS'][$filterRole])
			&& count($parameters['FILTER_FIELDS'][$filterRole]) === 1
			&& (int)$parameters['FILTER_FIELDS'][$filterRole][0] === $userId
		)
		{
			$selected = 1;
			$selector .= ' tasks-grid-filter-active';
		}

		$onClick = "BX.PreventDefault(); BX.Tasks.GridActions.toggleFilter({$encodedData}, {$selected})";

		return "<div class='tasks-grid-username-wrapper'>"
			. $this->renderControlButton(
				[
					'class' => $selector,
					'aria-pressed' => ($selected ? 'true' : 'false'),
					'data-testid' => $testId,
				],
				$userNameElement,
				$onClick,
				"'"
			)
			. "</div>";
	}

	/**
	 * Builds the avatar element of a single user. An empty $title leaves the markup without a tooltip,
	 * which is what the single user columns render.
	 */
	protected function buildAvatarMarkup(array $user, string $title = ''): string
	{
		$userIcon = $this->getUserIconModifier($user);

		$userAvatar = '';
		$userEmptyAvatar = ' tasks-grid-avatar-empty';

		if ($avatar = UI::getAvatar($user['PERSONAL_PHOTO'], 100, 100))
		{
			$userAvatar = ' style="background-image: url(\'' . Uri::urnEncode($avatar) . '\')"';
			$userEmptyAvatar = '';
		}

		$titleAttribute = ($title === '' ? '' : ' title="' . htmlspecialcharsbx($title) . '"');

		return "<span class='tasks-grid-avatar ui-icon ui-icon-common-user{$userEmptyAvatar}{$userIcon}'{$titleAttribute} aria-hidden='true'>"
			."<i{$userAvatar}></i>"
			."</span>";
	}

	protected function getUserIconModifier(array $user): string
	{
		$userIcon = '';
		if ($user['IS_EXTRANET_USER'])
		{
			$userIcon = ' tasks-grid-avatar-extranet';
		}
		if ($user["IS_EMAIL_USER"])
		{
			$userIcon = ' tasks-grid-avatar-mail';
		}
		if ($user["IS_CRM_EMAIL_USER"])
		{
			$userIcon = ' tasks-grid-avatar-crm';
		}
		if ($user['IS_COLLABER_USER'] ?? false)
		{
			$userIcon = ' tasks-grid-avatar-collaber';
		}

		return $userIcon;
	}
}