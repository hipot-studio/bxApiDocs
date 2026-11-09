<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\AI;

use Bitrix\AiAssistant\External\AiNode\Builder\MartaRequestBuilder;
use Bitrix\AiAssistant\External\AiNode\Dto\MartaNodeDto;
use Bitrix\AiAssistant\External\AiNode\Service\MartaAiClient;
use Bitrix\Landing\Markup\Repair\PackageJsonSchema;
use Bitrix\Landing\Markup\Repair\RepairNodePrompt;
use Bitrix\Main;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;

/**
 * Connector of the External/AiNode package repairer: the request goes to the
 * aiassistant Marta node queue and the answer comes back asynchronously through
 * the MartaAiNodeWebhook events, exactly like the ai-module queue of the image
 * steps. The returned request uuid plays the role of the queue hash, so the
 * standard Request persistence and resume path work unchanged.
 *
 * Prompt markers carry the call: `payload` - the {package, errors} object sent
 * as the node query, `userId` - the generation author the request is signed for.
 */
class MarkupRepairNode implements IConnector
{
	public function request(Prompt $prompt): Main\Result
	{
		$result = new Main\Result();

		if (!Loader::includeModule('aiassistant'))
		{
			return $result->addError(new Main\Error(
				'The aiassistant module is not available for the markup repair node.',
				'AI_UNAVAILABLE',
			));
		}

		$markers = $prompt->getMarkers();
		$payload = $markers['payload'] ?? null;
		$userId = (int)($markers['userId'] ?? 0);
		if (!is_array($payload) || $userId <= 0)
		{
			return $result->addError(new Main\Error(
				'The markup repair node call is incomplete: payload and userId are required.',
				'BAD_REQUEST',
			));
		}

		try
		{
			$request = (new MartaRequestBuilder())
				->setUserId($userId)
				->setQuery(Json::encode($payload))
				->setNode(MartaNodeDto::createWithJsonSchema(
					RepairNodePrompt::NODE_NAME,
					RepairNodePrompt::getSystemPrompt(),
					(new PackageJsonSchema())->getSchema(),
				))
				->build()
			;
			/** @var MartaAiClient $client */
			$client = ServiceLocator::getInstance()->get(MartaAiClient::class);
			$uuid = $client->execute($request);
		}
		catch (\Throwable $exception)
		{
			return $result->addError(new Main\Error($exception->getMessage(), 'AI_UNAVAILABLE'));
		}

		if ($uuid === '')
		{
			return $result->addError(new Main\Error(
				'The markup repair node returned an empty request uuid.',
				'AI_UNAVAILABLE',
			));
		}

		$result->setData(['hash' => $uuid]);

		return $result;
	}
}
