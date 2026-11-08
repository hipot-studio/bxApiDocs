<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\AI;

use Bitrix\Main;
use Bitrix\Landing;
use Bitrix\AI;
use Bitrix\AI\Payload;
use Bitrix\AI\Tuning;

class Image extends BaseConnector
{
	protected const CATEGORY = 'image';
	protected const CONTEXT_ID = 'landing_site_copilot_image';
	// the engine itself is registered by the ai module
	private const FLUX_ENGINE_CODE = 'FluxLitellm';

	public function request(Prompt $prompt): Main\Result
	{
		$result = new Main\Result();

		if (!$this->canRequest())
		{
			$result->addError(
				new Main\Error(
					'Ai is not available now', 'AI_UNAVAILABLE'
				)
			);

			return $result;
		}

		$this->setTemperature($prompt->getTemperature());

		$payload = new Payload\StyledPicture($prompt->getCode());
		$payload->setMarkers($prompt->getMarkers());

		$cost = $prompt->getCost();
		if ($cost !== null)
		{
			$payload->setCost($cost);
		}

		$this->engine->setPayload($payload);
		$this->engine->setHistoryState(true);

		$this->engine
			->onSuccess(function (AI\Result $aiResult, ?string $hash = null) use (&$result) {
				if ($hash)
				{
					$result->setData(['hash' => $hash]);
				}
				else
				{
					$result->setData(['result' => $aiResult->getPrettifiedData()]);
				}
			})
			->onError(function (Main\Error $error) use (&$result) {
				$result->addError(new Main\Error(
					$error->getMessage(), $error->getCode()
				));
			})
			->completionsInQueue()
		;

		return $result;
	}

	protected function getEngineCode(): ?string
	{
		$fluxEngineCode = static::getFluxEngineCode();
		if ($fluxEngineCode !== null)
		{
			return $fluxEngineCode;
		}

		$manager = new Tuning\Manager();

		return $manager->getItem(Landing\Connector\Ai::TUNING_CODE_SITE_IMAGE_PROVIDER)?->getValue();
	}

	/**
	 * FLUX engine code when the landing option is on and the ai module has the engine available.
	 * With the option off nothing else is called.
	 *
	 * @return string|null
	 */
	public static function getFluxEngineCode(): ?string
	{
		if (!Landing\Copilot\Manager::isFluxImageEnabled())
		{
			return null;
		}

		return static::isFluxEngineAvailable() ? self::FLUX_ENGINE_CODE : null;
	}

	protected static function isFluxEngineAvailable(): bool
	{
		if (!Main\Loader::includeModule('ai'))
		{
			return false;
		}

		return AI\Engine::getByCode(
			self::FLUX_ENGINE_CODE,
			new AI\Context('landing', static::CONTEXT_ID),
			static::CATEGORY
		) !== null;
	}
}
