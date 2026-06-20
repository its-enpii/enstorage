<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApiKeyResource;
use App\Models\ActivityLog;
use App\Models\ApiKey;
use App\Services\ActivityLogService;
use App\Services\ApiKey\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    public function __construct(
        private readonly ApiKeyService $service,
        private readonly ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ApiKey::where('user_id', $request->user()->id)
            ->orderByDesc('created_at');

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));

        return $this->paginated($query->paginate($perPage, ['*'], 'page', $page), ApiKeyResource::class, __('Daftar API key.'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'in:read,write,delete,full'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        [$apiKey, $plaintext] = $this->service->create(
            userId: $request->user()->id,
            label: $data['label'],
            scopes: $data['scopes'],
            expiresAt: isset($data['expires_at']) ? new \DateTimeImmutable($data['expires_at']) : null,
        );

        $this->activityLog->log(
            ActivityLog::ACTION_API_KEY_CREATE,
            userId: $request->user()->id,
            subject: $apiKey,
            metadata: ['label' => $apiKey->label, 'scopes' => $apiKey->scopes],
            request: $request,
        );

        return $this->created([
            'key' => array_merge(
                (new ApiKeyResource($apiKey))->toArray($request),
                ['plaintext' => $plaintext],
            ),
        ], __('API key dibuat. Simpan plaintext sekarang — tidak akan ditampilkan lagi.'));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $apiKey = ApiKey::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $apiKey) {
            return $this->fail(__('API key tidak ditemukan.'), 404);
        }

        $label = $apiKey->label;
        $apiKey->delete();

        $this->activityLog->log(
            ActivityLog::ACTION_API_KEY_REVOKE,
            userId: $request->user()->id,
            metadata: ['api_key_id' => $id, 'label' => $label],
            request: $request,
        );

        return $this->ok(null, __('API key dicabut.'));
    }
}
