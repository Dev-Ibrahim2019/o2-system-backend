<?php

namespace App\Http\Controllers\Api\External;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateExternalApiClient;
use App\Http\Resources\External\TakeawayOrderResource;
use App\Models\ExternalApiClient;
use App\Services\Takeaway\TakeawayOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * /api/v1/external/takeaway — API لبرنامج التيك أواي الخارجي (تعيين السائقين ومتابعة التحضير والتسليم).
 * العقد موثّق بـ docs/external-takeaway-api.yaml. الأخطاء كلها بالشكل {"error": {"code", "message"}}
 * (bootstrap/app.php)، والمصادقة والـrate limit بالـmiddleware على المجموعة.
 */
class TakeawayOrderController extends Controller
{
    public function __construct(private readonly TakeawayOrderService $takeaway) {}

    /**
     * GET /orders — القائمة مع فلاتر وpagination. updated_since للـpolling: بيرجّع اللي تغيّر من هالوقت
     * (>=) مرتّب تصاعديًا بـ updated_at، فالتطبيق بياخد updated_at تبع آخر عنصر كـ updated_since الجاي.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(TakeawayOrderService::STATUSES)],
            'order_type' => ['sometimes', Rule::in(TakeawayOrderService::ORDER_TYPES)],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'updated_since' => ['sometimes', 'date'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->takeaway->query()->with(TakeawayOrderResource::RELATIONS);

        if (isset($data['status'])) {
            $data['status'] === TakeawayOrderService::STATUS_NEW
                ? $query->whereNull('tw_status')
                : $query->where('tw_status', $data['status']);
        }
        if (isset($data['order_type'])) {
            $query->where('order_type', $data['order_type']);
        }
        if (isset($data['branch_id'])) {
            $query->where('branch_id', $data['branch_id']);
        }
        if (isset($data['from'])) {
            $query->where('created_at', '>=', Carbon::parse($data['from'])->utc());
        }
        if (isset($data['to'])) {
            $query->where('created_at', '<=', Carbon::parse($data['to'])->utc());
        }
        if (isset($data['updated_since'])) {
            $query->where('updated_at', '>=', Carbon::parse($data['updated_since'])->utc())
                ->orderBy('updated_at')->orderBy('id');
        } else {
            $query->orderByDesc('tw_released_at')->orderByDesc('id');
        }

        $page = $query->paginate((int) ($data['per_page'] ?? 25))->withQueryString();

        return response()->json([
            'data' => TakeawayOrderResource::collection($page->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /** GET /orders/{id} */
    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => (new TakeawayOrderResource($this->takeaway->findOrFail($id)))->resolve()]);
    }

    /** PATCH /orders/{id}/status — الانتقالات المسموحة بس (409 غير هيك)؛ نفس الحالة مرتين = no-op آمن. */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(array_values(array_diff(TakeawayOrderService::STATUSES, [TakeawayOrderService::STATUS_NEW])))],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $this->takeaway->changeStatus($id, $data['status'], $this->client($request), $data['note'] ?? null);

        return $this->show($id);
    }

    /** PATCH /orders/{id}/driver — تعيين/تغيير سائق لطلب توصيل (قبل ما يستلمه). */
    public function assignDriver(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'driver' => ['required', 'array'],
            'driver.name' => ['required', 'string', 'max:100'],
            'driver.phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'driver.external_id' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $this->takeaway->assignDriver($id, $data['driver'], $this->client($request));

        return $this->show($id);
    }

    private function client(Request $request): ExternalApiClient
    {
        return $request->attributes->get(AuthenticateExternalApiClient::REQUEST_ATTRIBUTE);
    }
}
