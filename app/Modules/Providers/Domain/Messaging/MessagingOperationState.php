<?php
namespace App\Modules\Providers\Domain\Messaging;
enum MessagingOperationState: string {
 case Reserved='reserved'; case Submitted='submitted'; case Pending='pending'; case Succeeded='succeeded'; case Failed='failed'; case Ambiguous='ambiguous';
 public function isTerminal(): bool { return in_array($this,[self::Succeeded,self::Failed],true); }
}