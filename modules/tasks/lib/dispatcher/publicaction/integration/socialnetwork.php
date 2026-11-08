<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage tasks
 * @copyright 2001-2016 Bitrix
 *
 * @access private
 *
 * Each method you put here you`ll be able to call as ENTITY_NAME.METHOD_NAME via AJAX and\or REST, so be careful.
 */

namespace Bitrix\Tasks\Dispatcher\PublicAction\Integration;

final class SocialNetwork extends \Bitrix\Tasks\Dispatcher\PublicAction
{
    const ALLOWED_AVATAR_SIZE = 100;

	/**
	 * Display user selector control
	 */
	public function getDestinationData($context = 'TASKS')
	{
		$validCtxs = static::getValidDestinationDataContexts();
		if(!in_array($context, $validCtxs))
		{
			$this->errors->add('INVALID_CONTEXT', 'Invalid context passed');
			return array();
		}

		$params = [
			'AVATAR_WIDTH' => static::ALLOWED_AVATAR_SIZE,
			'AVATAR_HEIGHT' => static::ALLOWED_AVATAR_SIZE,
			'USE_PROJECTS' => 'Y'
		];

		return \Bitrix\Tasks\Integration\SocialNetwork::getLogDestination($context, $params);
	}

	public function setDestinationLast($items = array(), $context = 'TASKS')
	{
		if(empty($items))
		{
			return array();
		}

		$validCtxs = static::getValidDestinationDataContexts();
		if(!in_array($context, $validCtxs))
		{
			$this->errors->add('INVALID_CONTEXT', 'Invalid context passed');
			return array();
		}

		\Bitrix\Tasks\Integration\SocialNetwork::setLogDestinationLast($items, $context);

		return array();
	}

	private static function getValidDestinationDataContexts()
	{
		// todo: there may be an event that obtains a list of valid contexts

		return array(
			'TASKS',
			'TASKS_RIGHTS'
		);
	}

	public function getMemberList($groupId)
	{
		if (!is_int($groupId) && !is_string($groupId))
		{
			$this->errors->add('ILLEGAL_ID', 'Group ID is illegal');

			return array();
		}

		$groupId = filter_var(
			$groupId,
			FILTER_VALIDATE_INT,
			array('options' => array('min_range' => 1))
		);
		if ($groupId === false)
		{
			$this->errors->add('ILLEGAL_ID', 'Group ID is illegal');

			return array();
		}

		$members = \Bitrix\Tasks\Integration\SocialNetwork::getMemberList($groupId, $this->userId);
		if ($members === null)
		{
			$this->addForbiddenError();

			return array();
		}

		return $members;
	}
}
