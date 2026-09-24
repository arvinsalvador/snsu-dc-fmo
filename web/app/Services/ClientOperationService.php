<?php

namespace App\Services;

use App\Models\ProcessedClientOperation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ClientOperationService
{
    /** @param array<string, mixed> $payload @param \Closure(): array<string, mixed> $perform */
    public function process(User $actor, string $operationId, ?string $installationId, string $type, array $payload, ?string $clientCreatedAt, \Closure $perform): array
    {
        $hash = hash('sha256', json_encode(['type' => $type, 'payload' => $this->canonical($payload)], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $operationId, $installationId, $type, $hash, $clientCreatedAt, $perform): array {
            // Serializes concurrent retries by the same account before the unique operation insert.
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $existing = ProcessedClientOperation::where('user_id', $actor->id)->where('client_operation_id', $operationId)->first();
            if ($existing) {
                if ($existing->request_hash !== $hash) {
                    throw new ConflictHttpException('This operation ID was already used for different data.');
                }

                return $existing->result;
            }
            $result = $perform();
            ProcessedClientOperation::create(['user_id' => $actor->id, 'client_operation_id' => $operationId, 'installation_id' => $installationId, 'type' => $type, 'request_hash' => $hash, 'result' => $result, 'client_created_at' => $clientCreatedAt, 'processed_at' => now()]);

            return $result;
        });
    }

    private function canonical(array $payload): array
    {
        ksort($payload);
        foreach ($payload as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $payload;
    }
}
