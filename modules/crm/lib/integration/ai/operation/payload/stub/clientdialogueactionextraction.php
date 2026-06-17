<?php

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Stub;

use Bitrix\Crm\Integration\AI\Operation\AnalyzeCommunication;
use Bitrix\Crm\Integration\AI\Operation\Payload\StubInterface;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

final class ClientDialogueActionExtraction implements StubInterface
{
	public function makeStub(): string
	{
		$isClient = (bool)Random::getInt(0, 1);
		$reasonIfIsClientFalse = null;
		$actions = null;
		if ($isClient)
		{
			$actions = [
				[
					'title' => 'Follow up with the client',
					'description' => 'The client mentioned that they have some concerns about the product. It would be good to reach out to them and address their concerns to ensure customer satisfaction.',
					'responsible_person' => 'John Doe',
					'deadline' => (new DateTime())->add('+2 days')->format(AnalyzeCommunication::DATE_FORMAT),
				]
			];
		}
		else
		{
			$reasonIfIsClientFalse = "The person is not a client, but an employee who called by mistake.";
		}

		return Json::encode([
			'is_client' => $isClient,
			'reason_if_is_client_false' => $reasonIfIsClientFalse,
			'actions' => $actions,
		]);
	}
}
