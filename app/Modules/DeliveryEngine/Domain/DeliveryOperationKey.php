<?php

namespace App\Modules\DeliveryEngine\Domain;

use InvalidArgumentException;

final class DeliveryOperationKey
{
    public function derive(
        MessageExecutionSnapshot $message,
        RecipientExecutionSnapshot $recipient,
    ): string {
        if ($message->workspaceId !== $recipient->workspaceId) {
            throw new InvalidArgumentException('Delivery snapshots must belong to the same workspace.');
        }

        if ($recipient->messageSnapshotId !== $message->id) {
            throw new InvalidArgumentException('Recipient snapshot does not belong to the message snapshot.');
        }

        if ($message->channel !== $recipient->channel) {
            throw new InvalidArgumentException('Delivery snapshot channels must match.');
        }

        return hash('sha256', implode('|', [
            'delivery-operation:v1',
            $message->workspaceId,
            $message->businessIntentKey,
            $message->channel->value,
            $recipient->normalizedDestination,
        ]));
    }
}
