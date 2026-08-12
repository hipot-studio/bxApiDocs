<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\AI;

use Bitrix\Main;

final class CreateAiSiteText implements IConnector
{
	private IConnector $connector;

	public function __construct()
	{
		$this->connector = new Text();
	}

	public function request(Prompt $prompt): Main\Result
	{
		return $this->connector->request($prompt);
	}
}
