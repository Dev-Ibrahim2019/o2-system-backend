<?php
namespace App\Services\Support;
use App\Models\IdempotencyRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class IdempotencyService
{
    public function execute(string $scope,string $key,array $payload,?int $userId,callable $operation):array
    {
        $hash=hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return DB::transaction(function()use($scope,$key,$hash,$userId,$operation){
            $record=IdempotencyRecord::where(['scope'=>$scope,'key'=>$key])->lockForUpdate()->first();
            if($record){if(!hash_equals($record->request_hash,$hash))throw ValidationException::withMessages(['idempotency_key'=>'Idempotency-Key was already used with a different payload.']);return ['data'=>$record->response,'replayed'=>true,'status'=>$record->response_status];}
            $data=$operation(); IdempotencyRecord::create(['user_id'=>$userId,'scope'=>$scope,'key'=>$key,'request_hash'=>$hash,'response'=>$data,'response_status'=>200]);
            return ['data'=>$data,'replayed'=>false,'status'=>200];
        },3);
    }
}
