<?php
namespace App\Services\Support;
use App\Models\IdempotencyRecord;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
class IdempotencyService
{
    public function lockOrCreate(string $scope,string $key,array $payload,?int $userId,string $resourceType,int $resourceId):IdempotencyRecord
    {
        $hash=$this->requestHash($payload);
        IdempotencyRecord::query()->insertOrIgnore([
            'user_id'=>$userId,'scope'=>$scope,'key'=>$key,'request_hash'=>$hash,
            'status'=>'pending','resource_type'=>$resourceType,'resource_id'=>$resourceId,
            'response_status'=>200,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $record=IdempotencyRecord::where(['scope'=>$scope,'key'=>$key])->lockForUpdate()->firstOrFail();
        if(!hash_equals($record->request_hash,$hash))throw new ConflictHttpException('Idempotency-Key was already used with a different payload.');
        return $record;
    }

    public function markFinancialCommitted(IdempotencyRecord $record,array $response):void
    {
        $record->update(['status'=>'financial_committed','response'=>$response,'response_status'=>200]);
    }

    public function requestHash(array $payload):string
    {
        return hash('sha256',json_encode($this->canonicalize($payload),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    public function execute(string $scope,string $key,array $payload,?int $userId,callable $operation,?string $resourceType=null,?int $resourceId=null):array
    {
        $hash=$this->requestHash($payload);
        return DB::transaction(function()use($scope,$key,$hash,$userId,$operation,$resourceType,$resourceId){
            $record=IdempotencyRecord::where(['scope'=>$scope,'key'=>$key])->lockForUpdate()->first();
            if($record){
                if(!hash_equals($record->request_hash,$hash))throw new ConflictHttpException('Idempotency-Key was already used with a different payload.');
                if($record->status==='pending')throw new ConflictHttpException('The idempotent operation is still pending.');
                return ['data'=>$record->response,'replayed'=>true,'status'=>$record->response_status];
            }
            $record=IdempotencyRecord::create(['user_id'=>$userId,'scope'=>$scope,'key'=>$key,'request_hash'=>$hash,'status'=>'pending','resource_type'=>$resourceType,'resource_id'=>$resourceId,'response_status'=>200]);
            $data=$operation();
            $record->update(['status'=>'completed','response'=>$data,'response_status'=>200]);
            return ['data'=>$data,'replayed'=>false,'status'=>200];
        },3);
    }

    private function canonicalize(array $payload):array
    {
        foreach($payload as &$value){if(is_array($value))$value=$this->canonicalize($value);} unset($value);
        if(!array_is_list($payload))ksort($payload);
        return $payload;
    }
}
