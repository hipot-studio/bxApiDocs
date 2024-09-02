<?php

namespace Bitrix\AI\Controller;

use Bitrix\AI\Engine;
use Bitrix\AI\Engine\IQueue;
use Bitrix\AI\Facade;
use Bitrix\AI\Payload;
use Bitrix\AI\Prompt\Role;
use Bitrix\AI\Result;
use Bitrix\AI\Role\RoleManager;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Error;

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
	 * @param array $markers Marker for replacing in prompt ({key} => value).
	 * @param array $parameters Additional params for tuning query.
	 * @return array
	 */
	public function completionsAction(string $prompt, array $markers = [], array $parameters = []): array
	{
		if (!empty($this->getErrors()))
		{
			return [];
		}

		$engine = Engine::getByCategory($this->category, $this->context);
		if (!$engine)
		{
			return [];
		}

		if (!$this->checkAgreementAccepted($engine))
		{
			return [];
		}

		if (isset($markers['style']) && $prompt !== '')
		{
			$prompt = $this->translatePrompt($prompt);
		}

		$isQueueable = $engine instanceof IQueue;

		$payload = (new Payload\StyledPicture($prompt))->setMarkers($markers);

		$engine->setPayload($payload)
			->setAnalyticData($parameters['bx_analytic'] ?? [])
			->setHistoryState($this->isTrue($parameters['bx_history'] ?? false))
			->setHistoryGroupId($this->parseInt($parameters['bx_history_group_id'] ?? -1))
			->onSuccess(function (Result $result, ?string $hash = null) use($isQueueable) {
				$this->result = $isQueueable ? $result->getRawData() : $result->getPrettifiedData();
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
	 * Get translate for prompt
	 *
	 * @param string|null $prompt
	 *
	 * @return string
	 */
	private function translatePrompt(string $prompt): string
	{
		// translate request
		$textTranslate = null;
		$engineText = Engine::getByCategory(Engine::CATEGORIES['text'], $this->context);
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

		return $textTranslate ?? '';
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
