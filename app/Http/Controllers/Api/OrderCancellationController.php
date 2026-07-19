<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\ApiController;
use App\Models\{DeliveryTrip,DeliveryTripStop,OrderCancellationRequest};
use App\Services\Delivery\OrderCancellationService;
use Illuminate\Http\{JsonResponse,Request};
use Illuminate\Validation\Rule;
class OrderCancellationController extends ApiController
{
    public function __construct(private readonly OrderCancellationService $service){}
    public function cancelStop(Request $request,DeliveryTrip $trip,DeliveryTripStop $stop):JsonResponse{$data=$request->validate(['reason_code'=>['required',Rule::in(OrderCancellationService::REASONS)],'reason_text'=>['nullable','string','max:2000'],'customer_confirmed'=>['boolean']]);$result=$this->service->request($trip,$stop,$request->user(),$data);return $this->success($result->status==='auto_approved'?'Order cancelled':'Cancellation sent for review',$result);}
    public function index(Request $request):JsonResponse{$q=OrderCancellationRequest::with(['order.customer','trip.driver','stop'])->when($request->status,fn($x,$v)=>$x->where('status',$v))->latest();if(!$request->user()->hasRole('super-admin'))$q->whereHas('order',fn($x)=>$x->where('branch_id',$request->user()->branch_id));return $this->success('Cancellation requests',$q->paginate(min(100,max(10,$request->integer('per_page',25)))));}
    public function approve(Request $request,OrderCancellationRequest $cancellationRequest):JsonResponse{$data=$request->validate(['resolution_note'=>['nullable','string','max:2000']]);return $this->success('Cancellation approved',$this->service->approve($cancellationRequest,$request->user(),$data['resolution_note']??null));}
    public function reject(Request $request,OrderCancellationRequest $cancellationRequest):JsonResponse{$data=$request->validate(['decision'=>['required','in:OUT_FOR_DELIVERY,FAILED_DELIVERY'],'resolution_note'=>['required','string','max:2000']]);return $this->success('Cancellation rejected',$this->service->reject($cancellationRequest,$request->user(),$data['decision'],$data['resolution_note']));}
}
