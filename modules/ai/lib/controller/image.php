<?php

namespace Bitrix\AI\Controller;

use Bitrix\AI\Config;
use Bitrix\AI\Engine;
use Bitrix\AI\Engine\ThirdParty;
use Bitrix\AI\Engine\IEngine;
use Bitrix\AI\Engine\IQueue;
use Bitrix\AI\Facade;
use Bitrix\AI\Facade\Bitrix24;
use Bitrix\AI\Facade\User;
use Bitrix\AI\ImageStylePrompt\ImageStylePromptManager;
use Bitrix\AI\Payload;
use Bitrix\AI\Prompt\Role;
use Bitrix\AI\Result;
use Bitrix\AI\Role\RoleManager;
use Bitrix\Bitrix24\Integration\AI\Engine\StableDiffusion;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;

/**
 * Controller for work with image AI Engine
 */
class Image extends Controller
{
	protected ?string $category = Engine::CATEGORIES['image'];
	private mixed $result = null;
	private ?string $hash = null;

	public function getDefaultPreFilters(): array
	{
		return [
			new ActionFilter\Authentication(),
		];
	}

	/**
	 * Make request to AI Engine. The model will return one or more predicted completions.
	 *
	 * @param string $prompt Prompt to completion.
	 * @param string|null $engineCode Engine's code (by default the selected Engine of the category is used).
	 * @param array $markers Image options: style, format, images_number.
	 * @param array $parameters Context (see parent class) and params: bx_history, bx_history_group_id, bx_analytic.
	 * @return array{result: mixed, queue: string|null}|array{} Empty array if the context or agreement check fails,
	 *   or if the engine is not found (no error is added in that case). On an engine error the shape is returned
	 *   with result = null and the error is added to the controller errors. A failed prompt translation adds
	 *   its error but does not stop the generation (the original prompt is used).
	 */
	public function completionsAction(string $prompt, string $engineCode = null, array $markers = [], array $parameters = []): array
	{
		if (!empty($this->getErrors()))
		{
			return [];
		}

		$engine = $engineCode === null
			? Engine::getByCategory($this->category, $this->context)
			: Engine::getByCode($engineCode, $this->context, $this->category);

		if (!$engine)
		{
			return [];
		}

		if (!$this->checkAgreementAccepted($engine))
		{
			return [];
		}

		if (isset($markers['style']) && $prompt !== '' && $engine->getIEngine() instanceof StableDiffusion)
		{
			$prompt = $this->translatePrompt($prompt);
		}

		$payload = (new Payload\StyledPicture($prompt))->setMarkers($markers);

		$engine->setPayload($payload)
			->setAnalyticData($parameters['bx_analytic'] ?? [])
			->setHistoryState($this->isTrue($parameters['bx_history'] ?? false))
			->setHistoryGroupId($this->parseInt($parameters['bx_history_group_id'] ?? -1))
			->onSuccess(function (Result $result, ?string $hash = null) {
				$this->result = $result->getPrettifiedData();
				$this->hash = $hash;
			})
			->onError(function (Error $error){
				$this->addError($error);
			})
			->completions()
		;

		return [
			'result' => $this->result,
			'queue' => $this->hash,
		];
	}

	/**
	 * Returns image formats and styles available for the image Engine.
	 *
	 * @param string $engineCode Engine's code.
	 * @param array $parameters Parameters with context. See parent class.
	 * @return array{formats: array, styles: array}|array{} Empty array if the Engine is not found.
	 */
	public function getParamsAction(string $engineCode, array $parameters = []): array
	{
		$engine = Engine::getByCode($engineCode, $this->context, $this->category);
		if ($engine === null || $engine->getIEngine()->getCategory() !== $this->category)
		{
			$this->addError(new Error('Engine not found'));

			return [];
		}

		return $this->getEngineParams($engine->getIEngine());
	}

	/**
	 * Returns tooling for image ai Client's UI.
	 *
	 * @param array $parameters Additional params for tuning query.
	 * @return array
	 */
	public function getToolingAction(array $parameters = []): array
	{
		if (!empty($this->getErrors()))
		{
			return [];
		}

		$category = 'image';

		$engines = Engine::getData($category, $this->context);
		$selectEngine = null;
		foreach ($engines as $engine)
		{
			if ($engine['selected'])
			{
				$selectEngine = Engine::getByCode($engine['code'], $this->context, $category)?->getIEngine();
				break;
			}
		}

		if ($selectEngine === null)
		{
			$this->addError(new Error('Selected engine not found'));

			return [];
		}

		return [
			'engines' => $engines,
			'params' => $this->getEngineParams($selectEngine),
			// todo replace with Bitrix24:getPortalZone() when Bitrix24:getPortalZone() will be fixed
			'portal_zone' => Loader::includeModule('bitrix24') ? Bitrix24::getPortalZone() : LANGUAGE_ID,
			'first_launch' => Config::getPersonalValue('first_launch') !== 'N' && Bitrix24::shouldUseB24(),
		];
	}

	/**
	 * @param IEngine|null $engine
	 *
	 * @return array
	 */
	private function getEngineParams(?Engine\IEngine $engine): array
	{
		return [
			'formats' => $this->getImageFormats($engine),
			'styles' => ((new ImageStylePromptManager(User::getUserLanguage()))->list()),
		];
	}

	/**
	 * @param IEngine|null $engine
	 *
	 * @return array
	 */
	private function getImageFormats(?Engine\IEngine $engine): array
	{
		$formats = [];

		$engineImageFormats = $engine->getImageFormats();

		foreach ($engineImageFormats as $format)
		{
			$formats[] = [
				'code' => $format['code'],
				'name' => $format['name'],
			];
		}

		return $formats;
	}

	/**
	 * Translates the prompt via YandexGPT; returns the original prompt if translation is unavailable.
	 * Translation errors are added to the controller's errors.
	 *
	 * @param string $prompt
	 *
	 * @return string
	 */
	private function translatePrompt(string $prompt): string
	{
		// translate request
		$textTranslate = null;
		$engineText = Engine::getByCode('YandexGPT', $this->context, Engine::CATEGORIES['text']);

		if (!$engineText)
		{
			return $prompt;
		}

		$payload = (new Payload\Prompt('translate_picture_request'))->setMarkers([ 'original_message' => $prompt, 'user_message' => '']);
		$payload->setRole(Role::get(RoleManager::getUniversalRoleCode()));

		$engineText->setPayload($payload)
			->onSuccess(function(Result $result, ?string $hash = null) use(&$textTranslate) {
				$textTranslate = $result->getPrettifiedData();
			})
			->onError(function(Error $error) {
				$this->addError($error);
			})
			->completions()
		;

		return $textTranslate ?? $prompt;
	}

	/**
	 * Retrieves external image and saves to DB. Returns local file id.
	 *
	 * @param string $pictureUrl Picture external URL.
	 * @param array $parameters Context parameters.
	 * @return int|null
	 */
	public function saveAction(string $pictureUrl, array $parameters = []): ?int
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		return Facade\File::saveImageByURL($pictureUrl, $this->context->getModuleId());
	}
}
