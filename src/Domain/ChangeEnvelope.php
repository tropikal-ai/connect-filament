<?php

declare(strict_types=1);

namespace TropikalAI\ConnectFilament\Domain;

/**
 * One thing that happened to one shared record, as TROPIKAL will receive it.
 *
 * `context` is already narrowed by the owner's field selection — the projection
 * happens at the source, before the envelope is built, so an unticked field is
 * absent here rather than filtered out on receipt. Nothing downstream has to be
 * trusted to drop it.
 *
 * `eventId` is stable across delivery attempts. A retried delivery carries the
 * same id, which is what lets the receiving end run the Job once rather than
 * once per attempt.
 *
 * `origin` records where the change came from. A write that TROPIKAL itself
 * made arrives as `tropikal` and is never emitted — see ChangeOrigin.
 */
final readonly class ChangeEnvelope
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const DELETED = 'deleted';

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $actor
     */
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $slug,
        public string $event,
        public string $objectId,
        public array $context,
        public string $origin,
        public array $actor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt,
            'slug' => $this->slug,
            'event' => $this->event,
            'object_id' => $this->objectId,
            'context' => $this->context,
            'origin' => $this->origin,
            'actor' => $this->actor,
        ];
    }
}
