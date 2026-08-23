<?php

namespace App\Modules\Core\Application\Messaging;

use App\Modules\Core\Domain\Contracts\OutboxRepository;
use App\Modules\Core\Domain\Contracts\OutboxTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class PublishOutboxMessage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const int MAX_ATTEMPTS = 5;

    /** @var list<int> */
    public const array BACKOFF_SECONDS = [5, 30, 120, 300];

    public int $tries = self::MAX_ATTEMPTS;

    public int $maxExceptions = self::MAX_ATTEMPTS;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $messageId)
    {
    }

    public function uniqueId(): string
    {
        return $this->messageId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return self::BACKOFF_SECONDS;
    }

    public function handle(OutboxRepository $outbox, OutboxTransport $transport): void
    {
        $message = $outbox->findPending($this->messageId);

        if ($message === null) {
            return;
        }

        try {
            $transport->publish($message);
            $outbox->markPublished($message->id);
        } catch (Throwable $exception) {
            $outbox->markAttemptFailed(
                $message->id,
                $exception->getMessage(),
                self::MAX_ATTEMPTS,
                self::BACKOFF_SECONDS,
            );

            throw $exception;
        }
    }
}
