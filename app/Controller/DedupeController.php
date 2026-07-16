<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DedupeService;
use Hyperf\Di\Annotation\Inject;
use InvalidArgumentException;

final class DedupeController extends AbstractController
{
    #[Inject]
    protected DedupeService $dedupeService;

    public function health(): array
    {
        return $this->ok(['db' => 'postgres', 'documents' => $this->dedupeService->documentCount()]);
    }

    public function check(): array
    {
        $payload = $this->request->all();
        $source = [
            'id' => $payload['id'] ?? null,
            'source_from' => $payload['source_from'] ?? null,
            'title' => $payload['title'] ?? null,
            'content' => $payload['content'] ?? null,
        ];
        if ((string) $source['title'] === '' && (string) $source['content'] === '') {
            return $this->fail('title/content are all empty');
        }

        try {
            $options = [];
            foreach (['max_hamming', 'max_bucket_size', 'limit'] as $name) {
                if (array_key_exists($name, $payload)) {
                    $options[$name] = (int) $payload[$name];
                }
            }
            if (isset($payload['levels']) && is_array($payload['levels'])) {
                $options['levels'] = array_values(array_filter($payload['levels'], 'is_string'));
            }
            if (array_key_exists('insert_on_check', $payload)) {
                $options['insert_on_check'] = filter_var($payload['insert_on_check'], FILTER_VALIDATE_BOOLEAN);
            }
            return $this->ok($this->dedupeService->check($source, $options));
        } catch (InvalidArgumentException $exception) {
            return $this->fail($exception->getMessage());
        }
    }

    private function ok(array $data = [], string $msg = ''): array
    {
        return ['status' => 1, 'msg' => $msg, 'data' => $data];
    }

    private function fail(string $msg, array $data = []): array
    {
        return ['status' => 0, 'msg' => $msg, 'data' => $data];
    }

}
