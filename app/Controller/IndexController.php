<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Controller;
use App\Service\DedupeService;
use Hyperf\Di\Annotation\Inject;

class IndexController extends AbstractController
{
    #[Inject]
    protected DedupeService $dedupeService;

    public function index()
    {
        return [
            'status' => 'ok',
            'documents' => $this->dedupeService->documentCount(),
        ];
    }
}
