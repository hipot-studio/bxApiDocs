<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Internal\Repositories\LocalServerRepository;

final class ServerRuntimeService
{
	public function __construct(private ?LocalServerRepository $repository = null)
	{
		$this->repository ??= new LocalServerRepository();
	}

	public function create(array $fields): Result
	{
		$server = $this->repository->create($fields);
		$saveResult = $this->repository->save($server);

		return $this->buildServerResult($server, $saveResult);
	}

	public function update(Server $server, array $fields): Result
	{
		$saveResult = $this->repository->update($server, $fields);

		return $this->buildServerResult($server, $saveResult);
	}

	public function delete(Server $server): Result
	{
		$result = $this->repository->deleteWithUsers($server);
		if ($result->isSuccess())
		{
			$result->setData([
				'deleted' => true,
			]);
		}

		return $result;
	}

	public function generateJwtKeys(Server $server): Result
	{
		$keyGenerator = new JwtKeyGenerator();

		$result = $this->update($server, [
			'jwtSecret' => $keyGenerator->getPrivateKey(),
		]);
		if ($result->isSuccess())
		{
			$data = $result->getData();
			$data['publicKey'] = $keyGenerator->getBase64EncodePublicKey();
			$result->setData($data);
		}

		return $result;
	}

	private function buildServerResult(Server $server, Result $result): Result
	{
		if (!$result->isSuccess())
		{
			return $result;
		}

		$result->setData([
			'serverEntity' => $server,
		] + $result->getData());

		return $result;
	}
}
