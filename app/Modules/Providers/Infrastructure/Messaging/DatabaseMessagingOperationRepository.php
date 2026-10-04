<?php
namespace App\Modules\Providers\Infrastructure\Messaging;
use App\Modules\Providers\Domain\Messaging\Contracts\MessagingOperationRepository;
use App\Modules\Providers\Domain\Messaging\MessagingOperation;
use App\Modules\Providers\Domain\Messaging\MessagingOperationState;
use App\Modules\Providers\Domain\Messaging\MessagingProviderOutcome;
use DateTimeImmutable; use Illuminate\Support\Facades\DB; use Illuminate\Support\Str; use InvalidArgumentException; use RuntimeException;
final class DatabaseMessagingOperationRepository implements MessagingOperationRepository {
 public function reserve(string $workspaceId,string $channel,string $providerKey,string $idempotencyKey,string $requestFingerprint): MessagingOperation {
  return DB::transaction(function()use($workspaceId,$channel,$providerKey,$idempotencyKey,$requestFingerprint){
   $row=DB::table('messaging_operations')->where('workspace_id',$workspaceId)->where('idempotency_key',$idempotencyKey)->lockForUpdate()->first();
   if($row!==null){if((string)$row->request_fingerprint!==$requestFingerprint)throw new InvalidArgumentException('Idempotency key was reused for a different messaging request.');return $this->fromRow($row);}
   $now=new DateTimeImmutable; $id=(string)Str::uuid(); DB::table('messaging_operations')->insert(['id'=>$id,'workspace_id'=>$workspaceId,'channel'=>$channel,'provider_key'=>$providerKey,'idempotency_key'=>$idempotencyKey,'request_fingerprint'=>$requestFingerprint,'operation_state'=>MessagingOperationState::Reserved->value,'provider_operation_id'=>null,'ambiguous_outcome'=>false,'evidence'=>json_encode(['reservation'=>'durable'],JSON_THROW_ON_ERROR),'created_at'=>$now,'updated_at'=>$now]);
   return new MessagingOperation($id,$workspaceId,$channel,$providerKey,$idempotencyKey,$requestFingerprint,MessagingOperationState::Reserved,null,false,['reservation'=>'durable'],$now,$now);
  });
 }
 public function reconcile(MessagingOperation $operation,MessagingProviderOutcome $outcome): MessagingOperation {
  return DB::transaction(function()use($operation,$outcome){$row=DB::table('messaging_operations')->where('id',$operation->id)->lockForUpdate()->first();if($row===null)throw new RuntimeException('Messaging operation no longer exists.');$next=$this->fromRow($row)->reconcile($outcome);DB::table('messaging_operations')->where('id',$next->id)->update(['operation_state'=>$next->state->value,'provider_operation_id'=>$next->providerOperationId,'ambiguous_outcome'=>$next->ambiguousOutcome,'evidence'=>json_encode($next->evidence,JSON_THROW_ON_ERROR),'updated_at'=>$next->updatedAt]);return $next;});
 }
 private function fromRow(object $row): MessagingOperation { return new MessagingOperation((string)$row->id,(string)$row->workspace_id,(string)$row->channel,(string)$row->provider_key,(string)$row->idempotency_key,(string)$row->request_fingerprint,MessagingOperationState::from((string)$row->operation_state),$row->provider_operation_id===null?null:(string)$row->provider_operation_id,(bool)$row->ambiguous_outcome,json_decode((string)$row->evidence,true,512,JSON_THROW_ON_ERROR),new DateTimeImmutable((string)$row->created_at),new DateTimeImmutable((string)$row->updated_at));}
}