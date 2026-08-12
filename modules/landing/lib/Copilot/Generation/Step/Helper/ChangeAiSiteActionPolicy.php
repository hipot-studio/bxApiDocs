<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step\Helper;

final class ChangeAiSiteActionPolicy
{
	public const REASON_NOT_AVAILABLE = 'BLOCK_NOT_AVAILABLE_FOR_CHANGE';

	public function buildCandidatePolicy(int $blockId, bool $canUpdate): array
	{
		return [
			'canUpdate' => $canUpdate,
			'canDelete' => true,
			'canMove' => true,
			'hardDeny' => false,
			'policyReason' => '',
		];
	}

	public function validateAction(string $actionType, array $candidate = []): array
	{
		$policy = $this->normalizeCandidatePolicy($candidate);
		if ($policy['hardDeny'])
		{
			return $this->deny($policy['policyReason']);
		}

		if ($actionType === 'delete_block' && !$policy['canDelete'])
		{
			return $this->deny($policy['policyReason']);
		}

		if ($actionType === 'move_block' && !$policy['canMove'])
		{
			return $this->deny($policy['policyReason']);
		}

		return [
			'allowed' => true,
			'reason' => '',
		];
	}

	private function normalizeCandidatePolicy(array $candidate): array
	{
		$reason = trim((string)($candidate['policyReason'] ?? $candidate['reason'] ?? ''));

		return [
			'canUpdate' => array_key_exists('canUpdate', $candidate) ? !empty($candidate['canUpdate']) : true,
			'canDelete' => array_key_exists('canDelete', $candidate) ? !empty($candidate['canDelete']) : true,
			'canMove' => array_key_exists('canMove', $candidate) ? !empty($candidate['canMove']) : true,
			'hardDeny' => !empty($candidate['hardDeny']),
			'policyReason' => $reason !== '' ? $reason : self::REASON_NOT_AVAILABLE,
		];
	}

	private function deny(string $reason): array
	{
		return [
			'allowed' => false,
			'reason' => $reason !== '' ? $reason : self::REASON_NOT_AVAILABLE,
		];
	}
}
