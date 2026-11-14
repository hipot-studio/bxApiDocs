<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search;

use Bitrix\Im\V2\Message;

/**
 * Hidden and service messages of the Open Lines, told apart by the string constants of that module.
 * The link is one-way - a hidden type added on the Open Lines side does not reach this list on its
 * own - so a new one is written down here by hand.
 */
class OpenLinesMessageRegistry
{
	private const SERVICE_COMPONENT_IDS = [
		'HiddenMessage',
		'StartDialogMessage',
		'FeedbackFormMessage',
	];

	/** Message components of the Open Lines are named with this prefix. */
	private const SERVICE_COMPONENT_PREFIX = 'bx-imopenlines-';

	private const SERVICE_PARAM_NAMES = ['IMOL_FORM'];

	/** Vote request and its answers: IMOL_VOTE, IMOL_VOTE_HEAD, IMOL_VOTE_SID and the rest of the family. */
	private const VOTE_PARAM_PREFIX = 'IMOL_VOTE';

	public function isServiceMessage(Message\Params $params): bool
	{
		return $this->hasServiceComponent($params) || $this->hasServiceParam($params);
	}

	private function hasServiceComponent(Message\Params $params): bool
	{
		$componentId = (string)$params->get(Message\Params::COMPONENT_ID)->getValue();

		return in_array($componentId, self::SERVICE_COMPONENT_IDS, true)
			|| str_starts_with($componentId, self::SERVICE_COMPONENT_PREFIX)
		;
	}

	private function hasServiceParam(Message\Params $params): bool
	{
		foreach (array_keys($params->getArrayCopy()) as $key)
		{
			$name = (string)$key;
			if ($this->isServiceParamName($name) && $params->isSet($name))
			{
				return true;
			}
		}

		return false;
	}

	private function isServiceParamName(string $name): bool
	{
		return in_array($name, self::SERVICE_PARAM_NAMES, true)
			|| str_starts_with($name, self::VOTE_PARAM_PREFIX)
		;
	}
}
