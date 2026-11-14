<?php
namespace Bitrix\ImOpenLines\Crm;

use Bitrix\Main\Config\Option;

/**
 * Class Agent
 *
 * @package Bitrix\ImOpenLines\Crm
 */
class Agent
{
	/**
	 * @param string $oldUserCode
	 * @param string $newUserCode
	 */
	public static function addUniqueReplacementUserCodeAgent(string $oldUserCode, string $newUserCode): void
	{
		$moduleId = 'imopenlines';

		// the name must match the one Stepper::bindClass() creates: user codes are escaped arguments of execAgent()
		$agentArguments = ReplacementUserCode::makeArguments([$oldUserCode, $newUserCode]);
		$agentName = ReplacementUserCode::class . '::execAgent(' . $agentArguments . ');';

		$isAgent = \CAgent::getList([], [
			'MODULE_ID' => $moduleId,
			'NAME' => $agentName
		])->fetch();

		if(!$isAgent)
		{
			$optionName = ReplacementUserCode::OPTION_NAME . md5($oldUserCode . $newUserCode);
			$params = Option::get(ReplacementUserCode::$moduleId, $optionName, '');

			if (empty($params))
			{
				ReplacementUserCode::bind(ReplacementUserCode::AGENT_DELAY, [$oldUserCode, $newUserCode]);
			}
		}
	}
}