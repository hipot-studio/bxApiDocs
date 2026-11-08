<?php

namespace Bitrix\BIConnector\Internal\PortalHealthcheck;

final class CallbackHandler
{
	public function __construct(
		private readonly StateRepository $repository = new StateRepository(),
		private readonly ResultValidator $validator = new ResultValidator(),
	)
	{
	}

	public function handle(array $body): array
	{
		$validation = $this->validator->validateCallback($body);
		if (!$validation->isSuccess())
		{
			return [400, ['error' => 'BAD_REQUEST']];
		}

		$data = $validation->getData();
		$tokenHash = hash('sha256', $data['token']);
		$diagnosticId = $data['diagnosticId'];
		unset($data['token']);
		$accepted = $this->repository->accept($tokenHash, $diagnosticId, $data);
		if ($accepted === 'forbidden')
		{
			return [403, ['error' => 'HEALTH_TOKEN_INVALID']];
		}

		return [200, ['status' => $accepted]];
	}
}
