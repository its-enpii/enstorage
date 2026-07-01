<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Webhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    /**
     * GET /webhooks
     */
    public function index(Request $request): JsonResponse
    {
        $query = Webhook::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->select(['id', 'label', 'url', 'events', 'is_active', 'last_triggered_at', 'last_status', 'created_at']);

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));

        return $this->paginated($query->paginate($perPage, ['*'], 'page', $page), null, __('Daftar webhook.'));
    }

    /**
     * POST /webhooks
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'in:'.implode(',', Webhook::EVENTS)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $secret = bin2hex(random_bytes(32));

        $webhook = Webhook::create([
            'user_id' => $request->user()->id,
            'label' => $data['label'],
            'url' => $data['url'],
            'events' => $data['events'],
            'is_active' => $data['is_active'] ?? true,
            'secret' => $secret,
        ]);

        // Secret hanya dikembalikan sekali saat dibuat
        return $this->ok([
            'id' => $webhook->id,
            'label' => $webhook->label,
            'url' => $webhook->url,
            'events' => $webhook->events,
            'is_active' => $webhook->is_active,
            'secret' => $secret,
            'created_at' => $webhook->created_at,
        ], __('Webhook berhasil dibuat. Simpan secret — tidak akan ditampilkan lagi.'), [], 201);
    }

    /**
     * PATCH /webhooks/{id} — toggle aktif/nonaktif, edit label/url/events.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $webhook = Webhook::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();
        if (! $webhook) {
            return $this->fail(__('Webhook tidak ditemukan.'), 404);
        }

        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:100'],
            'url' => ['sometimes', 'url', 'max:2048'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string', 'in:'.implode(',', Webhook::EVENTS)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $webhook->fill($data);
        $webhook->save();

        return $this->ok($webhook->only(['id', 'label', 'url', 'events', 'is_active']), __('Webhook diperbarui.'));
    }

    /**
     * DELETE /webhooks/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $webhook = Webhook::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();
        if (! $webhook) {
            return $this->fail(__('Webhook tidak ditemukan.'), 404);
        }

        $webhook->delete();
        return $this->ok(null, __('Webhook dihapus.'));
    }
}
