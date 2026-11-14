<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Mobile\BitrixGpt\Voice\Answer;
use Bitrix\Mobile\BitrixGpt\Voice\AudioUpload;
use Bitrix\Mobile\BitrixGpt\Voice\Availability;
use Bitrix\Mobile\BitrixGpt\Voice\ActionFilter\FeatureFilter;
use Bitrix\Mobile\BitrixGpt\Voice\Transcription;

final class BitrixGptVoice extends Controller
{
	public function checkRecordingAction(Availability $availability): ?array
	{
		return $this->respond($availability->checkRecording((int)$this->getCurrentUser()->getId()));
	}

	/**
	 * Reports whether the voice widget is currently available to the authenticated user.
	 * Unlike the cached feature flag, the client requests it once per app launch.
	 *
	 * @return array{available: bool}
	 */
	public function getAvailabilityAction(Availability $availability): array
	{
		return ['available' => $availability->isAvailable((int)$this->getCurrentUser()->getId())];
	}

	/**
	 * Returns the final AI reply to the authenticated user's chat message.
	 *
	 * @return array|null Reply status and optional text, or an action error.
	 */
	public function getAnswerAction(Answer $answer, int $messageId): ?array
	{
		return $this->respond($answer->get((int)$this->getCurrentUser()->getId(), $messageId));
	}

	protected function getDefaultPreFilters(): array
	{
		return [
			new ActionFilter\Authentication(),
			new FeatureFilter(),
			new ActionFilter\Csrf(),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			new ActionFilter\Scope(ActionFilter\Scope::AJAX),
			new ActionFilter\CloseSession(),
		];
	}

	/**
	 * Starts recognition of the authenticated user's uploaded audio fragment.
	 *
	 * @return array|null Queue hash or direct text with a signed upload token, or an action error.
	 */
	public function transcribeAction(Transcription $transcription, Availability $availability): ?array
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if (!$availability->isAvailable($userId))
		{
			$this->addError(new Error('Voice widget is disabled.', 'FEATURE_DISABLED'));

			return null;
		}

		$file = $this->getRequest()->getFile('file');
		if (
			!is_array($file)
			|| ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
			|| !is_string($file['tmp_name'] ?? null)
			|| !is_uploaded_file($file['tmp_name'])
		)
		{
			$this->addError(new Error('Expected an uploaded audio fragment.', 'INVALID_AUDIO'));

			return null;
		}

		return $this->respond($transcription->transcribe(
			$userId,
			$file['tmp_name'],
		));
	}

	/**
	 * Releases a finished audio upload using its user-bound signed token.
	 *
	 * @param string $uploadToken
	 * @return array|null Removal confirmation or an action error.
	 */
	public function removeUploadAction(string $uploadToken): ?array
	{
		if (!Loader::includeModule('ui'))
		{
			$this->addError(new Error('Audio upload is unavailable.', 'UPLOAD_UNAVAILABLE'));

			return null;
		}

		return $this->respond((new AudioUpload((int)$this->getCurrentUser()->getId()))->remove($uploadToken));
	}

	private function respond(Result $result): ?array
	{
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}
}
